<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PandaPanel\Actions\Action;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\RelationForm;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\ParentRecord;
use PandaPanel\Support\RelationOperation;
use PandaPanel\Tables\TableSchema;

/**
 * Stores one file for a form field.
 *
 * The request names a resource and a field, never a disk or a directory. Both
 * of those come from the field's own declaration, looked up in the schema
 * that declared it — so a request cannot choose where its file lands, which
 * is the whole point of doing the upload separately from the form submit.
 *
 * Everything the field declares is enforced here *and* again when the form is
 * submitted, because they are two requests and only the second attaches the
 * path to a record.
 *
 * ## Which permission an upload needs
 *
 * The one that would be needed to submit the form the field belongs to, and
 * nothing weaker. There are three kinds of form and they are not
 * interchangeable, so the context in the URL decides which schema is built
 * and which ability is asked:
 *
 * | Context in the URL          | Schema                        | Ability |
 * | --------------------------- | ----------------------------- | ------- |
 * | `page=create`               | the resource's create form     | `create` |
 * | `page=edit` + `record`      | the resource's edit form       | `update` on that record |
 * | `relation` + `operation`    | the relation form              | the relation's own, per operation |
 * | `action` + `scope`          | the action's form              | the action's own |
 *
 * The context is built by the server and travels in the query string; the
 * client adds only the field name and the file. That split is what stops a
 * user who may create in a resource from uploading against an edit form for
 * a record they may not touch, and a `viewAny` reader from uploading at all.
 *
 * Reading the resource is never enough. An upload writes a file to a disk
 * under a directory this application chose, and the ability to look at a list
 * is not the ability to put something on that disk.
 */
