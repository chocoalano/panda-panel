<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Resources\RelationForm;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\RelationTable;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\DatabaseTransaction;
use PandaPanel\Support\FormEndpoints;
use PandaPanel\Support\RelationEndpoints;
use PandaPanel\Support\RelationOperation;

/**
 * Everything a relation manager can be asked to do.
 *
 * The request names a resource, an owner record, a relation, and an operation
 * — nothing else, and nothing executable. Each name is resolved against this
 * panel's registry, then against the resource's own list of managers, so a
 * relation the resource never declared does not exist however the request
 * spells it. Related records are loaded through `RelationManager::query()`,
 * which starts from the owner's relation, so a key belonging to another owner
 * resolves to nothing rather than to somebody else's row.
 *
 * Two authorization questions are asked on every call and neither substitutes
 * for the other: may this user see the owner at all (`Resource::canView`), and
 * may they perform this operation on the relation.
 *
 * The context travels in the query string rather than the body. The body is
 * the form's values, and a field named `resource` must not be able to point
 * the request somewhere else.
 */
final class PanelRelationController
{
    public function __construct(private readonly PanelManager $manager) {}

    /**
     * The schema for one operation, fetched when its dialog opens.
     *
     * JSON rather than Inertia, for the same reason the search endpoint is:
     * re-rendering the page the user is looking at to answer "what does this
     * form look like" would throw away the table state they arrived with.
     */
    public function form(Request $request): JsonResponse
    {
        [$resource, $manager, $owner] = $this->resolveContext($request);

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        $related = $this->resolveRelated($request, $manager, $owner, $operation);

        abort_unless($this->authorizeOperation($manager, $owner, $operation, $related), 403);

        $form = RelationForm::for($manager, $owner, $operation, $related);

        return response()->json([
            'title' => $form->title(),
            'submitLabel' => $form->submitLabel(),
            'form' => $form->toArray($related),
            'submitUrl' => RelationEndpoints::form(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
            'method' => 'post',
            'optionsUrl' => FormEndpoints::forRelation(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
            // Carries the same context as the options URL, so a file field on
            // a relation form is authorized by the relation's own abilities
            // rather than by the owning resource's.
            'uploadUrl' => FormEndpoints::uploadForRelation(
                $resource,
                $manager,
                $owner,
                $operation->value,
                $related,
            ),
        ]);
    }

    /**
     * Runs one operation.
     *
     * Redirects back rather than answering JSON: the relation table lives on a
     * page, and the page has to re-render for the new row to appear. Validation
     * failures come back the same way, so the dialog shows them beside the
     * fields without a second error format.
     */
    public function save(Request $request): RedirectResponse
    {
        [, $manager, $owner] = $this->resolveContext($request);

        $operation = RelationOperation::tryFromRequest($request->query('operation'));

        abort_if($operation === null, 404, __('panda-panel::errors.unknown_relation_operation'));

        $related = $this->resolveRelated($request, $manager, $owner, $operation);

        abort_unless($this->authorizeOperation($manager, $owner, $operation, $related), 403);

        $form = RelationForm::for($manager, $owner, $operation, $related);

        $validated = validator($request->all(), $form->validationRules($related))->validate();

        $this->assertNotAlreadyRelated($manager, $owner, $operation, $form->relatedKey($validated));

        DatabaseTransaction::run(null, static function () use ($form, $validated, $related): void {
            $form->save($validated, $related);
        });

        return back()->with('success', $this->successMessage($operation, $manager));
    }

    /**
     * Runs a row action the manager's table declared.
     */
    public function action(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'record' => ['required'],
            'relation' => ['required', 'string'],
            'action' => ['required', 'string'],
            'related' => ['required'],
        ]);

        [, $manager, $owner] = $this->resolveContext($request, $validated);

        $action = RelationTable::actionFor($manager, $owner, (string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_action'));
        abort_unless($action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));

        $key = $validated['related'];

        abort_unless(is_string($key) || is_int($key), 422, __('panda-panel::errors.invalid_record_key'));

        $related = $manager::resolveRecord($owner, $key);

        abort_if($related === null, 404);
        abort_unless($action->isAuthorizedFor($related), 403);

        $action->execute($related);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * Runs a bulk action the manager's table declared.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'string'],
            'record' => ['required'],
            'relation' => ['required', 'string'],
            'action' => ['required', 'string'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*' => ['required'],
        ]);

        [, $manager, $owner] = $this->resolveContext($request, $validated);

        $action = RelationTable::bulkActionFor($manager, $owner, (string) $validated['action']);

        abort_if($action === null, 404, __('panda-panel::errors.unknown_bulk_action'));
        abort_unless($action->isBulkExecutable() || $action->isExecutable(), 400, __('panda-panel::errors.action_not_executable'));
        abort_unless($action->isAuthorizedFor(null), 403);

        $keys = $this->scalarKeys($validated['records']);

        abort_if($keys === [], 422, __('panda-panel::errors.invalid_record_keys'));

        $records = $manager::query($owner)->whereKey($keys)->get();

        // Keys outside the relation silently disappear from the query, so the
        // count check is what turns that into a visible failure rather than a
        // partial operation.
        abort_if($records->count() !== count($keys), 404, __('panda-panel::errors.records_not_found'));

        $action->executeBulk($records);

        return back()->with('success', $action->getSuccessMessage());
    }

