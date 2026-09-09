<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Forms\Support\CallbackParameters;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Forms\Support\Get;
use PandaPanel\Forms\Support\Set;
use Tests\Fixtures\Panel\Forms\FormFixturePanel;
use Tests\Fixtures\Panel\Forms\FormFixtureResource;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\RelationPanel;

/*
|--------------------------------------------------------------------------
| Reactive forms, on every surface
|--------------------------------------------------------------------------
|
| Three defects that only look like three:
|
| 1. `live()` worked on a resource's create and edit pages and nowhere else,
|    because the state endpoint could only build a resource's schema. On an
|    action's form or a relation's, the field declared itself live, the
|    renderer honoured it, and the request had nowhere to go.
|
| 2. A searchable select knew its own search term and nothing else, so a
|    dependent one — the employees in the chosen department — could only search
|    the whole table and then refuse most of what it found.
|
| 3. A server that rebuilt a child's options had no way to say the child's
|    *value* was now wrong. The renderer preserves what the user typed across a
|    rebuild, so the stale selection came straight back and stayed on screen
|    until the submit refused it.
|
| They share one cause — there was no single answer to "which form is this and
| what does it currently hold" — and one fix, `FormContext` and `FormState`.
|
*/

beforeEach(function (): void {
    FormFixturePanel::boot();
    FormFixturePanel::reset();

    FormFixtureResource::$lastData = [];
    FormFixtureResource::$lastGet = null;
    FormFixtureResource::$legacyArgs = null;

    $this->actingAs(User::factory()->create());
});

function reactiveUrl(string $name, array $query = []): string
{
    return route('panel.'.FormFixturePanel::ID.'.'.$name, $query, absolute: false);
}

/*
 * FormState — the primitive underneath all three
 */

it('reads a value by its flat name and by its path', function (): void {
    $state = new FormState(['profile.bio' => 'Hi', 'meta' => ['tone' => 'warm']]);

    // A relation group names its children `profile.bio` and the renderer keeps
    // them flat, so the dotted name *is* the key. Walking the segments first
    // would answer null for a field that is plainly there.
    expect($state->get('profile.bio'))->toBe('Hi')
        ->and($state->get('meta.tone'))->toBe('warm')
        ->and($state->get('missing'))->toBeNull();
});

it('separates having nothing from holding nothing', function (): void {
    $state = new FormState(['cleared' => null]);

    expect($state->has('cleared'))->toBeTrue()
        ->and($state->has('absent'))->toBeFalse();
});

it('records a mutation as a patch rather than only as a value', function (): void {
    $state = new FormState(['position_id' => 7]);

    $state->set('position_id', null);

    // Both: the patch is what travels back and wins over the client, and the
    // local write is what a second callback in the same request reads.
    expect($state->patches())->toBe(['position_id' => null])
        ->and($state->get('position_id'))->toBeNull();
});

it('produces no patch when nothing was changed', function (): void {
    $state = new FormState(['a' => 1]);

    expect($state->hasPatches())->toBeFalse()
        ->and($state->patches())->toBe([]);
});

/*
 * Callback compatibility — the rule that makes all of this additive
 */

it('calls a positional callback exactly as it always was', function (): void {
    $seen = null;

    CallbackParameters::call(
        function ($value, $previous, $record) use (&$seen): void {
            $seen = [$value, $previous, $record];
        },
        ['new', 'old', null],
        new FormState,
    );

    expect($seen)->toBe(['new', 'old', null]);
});

it('injects the state objects a callback asks for by type', function (): void {
    $state = new FormState(['parent' => 'produksi']);

    CallbackParameters::call(
        function (Set $set, Get $get): void {
            $set('child', $get('parent') === 'produksi' ? 'welder' : null);
        },
        ['ignored'],
        $state,
    );

    expect($state->patches())->toBe(['child' => 'welder']);
});

it('mixes positional arguments with injected ones, in order', function (): void {
    $state = new FormState;
    $seen = null;

    CallbackParameters::call(
        function ($value, Set $set) use (&$seen): void {
            $seen = $value;
            $set('other', $value);
        },
        ['typed'],
        $state,
    );

    expect($seen)->toBe('typed')
        ->and($state->patches())->toBe(['other' => 'typed']);
});

