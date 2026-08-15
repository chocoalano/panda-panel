<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Actions\ReplicateAction;
use PandaPanel\Actions\Support\Modal;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\ProjectRelationResource;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();
});

/*
 * Modal configuration
 */

it('carries no modal for an action that never opens one', function (): void {
    expect(Action::make('approve')->toArray())
        ->toHaveKey('modal')
        ->and(Action::make('approve')->toArray()['modal'])->toBeNull();
});

it('describes how the dialog behaves once one is configured', function (): void {
    $definition = Action::make('approve')
        ->modalWidth(ModalWidth::FourExtraLarge)
        ->slideOver()
        ->modalHeading('Approve this order')
        ->modalSubmitLabel('Approve it')
        ->modal(static function (Modal $modal): void {
            $modal->stickyHeader()
                ->stickyFooter()
                ->closeByClickingAway(false)
                ->closeByEscaping(false)
                ->autofocus(false)
                ->withoutCancel();
        })
        ->toArray()['modal'];

    expect($definition)->toBe([
        'width' => '4xl',
        'slideOver' => true,
        'stickyHeader' => true,
        'stickyFooter' => true,
        'closeByClickingAway' => false,
        'closeByEscaping' => false,
        'autofocus' => false,
        'heading' => 'Approve this order',
        'description' => null,
        'submitLabel' => 'Approve it',
        'cancelLabel' => null,
        'cancel' => false,
        'componentName' => null,
        'config' => [],
    ]);
});

it('sends custom modal content as a registry key, never as markup', function (): void {
    $definition = Action::make('approve')
        ->modalContent('Panels/Admin/Modals/Explanation', ['tone' => 'warning'])
        ->toArray()['modal'];

    expect($definition['componentName'])->toBe('Panels/Admin/Modals/Explanation')
        ->and($definition['config'])->toBe(['tone' => 'warning']);
});

/*
 * Forms on actions
 */

it('says an action has a form without shipping the form', function (): void {
    $action = Action::make('note')
        ->schema(static fn (): FormSchema => FormSchema::make()->schema([
            TextInput::make('note'),
        ]));

    $definition = $action->toArray();

    // A table of twenty records would otherwise carry twenty copies of a
    // form to open at most one of them.
    expect($definition['hasForm'])->toBeTrue()
        ->and($definition['type'])->toBe('form')
        ->and($definition)->not->toHaveKey('form');
});

it('builds the form against the record it was opened for', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $action = Action::make('rename')->schema(
        static fn (?Model $record): FormSchema => FormSchema::make()->schema([
            TextInput::make('name')->default($record?->getAttribute('name')),
        ]),
    );

    $schema = $action->resolveSchema($project);

    expect($schema?->toArray($project)['schema'][0]['value'])->toBe('Apollo');
});

it('hands the handler what the form submitted', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $seen = null;

    Action::make('rename')
        ->action(static function (Model $record, array $data) use (&$seen): void {
            $seen = $data;
        })
        ->execute($project, ['name' => 'Gemini']);

    expect($seen)->toBe(['name' => 'Gemini']);
});

it('leaves a one-argument handler untouched', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    // Declaring a form is additive: every action written before this existed
    // still runs exactly as it did.
    Action::make('touch')
        ->action(static fn (Model $record) => $record->touch())
        ->execute($project, ['ignored' => true]);

    expect($project->fresh())->not->toBeNull();
});

/*
 * Registered modal actions
 */

it('reaches a registered action only through the one that declared it', function (): void {
    $nested = Action::make('explain');
    $parent = Action::make('approve')->registerModalActions([$nested]);

    expect($parent->getModalAction('explain'))->toBe($nested)
        ->and($parent->getModalAction('missing'))->toBeNull()
        ->and(Action::make('other')->getModalAction('explain'))->toBeNull();
});

it('serializes a registered action against the same record', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $parent = Action::make('approve')->registerModalActions([
        Action::make('visible'),
        Action::make('refused')->authorize(static fn (): bool => false),
    ]);

    $names = array_column($parent->toArray($project)['modalActions'], 'name');

    // An action the user may not run is absent rather than a button that
    // answers 403.
    expect($names)->toBe(['visible']);
});

/*
 * Built-in actions
 */

it('offers creating as a page or as a dialog', function (): void {
    $link = CreateAction::make(ProjectRelationResource::class);
    $modal = CreateAction::modal(ProjectRelationResource::class);

    expect($link->type()->value)->toBe('link')
        ->and($modal->type()->value)->toBe('form')
        ->and($modal->isTableExecutable())->toBeTrue();
});

it('replicates a record without the columns that must not be duplicated', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $action = ReplicateAction::make(
        ProjectRelationResource::class,
        except: ['name'],
        using: static function (Model $copy): void {
            $copy->forceFill(['name' => 'Apollo (copy)']);
        },
    );

    $action->execute($project);

    expect(Project::query()->count())->toBe(2)
        ->and(Project::query()->latest('id')->value('name'))->toBe('Apollo (copy)');
});

it('refuses to replicate for somebody who may not create', function (): void {
    $this->actingAs(User::factory()->create());

    ProjectPolicy::$creatable = false;

    $project = Project::query()->create(['name' => 'Apollo']);

    $action = ReplicateAction::make(ProjectRelationResource::class);

    // A copy is a new record, and being allowed to see one is not being
    // allowed to make another.
    expect($action->isAuthorizedFor($project))->toBeFalse();

    ProjectPolicy::reset();
});
