<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource as PanelResource;

/**
 * Rebuilds a form after a `live()` field changed.
 *
 * For a dependency the declarative conditions cannot express — a select whose
 * options come from another field's value, a total computed from three of
 * them. The browser sends what has been typed; the server rebuilds the schema
 * against it and answers with the new one.
 *
 * This is not a submit. Nothing is validated and nothing is written: it
 * answers what the form should *look* like now. That separation is what makes
 * it safe to call on every keystroke of a live field — the worst a crafted
 * request can do is ask what a form looks like, which it could see anyway by
 * opening the page.
 *
 * JSON rather than Inertia, for the reason the other form endpoints are: a
 * full page response would discard what the user is in the middle of typing.
 */
final class PanelFormStateController
{
    public function __construct(private readonly PanelManager $manager) {}

    public function __invoke(Request $request): JsonResponse
    {
        $panel = $this->currentPanel();

        $resourceSlug = $request->query('resource');
        $page = $request->query('page') === 'edit' ? 'edit' : 'create';

        abort_unless(is_string($resourceSlug), 422, __('panda-panel::errors.invalid_resource'));

        $resource = $this->resolveResource($panel, $resourceSlug);
        $record = $this->resolveRecord($request, $resource, $page);

        // Seeing a form requires being allowed to open the page it belongs
        // to. Asked before the schema is built, because building it runs the
        // schema's own closures.
        abort_unless(
            $record === null ? $resource::canCreate() : $resource::canEdit($record),
            403,
        );

        $schema = $resource::form(
            FormSchema::make()->model($resource::getModel())->forPage($page),
        );

        $state = $this->state($request, $schema);

        $this->runUpdateHook($request, $schema, $state, $record);

        return response()->json([
            'form' => $schema->toArrayWithState($record, $state),
        ]);
    }

    /**
     * The submitted values, narrowed to the fields the schema declares.
     *
     * A key that is not a field is discarded here, so nothing downstream has
     * to wonder whether the state it was given is the schema's.
     *
     * @return array<string, mixed>
     */
    private function state(Request $request, FormSchema $schema): array
    {
        $submitted = $request->input('state');
        $submitted = is_array($submitted) ? $submitted : [];

        $state = [];

        foreach ($schema->fields() as $field) {
            $name = $field->getName();

            if (array_key_exists($name, $submitted)) {
                $state[$name] = $submitted[$name];
            }
        }

        return $state;
    }

    /**
     * Runs `afterStateUpdated` for the field that changed, and only if it is
     * one that asked to be live.
     *
     * @param  array<string, mixed>  $state
     */
    private function runUpdateHook(
        Request $request,
        FormSchema $schema,
        array $state,
        ?Model $record,
    ): void {
        $changed = $request->input('changed');

        if (! is_string($changed)) {
            return;
        }

        $field = $schema->field($changed);

        // A field that did not declare itself live has no hook to run here,
        // whatever the request says changed.
        if ($field === null || ! $field->isLive()) {
            return;
        }

        $field->handleStateUpdated(
            $state[$changed] ?? null,
            $request->input('previous'),
            $record,
        );
    }

    /**
     * @param  class-string<PanelResource>  $resource
     */
    private function resolveRecord(Request $request, string $resource, string $page): ?Model
    {
        if ($page !== 'edit') {
            return null;
        }

        $key = $request->query('record');

        abort_unless(is_string($key), 422, __('panda-panel::errors.invalid_record_key'));

        $record = $resource::findRecord($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @return class-string<PanelResource>
     */
    private function resolveResource(Panel $panel, string $slug): string
    {
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