/*
 * Session 4 — a stale dependent value can be cleared
 */

it('answers a rebuild with the patch its callback recorded', function (): void {
    $response = $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'state' => ['parent' => 'gudang', 'child' => 'operator'],
            'changed' => 'parent',
            'previous' => 'produksi',
        ],
    );

    // `operator` belonged to Produksi. Rebuilding the options was never enough
    // on its own — the client keeps what the user typed — so the server says
    // outright that the field is now empty.
    $response->assertOk();

    expect($response->json('statePatch'))->toBe(['child' => null]);
});

it('rebuilds the child against the new parent, not the old one', function (): void {
    $response = $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => ['parent' => 'gudang'], 'changed' => 'parent'],
    );

    $child = collect($response->json('form.schema'))->firstWhere('name', 'child');

    expect(collect($child['options'])->pluck('value')->all())->toBe(['picker']);
});

it('leaves the child alone when the parent did not change', function (): void {
    $response = $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => ['parent' => 'produksi', 'child' => 'welder'], 'changed' => 'kind'],
    );

    $child = collect($response->json('form.schema'))->firstWhere('name', 'child');

    expect($response->json('statePatch'))->toBe([])
        ->and($child['value'])->toBe('welder');
});

it('gives a reactive callback the sibling values through Get', function (): void {
    $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => ['parent' => 'gudang'], 'changed' => 'parent'],
    )->assertOk();

    expect(FormFixtureResource::$lastGet)->toBe('gudang');
});

it('still calls a legacy positional callback with value, previous and record', function (): void {
    $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => ['legacy' => 'x'], 'changed' => 'legacy', 'previous' => 'was'],
    )->assertOk();

    expect(FormFixtureResource::$legacyArgs)->toBe(['x', 'was', null]);
});

it('runs no hook for a field that never asked to be live', function (): void {
    $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => ['name' => 'Apollo'], 'changed' => 'name'],
    )->assertOk();

    expect(FormFixtureResource::$lastGet)->toBeNull();
});

it('cannot be handed a patch by the request', function (): void {
    $response = $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'state' => ['name' => 'Apollo'],
            'changed' => 'name',
            // A patch is not something a request can send. It is produced only
            // by a callback the schema declared, running on the server.
            'statePatch' => ['name' => 'Forged'],
        ],
    );

    expect($response->json('statePatch'))->toBe([]);
});

/*
 * Session 3 — a dependent select searches inside the rest of the form
 */