final class PanelUploadController
{
    /**
     * Where an action can be declared — the same allowlist the action form
     * endpoint uses, because an upload's context has to name a place an
     * action could actually have come from.
     */
    private const ACTION_SCOPES = ['record', 'table', 'bulk', 'infolist'];

    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): JsonResponse
    {
        $panel = $this->currentPanel();

        $validated = $request->validate([
            'field' => ['required', 'string'],
            'file' => ['required', 'file'],
        ]);

        // Read from the query string, never from the body. The body is the
        // form's values, and a field that happens to be named `resource`
        // must not be able to point the upload at a different one — the same
        // rule the relation endpoints follow.
        $resource = $this->resolveResource($panel, $request->query('resource'));

        $this->bindParentRecord($request, $resource);

        // Authorization and the schema are answered together, by the same
        // branch: a schema built for one context and a permission asked about
        // another is the bug this endpoint exists to not have.
        $schema = $this->authorizeAndResolveSchema($request, $resource);

        $field = $this->resolveField($schema, (string) $validated['field']);

        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, 422, __('panda-panel::errors.no_file_uploaded'));

        // The field's own limits, applied to the real file rather than to
        // what the browser claimed before sending it.
        $rules = ['file' => ['file', 'max:'.$field->getMaxSize()]];

        if ($field->getAcceptedTypes() !== []) {
            $rules['file'][] = 'mimetypes:'.implode(',', $field->getAcceptedTypes());
        }

        validator(['file' => $file], $rules)->validate();

        $path = $file->store($field->getDirectory(), $field->getDisk());

        abort_if($path === false, 500, __('panda-panel::errors.file_not_stored'));

        return response()->json([
            'path' => $path,
            'name' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * The schema this upload belongs to, once the user has been shown to be
     * allowed to submit it.
     *
     * The branches are ordered most specific first. An action's form is not
     * the resource's form even when it is opened from a resource page, and a
     * relation form is not either — matching on `page` first would let either
     * of them be authorized as though it were a plain resource write.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function authorizeAndResolveSchema(Request $request, string $resource): FormSchema
    {
        if ($request->query('action') !== null) {
            return $this->actionSchema($request, $resource);
        }

        if ($request->query('relation') !== null) {
            return $this->relationSchema($request, $resource);
        }

        return $this->resourceSchema($request, $resource);
    }

    /**
     * A file field on the resource's own create or edit form.
     *
     * `page` is an allowlist rather than "edit, or else create": defaulting
     * would mean an unrecognised value silently became the create form, and
     * the create form is the one that needs no record — which is exactly the
     * check being dodged.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function resourceSchema(Request $request, string $resource): FormSchema
    {
        $page = $request->query('page');

        abort_unless(in_array($page, ['create', 'edit'], true), 422, __('panda-panel::errors.invalid_page'));

        if ($page === 'create') {
            abort_unless($resource::canCreate(), 403);

            return $resource::form(
                FormSchema::make()->model($resource::getModel())->forPage('create'),
            );
        }

        // An edit form's permission is about one record, so the record is
        // required rather than optional. Resolved through the resource's own
        // lookup, so a key outside its scope — another tenant's, a soft
        // deleted row the resource hides — is a 404 before any policy runs.
        $record = $this->resolveRecord($resource, $request->query('record'));

        abort_unless($resource::canEdit($record), 403);

        return $resource::form(
            FormSchema::make()->model($resource::getModel())->forPage('edit'),
        );
    }

    /**
     * A file field on a relation manager's form.
     *
     * Two questions, both asked: whether the relation may be opened at all,
     * and whether this particular operation may be performed. Neither
     * substitutes for the other — see `PanelFormOptionsController`, which
     * asks the identical pair for the identical reason.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function relationSchema(Request $request, string $resource): FormSchema
    {
        $manager = $resource::relationManager((string) $request->query('relation'));

        abort_if($manager === null, 404, __('panda-panel::errors.unknown_relation'));

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        $owner = $this->resolveRecord($resource, $request->query('record'));

        abort_unless($resource::canView($owner), 403);
        abort_unless($manager::canViewAny($owner), 403);
        abort_unless($operation->isAuthorized($manager, $owner), 403);

        $related = $operation->needsRelatedRecord()
            ? $manager::resolveRecord($owner, (string) $request->query('related'))
            : null;

        return RelationForm::for($manager, $owner, $operation, $related)->schema();
    }

    /**
     * A file field on an action's form.
     *
     * The action is looked up in the schema that declared it and then asked
     * whether it is authorized for this record, which is the same pair of
     * questions `PanelActionFormController` asks before it will even describe
     * the dialog. An action that has no form has nowhere to upload to, and
     * says so rather than falling through to the resource's form.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function actionSchema(Request $request, string $resource): FormSchema
    {
        $scope = $request->query('scope');

        abort_unless(in_array($scope, self::ACTION_SCOPES, true), 422, __('panda-panel::errors.invalid_scope'));

        $key = $request->query('record');

        $record = $key === null || $key === ''
            ? null
            : $this->resolveActionRecord($resource, $key);

        $action = $this->resolveAction($resource, $scope, (string) $request->query('action'));

        abort_unless($action->isAuthorizedFor($record), 403);

        $schema = $action->resolveSchema($record);

        abort_if($schema === null, 400, __('panda-panel::errors.action_no_form'));

        return $schema;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveAction(string $resource, string $scope, string $name): Action
    {
        $action = match ($scope) {
            'record' => $resource::table(TableSchema::make())->getRecordAction($name),
            'table' => $resource::table(TableSchema::make())->getTableAction($name),
            'bulk' => $resource::table(TableSchema::make())->getBulkAction($name),
            default => $resource::infolist(InfolistSchema::make())->getAction($name),
        };

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));

        return $action;
    }

    /**
     * The field, resolved out of the schema that declared it.
     *
     * A name the schema does not have does not exist, and a field that is not
     * a `FileUpload` is not somewhere a file may be stored — both are refused
     * rather than defaulted.
     */
    private function resolveField(FormSchema $schema, string $name): FileUpload
    {
        $field = $schema->field($name);

        abort_if($field === null, 404, __('panda-panel::errors.unknown_field'));
        abort_unless($field instanceof FileUpload, 400, __('panda-panel::errors.field_rejects_files'));

        return $field;
    }

    /**
     * The record the form is about, through the resource's list query.
     *
     * `query()` rather than `findRecord()`: an upload is part of filling in a
     * form for a record the user is looking at, and a record the resource
     * hides is one they are not.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveRecord(string $resource, mixed $key): Model
    {
        abort_unless(is_string($key) && $key !== '', 422, __('panda-panel::errors.invalid_record_key'));

        $record = $resource::query()->find($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * An action's record, through the resource's record lookup.
     *
     * Deliberately not `resolveRecord()`: an action can legitimately address
     * a record the list hides — a restore being the obvious one — which is
     * the same distinction `PanelActionFormController` draws.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveActionRecord(string $resource, mixed $key): Model
    {
        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        $record = $resource::findRecord($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * Binds the parent a nested resource is scoped to, so `query()` and the
     * policies behind it see the same scope the page did.
     *
     * @param  class-string<PanelResource>  $resource
     */
    private function bindParentRecord(Request $request, string $resource): void
    {
        if (! $resource::isNested()) {
            return;
        }

        $parentResource = $resource::parentResource();
        $key = $request->query('parent');

        abort_if($parentResource === null, 404);
        abort_unless(is_string($key) && $key !== '', 422, __('panda-panel::errors.invalid_parent_key'));

        $parent = ParentRecord::resolve($parentResource, $key);

        abort_if($parent === null, 404);

        ParentRecord::bind($parent);
    }

    /**
     * @return class-string<PanelResource>
     */
    private function resolveResource(Panel $panel, mixed $slug): string
    {
        abort_unless(is_string($slug), 422, __('panda-panel::errors.invalid_resource'));

        $resource = $this->manager->resources($panel)->bySlug($slug);

        abort_if($resource === null, 404, __('panda-panel::errors.unknown_resource'));

        /** @var class-string<PanelResource> $resource */
        return $resource;
    }

    private function currentPanel(): Panel
    {
        return $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
