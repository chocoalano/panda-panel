<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use Tests\Fixtures\Panel\ScopedUserResource;

/**
 * Reaching a record the resource query excludes.
 *
 * A resource that narrows `query()` — to a tenant, a team, a workspace, a
 * "not archived" — is relying on every route to that record going through the
 * same narrowing. One that did not would be the whole point of the scope,
 * undone: the list shows what you own, and a guessed id shows what you do not.
 *
 * This registers a resource scoped to non-administrators and then tries to
 * reach an administrator through every endpoint that takes a record key. Every
 * one of them must answer 404, because outside the scope the record does not
 * exist as far as this resource is concerned.
 */
beforeEach(function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('scope-host')) {
        $panel = $manager->register(
            Panel::make('scope-host')
                ->path('scope-host')
                ->settings(false)
                ->resources([ScopedUserResource::class]),
        );

        app(PanelRouteRegistrar::class)->register($panel);
    }

    $this->admin = User::factory()->admin()->create(['name' => 'Out Of Scope']);
    $this->member = User::factory()->create(['name' => 'In Scope']);

    $this->actingAs($this->admin);
});

it('lists only what the scope admits', function (): void {
    $page = $this->get('/scope-host/scoped-users')->viewData('page');

    $names = collect($page['props']['rows'] ?? [])->pluck('cells.name.value')->all();

    expect($names)->toContain('In Scope')
        ->and($names)->not->toContain('Out Of Scope');
});

it('does not open the view page for a record outside the scope', function (): void {
    $this->get("/scope-host/scoped-users/{$this->admin->id}")->assertNotFound();
});

it('does not open the edit page for a record outside the scope', function (): void {
    $this->get("/scope-host/scoped-users/{$this->admin->id}/edit")->assertNotFound();
});

it('does not write a record outside the scope through the edit endpoint', function (): void {
    $this->put("/scope-host/scoped-users/{$this->admin->id}/edit", [
        'name' => 'Renamed from outside',
        'email' => $this->admin->email,
    ])->assertNotFound();

    expect($this->admin->fresh()->name)->toBe('Out Of Scope');
});

it('does not run a record action against a record outside the scope', function (): void {
    $this->post('/scope-host/actions/record', [
        'resource' => 'scoped-users',
        'action' => 'delete',
        'record' => $this->admin->id,
    ])->assertNotFound();

    expect(User::find($this->admin->id))->not->toBeNull();
});

it('does not reach a record outside the scope through a bulk action', function (): void {
    $this->post('/scope-host/actions/bulk', [
        'resource' => 'scoped-users',
        'action' => 'delete',
        'records' => [$this->admin->id, $this->member->id],
    ]);

    // The out-of-scope key resolves to nothing, so the selection is not the
    // one that was asked for and nothing is written.
    expect(User::find($this->admin->id))->not->toBeNull();
});

it('does not write a record outside the scope through an editable cell', function (): void {
    $this->post('/scope-host/actions/cell', [
        'resource' => 'scoped-users',
        'column' => 'name',
        'record' => $this->admin->id,
        'value' => 'Renamed from outside',
    ])->assertNotFound();

    expect($this->admin->fresh()->name)->toBe('Out Of Scope');
});

it('does not return a record outside the scope from global search', function (): void {
    // The palette is a second way to reach a record by name, so it has to obey
    // the same narrowing the list does.
    $inScope = $this->getJson('/scope-host/search?q=Scope')->json();

    expect(json_encode($inScope))->toContain('In Scope')
        ->and(json_encode($inScope))->not->toContain('Out Of Scope');
});

it('reaches a record inside the scope, so the refusals above are the scope and not a broken route', function (): void {
    $this->get("/scope-host/scoped-users/{$this->member->id}")->assertOk();
});
