<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationForm;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\RelationOperation;

/**
 * Answers what a searchable select may offer.
 *
 * A relation-backed select renders one bounded page of a table that may have
 * thousands of rows. Without this the other rows are simply unreachable — the
 * field would validate a key it had no way to show. The search runs on the
 * server for the same reason the option list is built there: the query, the
 * related model, and the relation name never reach the browser.
 *
 * The field is resolved out of the schema that declared it, so a request can
 * only search a field that exists on a form the user can already open. It
 * never names a column, a table, or a model — only a field name the schema
 * either has or does not.
 *
 * JSON rather than Inertia, like the search endpoint: re-rendering the page
 * somebody is filling in to answer a keystroke would throw away what they
 * typed.
 */
final class PanelFormOptionsController
{
    /** A bound the request cannot raise. */
    private const MAX_RESULTS = 50;

    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): JsonResponse
    {
        $panel = $this->currentPanel();
        $resource = $this->resolveResource($panel, $request->query('resource'));

        $field = $request->query('field');

        abort_unless(is_string($field), 422, __('panda-panel::errors.invalid_field'));

        $search = $request->query('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : null;

        $relation = $request->query('relation');

        return response()->json([
            'options' => is_string($relation)
                ? $this->relationOptions($request, $resource, $relation, $field, $search)
                : $this->resourceOptions($request, $resource, $field, $search),
        ]);
    }

    /**
     * Options for a field on the resource's own form.
     *
     * @param  class-string<PanelResource>  $resource
     * @return list<array{value: string, label: string}>
     */
    private function resourceOptions(
        Request $request,
        string $resource,
        string $field,
        ?string $search,
    ): array {
        $page = $request->query('page');

        abort_unless(in_array($page, ['create', 'edit'], true), 422, __('panda-panel::errors.invalid_page'));

        $page = (string) $page;

        $record = null;

        if ($page === 'create') {
            abort_unless($resource::canCreate(), 403);
        } else {
            $record = $this->resolveRecord($request, $resource);

            abort_unless($resource::canEdit($record), 403);
        }

        $schema = $resource::form(
            FormSchema::make()
                ->model($resource::getModel())
                ->forPage($page),
        );

        return $this->optionsFor($schema, $field, $resource::getModel(), $search, $record);
    }

    /**
     * Options for a field on a relation form, including the select that names
     * the record an attach or associate is about.
     *
     * @param  class-string<PanelResource>  $resource
     * @return list<array{value: string, label: string}>
     */
    private function relationOptions(
        Request $request,
        string $resource,
        string $relationKey,
        string $field,
        ?string $search,
    ): array {
        $manager = $resource::relationManager($relationKey);

        abort_if($manager === null, 404, __('panda-panel::errors.unknown_relation'));

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        $owner = $this->resolveOwner($request, $resource);

        // Reading a relation's field options requires being able to read the
        // relation at all, and then to perform the operation the field
        // belongs to. Neither substitutes for the other: without the first,
        // a manager the user may not open would still answer what its records
        // are called.
        abort_unless($manager::canViewAny($owner), 403);
        abort_unless($operation->isAuthorized($manager, $owner), 403);

        // The select naming the record to join is not a schema field backed by
        // a column; its options are the relation's own answer to "what is not
        // in here yet".
        if ($field === RelationForm::RELATED_FIELD) {
            return $manager::attachableOptions($owner, $search, self::MAX_RESULTS);
        }

        $related = $operation->needsRelatedRecord()
            ? $manager::resolveRecord($owner, (string) $request->query('related'))
            : null;

        return $this->optionsFor(
            RelationForm::for($manager, $owner, $operation, $related)->schema(),
            $field,
            $manager::getRelatedModel($owner),
            $search,
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<array{value: string, label: string}>
     */
    private function optionsFor(
        FormSchema $schema,
        string $field,
        string $modelClass,
        ?string $search,
        ?Model $record = null,
    ): array {
        $component = $schema->field($field, $record);

        // A field the schema does not declare does not exist, however the
        // request spells it — the same rule that governs sorting and
        // filtering.
        abort_if($component === null, 404, __('panda-panel::errors.unknown_field'));
        abort_unless($component instanceof Select, 400, __('panda-panel::errors.field_has_no_options'));

        return $component->resolveOptions($modelClass, $search);
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveRecord(Request $request, string $resource): Model
    {
        $key = $request->query('record');

        abort_unless(is_string($key), 422, __('panda-panel::errors.invalid_record_key'));

        $record = $resource::findRecord($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveOwner(Request $request, string $resource): Model
    {
        $key = $request->query('record');

        abort_unless(is_string($key), 422, __('panda-panel::errors.invalid_record_key'));

        $owner = $resource::query()->find($key);

        abort_if($owner === null, 404);
        abort_unless($resource::canView($owner), 403);

        return $owner;
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
