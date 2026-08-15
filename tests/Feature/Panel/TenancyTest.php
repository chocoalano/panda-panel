<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Tenancy\Tenancy;
use Tests\Fixtures\Panel\Tenancy\BrokenTenantResource;
use Tests\Fixtures\Panel\Tenancy\Document;
use Tests\Fixtures\Panel\Tenancy\DocumentResource;
use Tests\Fixtures\Panel\Tenancy\TenancyPanel;
use Tests\Fixtures\Panel\Tenancy\TenantUser;
use Tests\Fixtures\Panel\Tenancy\UnnamedTenant;
use Tests\Fixtures\Panel\Tenancy\Workspace;
use Tests\Fixtures\Panel\Tenancy\WorkspaceResource;

/*
|--------------------------------------------------------------------------
| Tenancy as public API
|--------------------------------------------------------------------------
|
| The framework's part of multi-tenancy, and only that part: which tenant this
| request is for, whether this user may be in it, and what a scoped resource
| may see. It does not create databases, switch connections or read subdomains
| — `stancl/tenancy` does all three, and `docs/panel-tenancy.md` is the guide
| for putting the two together.
|
| The fixtures are single-database on purpose. With a connection per tenant
| the boundary is the connection and there is nothing here to get wrong; with
| one database a missing `where` is a data leak, which is the failure worth a
| test.
|
*/

beforeEach(function (): void {
    TenancyPanel::boot();
    TenancyPanel::reset();

    $this->acme = Workspace::query()->create(['name' => 'Acme']);
    $this->beta = Workspace::query()->create(['name' => 'Beta']);

    Document::query()->create(['workspace_id' => $this->acme->getKey(), 'title' => 'Acme plan']);
    Document::query()->create(['workspace_id' => $this->acme->getKey(), 'title' => 'Acme notes']);
    Document::query()->create(['workspace_id' => $this->beta->getKey(), 'title' => 'Beta secrets']);

    // Verified, because the example user model's `canAccessPanel()` reads
    // exactly that — and a 403 from the panel's own door would look like a
    // 403 from tenancy.
    $this->user = TenantUser::query()->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => bcrypt('secret-secret'),
    ]);

    // `forceFill`, because the example user model deliberately keeps
    // `email_verified_at` out of `$fillable`.
    $this->user->forceFill(['email_verified_at' => now()])->save();

    $this->user->workspaces()->attach($this->acme->getKey());

    $this->actingAs($this->user);
});

/*
 * Resolving
 */

it('binds the tenant the request named and scopes to it', function (): void {
    $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
        ->assertOk();

    // Every read goes through `query()`, so proving it there proves the list,
    // the record lookup, the actions, and global search at once.
    $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey());
});

it('shows one tenant\'s records and not the other\'s', function (): void {
    $this->user->workspaces()->attach($this->beta->getKey());

    $acme = Tenancy::for($this->acme, fn (): array => DocumentResource::query()
        ->pluck('title')
        ->all());

    $beta = Tenancy::for($this->beta, fn (): array => DocumentResource::query()
        ->pluck('title')
        ->all());

    expect($acme)->toBe(['Acme plan', 'Acme notes'])
        ->and($beta)->toBe(['Beta secrets']);
});

it('refuses a tenant this user does not belong to', function (): void {
    // Ada is in Acme only. Naming Beta is not a 404 — it exists — it is a
    // 403, because hiding which tenants exist from somebody who already named
    // one buys nothing and costs a comprehensible error.
    $this->get(TenancyPanel::url('documents').'?workspace='.$this->beta->getKey())
        ->assertForbidden();
});

it('answers 404 for a tenant that is not there', function (): void {
    $this->get(TenancyPanel::url('documents').'?workspace=999999')
        ->assertNotFound();
});

it('refuses a user model that does not know about tenants at all', function (): void {
    // `HasPanelTenants` is what makes a tenant-scoped panel answerable. A
    // user model without it belongs to nothing, which is a refusal rather
    // than a panel that falls open.
    $this->actingAs(User::factory()->create());

    $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
        ->assertForbidden();
});

/*
 * Scoping
 */

it('leaves a resource that names no tenant relationship unscoped', function (): void {
    // Not an oversight: a table every tenant reads the same way, and a
    // database-per-tenant arrangement where the connection is the boundary,
    // both have nothing to scope by.
    $names = Tenancy::for($this->acme, fn (): array => WorkspaceResource::query()
        ->pluck('name')
        ->all());

    expect($names)->toBe(['Acme', 'Beta']);
});

