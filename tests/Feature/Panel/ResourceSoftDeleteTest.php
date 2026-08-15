<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use PandaPanel\Tables\Filters\TrashedFilter;
use Tests\Fixtures\Panel\Relations\NestedTaskResource;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;
use Tests\Fixtures\Panel\Relations\TaskSoftDeleteResource;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->live = Task::query()->create(['name' => 'Live']);
    $this->trashed = Task::query()->create(['name' => 'Trashed']);
    $this->trashed->delete();
});

function trashableUrl(string $path = ''): string
{
    return RelationPanel::url('trashable-tasks'.($path === '' ? '' : '/'.ltrim($path, '/')));
}

function actionOn(string $action, int|string $record, array $extra = []): TestResponse
{
    return test()->post(RelationPanel::url('actions/record'), [
        'resource' => 'trashable-tasks',
        'action' => $action,
        'record' => $record,
        ...$extra,
    ]);
}

/*
 * Route binding
 */

it('reaches a trashed record on a record page', function (): void {
    // The whole point: the only route to a deleted record is the one the
    // default scope hides, so without this it could never be restored.
    $this->get(trashableUrl((string) $this->trashed->getKey()))->assertOk();
});

it('still 404s a record the resource scope excludes', function (): void {
    $this->get(trashableUrl('99999'))->assertNotFound();
});

it('does not reach trashed records for a resource that does not declare it', function (): void {
    $orphan = Task::query()->create(['name' => 'Nested']);
    $orphan->delete();

    // `NestedTaskResource` shares the model but not the declaration, so its
    // pages see exactly what they saw before.
    expect(TaskSoftDeleteResource::findRecord($orphan->getKey()))->not->toBeNull()
        ->and(NestedTaskResource::usesSoftDeletes())->toBeFalse();
});

/*
 * The index still hides trashed records until asked
 */

it('hides trashed records from the index by default', function (): void {
    $rows = $this->get(trashableUrl())->viewData('page')['props']['rows'];

    expect(collect($rows)->pluck('cells.name')->all())->toBe(['Live']);
});

it('reveals them when the trashed filter asks', function (): void {
    $rows = fn (string $value): array => collect(
        test()->get(trashableUrl().'?'.http_build_query(['filters' => ['trashed' => $value]]))
            ->viewData('page')['props']['rows'],
    )->pluck('cells.name')->sort()->values()->all();

    expect($rows(TrashedFilter::ONLY))->toBe(['Trashed'])
        ->and($rows(TrashedFilter::WITH))->toBe(['Live', 'Trashed'])
        ->and($rows(TrashedFilter::WITHOUT))->toBe(['Live']);
});

it('ignores a trashed value the filter does not define', function (): void {
    $rows = $this->get(trashableUrl().'?'.http_build_query([
        'filters' => ['trashed' => 'everything'],
    ]))->viewData('page')['props']['rows'];

    // An unrecognised value is a no-op, never a widened query.
    expect(collect($rows)->pluck('cells.name')->all())->toBe(['Live']);
});

/*
 * Restore and force delete
 */

it('restores a trashed record', function (): void {
    actionOn('restore', $this->trashed->getKey())->assertRedirect();

    expect($this->trashed->fresh()->trashed())->toBeFalse();
});

it('force deletes a trashed record for good', function (): void {
    actionOn('forceDelete', $this->trashed->getKey())->assertRedirect();

    expect(Task::withTrashed()->find($this->trashed->getKey()))->toBeNull();
});

it('offers restore only on a record that is trashed', function (): void {
    $rows = collect(
        $this->get(trashableUrl().'?'.http_build_query([
            'filters' => ['trashed' => TrashedFilter::WITH],
        ]))->viewData('page')['props']['rows'],
    )->keyBy('key');

    $names = fn (int|string $key): array => collect($rows[$key]['actions'])
        ->pluck('name')
        ->all();

    expect($names($this->trashed->getKey()))
        ->toContain('restore')
        ->toContain('forceDelete')
        ->and($names($this->live->getKey()))
        ->not->toContain('restore')
        ->not->toContain('forceDelete')
        ->toContain('delete');
});

it('refuses a restore the policy does not allow', function (): void {
    TaskPolicy::$restorable = false;

    actionOn('restore', $this->trashed->getKey())->assertForbidden();

    expect($this->trashed->fresh()->trashed())->toBeTrue();
});

it('refuses a force delete the policy does not allow', function (): void {
    TaskPolicy::$deletable = false;

    actionOn('forceDelete', $this->trashed->getKey())->assertForbidden();

    expect(Task::withTrashed()->find($this->trashed->getKey()))->not->toBeNull();
});

/*
 * Bulk
 */

function bulkOn(string $action, array $records): TestResponse
{
    return test()->post(RelationPanel::url('actions/bulk'), [
        'resource' => 'trashable-tasks',
        'action' => $action,
        'records' => $records,
    ]);
}

it('restores every selected record', function (): void {
    $second = Task::query()->create(['name' => 'Also trashed']);
    $second->delete();

    bulkOn('restore', [$this->trashed->getKey(), $second->getKey()])->assertRedirect();

    expect($this->trashed->fresh()->trashed())->toBeFalse()
        ->and($second->fresh()->trashed())->toBeFalse();
});

it('restores nothing when one of a selection is refused', function (): void {
    $second = Task::query()->create(['name' => 'Also trashed']);
    $second->delete();

    TaskPolicy::$restorable = false;

    bulkOn('restore', [$this->trashed->getKey(), $second->getKey()])->assertForbidden();

    expect($this->trashed->fresh()->trashed())->toBeTrue()
        ->and($second->fresh()->trashed())->toBeTrue();
});

it('force deletes every selected record', function (): void {
    $second = Task::query()->create(['name' => 'Also trashed']);
    $second->delete();

    bulkOn('forceDelete', [$this->trashed->getKey(), $second->getKey()])->assertRedirect();

    expect(Task::withTrashed()->whereIn('id', [
        $this->trashed->getKey(),
        $second->getKey(),
    ])->count())->toBe(0);
});

it('destroys nothing when one of a selection is refused', function (): void {
    $second = Task::query()->create(['name' => 'Also trashed']);
    $second->delete();

    TaskPolicy::$deletable = false;

    bulkOn('forceDelete', [$this->trashed->getKey(), $second->getKey()])->assertForbidden();

    expect(Task::withTrashed()->whereIn('id', [
        $this->trashed->getKey(),
        $second->getKey(),
    ])->count())->toBe(2);
});

it('finds trashed records for a bulk operation', function (): void {
    // Without a trashed-aware lookup the count check below would 404 the
    // whole operation, which is how restore-bulk would have failed silently.
    expect(TaskSoftDeleteResource::findRecords([$this->trashed->getKey()])->count())->toBe(1);
});

/*
 * Policies end to end
 */

it('asks the collective ability before it has a record to ask about', function (): void {
    expect(TaskSoftDeleteResource::canRestoreAny())->toBeTrue()
        ->and(TaskSoftDeleteResource::canForceDeleteAny())->toBeTrue();

    TaskPolicy::$restorable = false;
    TaskPolicy::$deletable = false;

    expect(TaskSoftDeleteResource::canRestoreAny())->toBeFalse()
        ->and(TaskSoftDeleteResource::canForceDeleteAny())->toBeFalse();
});

it('hides a bulk action the collective ability refuses', function (): void {
    TaskPolicy::$restorable = false;

    $bulk = $this->get(trashableUrl())->viewData('page')['props']['table']['bulkActions'];

    expect(collect($bulk)->pluck('name')->all())
        ->not->toContain('restore')
        ->toContain('delete');
});