    /**
     * The resource, the manager, and the owner record this request addresses.
     *
     * @param  array<string, mixed>|null  $body  the validated body, for the endpoints that carry context there
     * @return array{0: class-string<PanelResource>, 1: class-string<RelationManager>, 2: Model}
     */
    private function resolveContext(Request $request, ?array $body = null): array
    {
        $panel = $this->currentPanel();

        $resourceSlug = $body['resource'] ?? $request->query('resource');
        $relationKey = $body['relation'] ?? $request->query('relation');
        $recordKey = $body['record'] ?? $request->query('record');

        abort_unless(is_string($resourceSlug), 422, __('panda-panel::errors.invalid_resource'));
        abort_unless(is_string($relationKey), 422, __('panda-panel::errors.invalid_relation'));
        abort_unless(is_string($recordKey) || is_int($recordKey), 422, __('panda-panel::errors.invalid_record_key'));

        $resource = $this->manager->resources($panel)->bySlug($resourceSlug);

        abort_if($resource === null, 404, __('panda-panel::errors.unknown_resource'));

        /** @var class-string<PanelResource> $resource */
        $manager = $resource::relationManager($relationKey);

        abort_if($manager === null, 404, __('panda-panel::errors.unknown_relation'));

        $owner = $resource::query()->find($recordKey);

        abort_if($owner === null, 404);

        // Reaching a record's relations at all requires being able to see the
        // record. The relation ability decides the operation; this decides
        // whether the owner is visible in the first place.
        abort_unless($resource::canView($owner), 403);
        abort_unless($manager::canViewAny($owner), 403);

        return [$resource, $manager, $owner];
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function resolveRelated(
        Request $request,
        string $manager,
        Model $owner,
        RelationOperation $operation,
    ): ?Model {
        if (! $operation->needsRelatedRecord()) {
            return null;
        }

        $key = $request->query('related');

        abort_unless(is_string($key), 422, __('panda-panel::errors.invalid_record_key'));

        $related = $manager::resolveRecord($owner, $key);

        abort_if($related === null, 404);

        return $related;
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function authorizeOperation(
        string $manager,
        Model $owner,
        RelationOperation $operation,
        ?Model $related,
    ): bool {
        return match ($operation) {
            RelationOperation::Create => $manager::canCreate($owner),
            RelationOperation::Edit => $related !== null && $manager::canEdit($owner, $related),
            RelationOperation::Attach => $manager::isManyToMany($owner) && $manager::canAttach($owner),
            RelationOperation::Associate => $manager::isOneToMany($owner) && $manager::canAssociate($owner),
        };
    }

    /**
     * A record already in the relation cannot be joined to it again.
     *
     * Checked here rather than in the form's rules: the rule would have to
     * name every currently related key, which is a list that grows with the
     * relation. One indexed lookup says the same thing.
     *
     * @param  class-string<RelationManager>  $manager
     */
    private function assertNotAlreadyRelated(
        string $manager,
        Model $owner,
        RelationOperation $operation,
        int|string|null $key,
    ): void {
        if ($key === null) {
            return;
        }

        if ($operation !== RelationOperation::Attach && $operation !== RelationOperation::Associate) {
            return;
        }

        abort_if(
            $manager::query($owner)->whereKey($key)->exists(),
            422,
            __('panda-panel::errors.record_already_related'),
        );
    }

    /**
     * @param  class-string<RelationManager>  $manager
     */
    private function successMessage(RelationOperation $operation, string $manager): string
    {
        $title = $manager::title();

        return match ($operation) {
            RelationOperation::Create => $title.' created.',
            RelationOperation::Edit => $title.' updated.',
            RelationOperation::Attach => $title.' attached.',
            RelationOperation::Associate => $title.' associated.',
        };
    }

    /**
     * @return list<int|string>
     */
    private function scalarKeys(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        $keys = [];

        foreach ($records as $key) {
            if (is_string($key) || is_int($key)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function currentPanel(): Panel
    {
        return $this->manager->currentPanel()
            ?? throw PanelRegistrationException::noCurrentPanel();
    }
}