it('raises rather than running unscoped when no tenant is bound', function (): void {
    // The failure this whole mechanism exists to prevent. A scoped resource
    // that ran without a tenant would return every tenant's records and look
    // like a working page.
    TenancyPanel::boot();

    expect(fn () => DocumentResource::query()->get())
        ->toThrow(PanelRegistrationException::class);
});

it('raises when the relationship named does not exist', function (): void {
    expect(fn () => Tenancy::for($this->acme, fn () => BrokenTenantResource::query()->get()))
        ->toThrow(PanelRegistrationException::class);
});

it('scopes nothing in a panel that declared no tenancy', function (): void {
    // The same resource class, asked outside a tenant-scoped panel. Tenancy
    // is a property of the panel, so a resource shared between a tenant panel
    // and an admin one is scoped in the first and whole in the second.
    app(PanelManager::class)->setCurrentPanel(null);

    expect(DocumentResource::query()->count())->toBe(3);
});

/*
 * Entering a tenant outside a request
 */

it('restores the previous tenant even when the callback throws', function (): void {
    Tenancy::bind($this->acme);

    try {
        Tenancy::for($this->beta, function (): void {
            throw new RuntimeException('nope');
        });
    } catch (RuntimeException) {
        // Expected. What matters is what is bound afterwards.
    }

    // A callback that threw must not leave the rest of the request scoped to
    // somebody else's tenant.
    expect(Tenancy::current()?->getKey())->toBe($this->acme->getKey());
});

it('leaves nothing bound when it started with nothing', function (): void {
    Tenancy::for($this->acme, fn () => null);

    expect(Tenancy::current())->toBeNull();
});

/*
 * Describing a tenant
 */

it('asks the contract for the key and the name', function (): void {
    expect(Tenancy::describe($this->acme))
        ->toBe(['key' => (int) $this->acme->getKey(), 'name' => 'Acme']);
});

it('falls back to the key and a name column without the contract', function (): void {
    $tenant = UnnamedTenant::query()->find($this->acme->getKey());

    expect(Tenancy::describe($tenant))
        ->toBe(['key' => $this->acme->getKey(), 'name' => 'Acme']);
});

/*
 * What the frontend receives
 */

it('shares the current tenant and the ones this user could switch to', function (): void {
    $this->user->workspaces()->attach($this->beta->getKey());

    $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.name', 'Acme')
            ->where('tenancy.current.key', (int) $this->acme->getKey())
            ->where('tenancy.available.0.name', 'Acme')
            ->where('tenancy.available.1.name', 'Beta'));
});

it('shares nothing at all for a panel with no tenancy', function (): void {
    // Null rather than an empty shape, so the frontend's check is
    // `tenancy === null` and a switcher never renders where there is nothing
    // to switch between.
    $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin');

    expect($response->viewData('page')['props']['tenancy'])->toBeNull();
});

/*
 * The switcher
 *
 * Tenancy is only first-class if a user can act on it. The server knowing
 * which tenants exist is half the feature; being able to move between them is
 * the other half.
 */

it('gives every offered tenant a url and marks the current one', function (): void {
    $this->user->workspaces()->attach($this->beta->getKey());

    $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.available.0.current', true)
            ->where('tenancy.available.1.current', false)
            // Built by the panel, because only the resolver's author knows how
            // a tenant is addressed.
            ->where(
                'tenancy.available.1.url',
                '/'.TenancyPanel::PATH.'/documents?workspace='.$this->beta->getKey(),
            ));
});

it('offers no tenant this user does not belong to', function (): void {
    // Ada is in Acme only. The switcher is built from the same list the
    // per-request check reads, so it never offers a 403.
    $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.available', 1)
            ->where('tenancy.available.0.name', 'Acme'));
});

it('leaves the url null when the panel never said how to build one', function (): void {
    $panel = app(PanelManager::class)->get(TenancyPanel::ID);

    // A panel with tenancy but no URL builder. The frontend hides the
    // switcher rather than rendering entries that go nowhere.
    expect(Panel::make('urlless')->tenant(
        Workspace::class,
        static fn (): ?Workspace => null,
    )->getTenantUrl($this->acme))->toBeNull()
        ->and($panel->getTenantUrl($this->acme))->not->toBeNull();
});