it('narrows a searchable select by the sibling the form holds', function (): void {
    $produksi = $this->postJson(
        reactiveUrl('options', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['field' => 'child', 'state' => ['parent' => 'produksi']],
    );

    $gudang = $this->postJson(
        reactiveUrl('options', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['field' => 'child', 'state' => ['parent' => 'gudang']],
    );

    expect(collect($produksi->json('options'))->pluck('value')->all())
        ->toBe(['welder', 'operator'])
        ->and(collect($gudang->json('options'))->pluck('value')->all())
        ->toBe(['picker']);
});

it('returns nothing for a dependent select whose parent is empty', function (): void {
    $response = $this->postJson(
        reactiveUrl('options', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['field' => 'child'],
    );

    expect($response->json('options'))->toBe([]);
});

it('still answers a plain GET search for a select that depends on nothing', function (): void {
    $response = $this->getJson(reactiveUrl('options', [
        'resource' => 'form-fixtures',
        'page' => 'create',
        'field' => 'kind',
        'search' => '',
    ]));

    $response->assertOk();

    expect(collect($response->json('options'))->pluck('value')->all())
        ->toBe(['plain', 'special']);
});

it('discards a state key the schema never declared before any callback sees it', function (): void {
    $response = $this->postJson(
        reactiveUrl('options', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['field' => 'child', 'state' => ['parent' => 'produksi', 'injected' => 'nope']],
    );

    // Narrowed the same way a submit is. The callback is handed the schema's
    // own fields and nothing else.
    expect(collect($response->json('options'))->pluck('value')->all())
        ->toBe(['welder', 'operator']);
});

it('tells the client which selects depend on the rest of the form', function (): void {
    $response = $this->get(FormFixturePanel::url('form-fixtures/create'));

    $schema = $response->viewData('page')['props']['form']['schema'];

    $child = collect($schema)->firstWhere('name', 'child');
    $kind = collect($schema)->firstWhere('name', 'kind');

    // The client throws away its cached search results for a dependent field
    // when a sibling changes; a plain select never needs to.
    expect($child['dependentOptions'])->toBeTrue()
        ->and($kind['dependentOptions'])->toBeFalse();
});

/*
 * Session 2 — the same lifecycle on an action's form
 */

it('rebuilds an action form when one of its live fields changes', function (): void {
    $response = $this->postJson(
        reactiveUrl('form-state', [
            'resource' => 'form-fixtures',
            'action' => 'categorise',
            'scope' => 'table',
        ]),
        ['state' => ['group' => 'b', 'member' => 'a1'], 'changed' => 'group'],
    );

    $response->assertOk();

    $member = collect($response->json('form.schema'))->firstWhere('name', 'member');

    expect($response->json('statePatch'))->toBe(['member' => null])
        ->and(collect($member['options'])->pluck('value')->all())->toBe(['b1', 'b2']);
});

it('searches an action form select against the action form state', function (): void {
    $response = $this->postJson(
        reactiveUrl('options', [
            'resource' => 'form-fixtures',
            'action' => 'categorise',
            'scope' => 'table',
        ]),
        ['field' => 'member', 'state' => ['group' => 'b']],
    );

    expect(collect($response->json('options'))->pluck('value')->all())->toBe(['b1', 'b2']);
});

it('sends an action dialog the endpoints its live fields need', function (): void {
    $response = $this->getJson(reactiveUrl('actions.form', [
        'resource' => 'form-fixtures',
        'action' => 'categorise',
        'scope' => 'table',
    ]));

    $response->assertOk();

    expect($response->json('formStateUrl'))->toContain('action=categorise')
        ->and($response->json('optionsUrl'))->toContain('action=categorise');
});

/*
 * Authorization — a refresh is not a side door
 */

it('refuses a state refresh for a form the user may not open', function (): void {
    ProjectPolicy::$creatable = false;

    $this->postJson(
        reactiveUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => [], 'changed' => 'parent'],
    )->assertForbidden();
});

it('refuses dependent options for a form the user may not open', function (): void {
    ProjectPolicy::$creatable = false;

    $this->postJson(
        reactiveUrl('options', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['field' => 'child', 'state' => ['parent' => 'produksi']],
    )->assertForbidden();
});

it('refuses a state refresh for an action the user may not run', function (): void {
    ProjectPolicy::$creatable = false;

    $this->postJson(
        reactiveUrl('form-state', [
            'resource' => 'form-fixtures',
            'action' => 'rename',
            'scope' => 'table',
        ]),
        ['state' => [], 'changed' => 'name'],
    )->assertForbidden();
});

it('writes nothing while rebuilding, whatever it is handed', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $this->postJson(
        reactiveUrl('form-state', [
            'resource' => 'form-fixtures',
            'page' => 'edit',
            'record' => (string) $project->getKey(),
        ]),
        ['state' => ['name' => 'Renamed', 'parent' => 'gudang'], 'changed' => 'parent'],
    )->assertOk();

    // Asking what a form should look like is not a submit, and a callback that
    // patches state is still not a write.
    expect($project->fresh()?->getAttribute('name'))->toBe('Apollo');
});

/*
 * Session 2 — and on a relation action's form
 */

it('rebuilds a relation action form and clears its stale child', function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $project = Project::query()->create(['name' => 'Apollo']);
    $task = $project->tasks()->create(['name' => 'One']);

    $response = $this->postJson(
        RelationPanel::url('form-state').'?'.http_build_query([
            'resource' => 'projects',
            'record' => $project->getKey(),
            'relation' => 'tasks',
            'action' => 'setStatus',
            'scope' => 'record',
            'related' => $task->getKey(),
        ]),
        ['state' => ['kind' => 'closed', 'detail' => 'todo'], 'changed' => 'kind'],
    );

    $response->assertOk();

    $detail = collect($response->json('form.schema'))->firstWhere('name', 'detail');

    expect($response->json('statePatch'))->toBe(['detail' => null])
        ->and(collect($detail['options'])->pluck('value')->all())->toBe(['done', 'cancelled']);
});
