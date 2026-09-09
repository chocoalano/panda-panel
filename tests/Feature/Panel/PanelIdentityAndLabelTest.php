<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;
use Tests\Fixtures\Panel\LabelledCreateUser;
use Tests\Fixtures\Panel\LabelledEditUser;
use Tests\Fixtures\Panel\Tenancy\Document;
use Tests\Fixtures\Panel\Tenancy\TenancyPanel;
use Tests\Fixtures\Panel\Tenancy\TenantUser;
use Tests\Fixtures\Panel\Tenancy\Workspace;

/*
|--------------------------------------------------------------------------
| Who the panel says it is, and what its forms say they do
|--------------------------------------------------------------------------
|
| Two unrelated-looking gaps with the same shape: something the application
| plainly knows, which the shell had no way to say.
|
| A panel is configured in a service provider's `boot()`, long before any
| middleware has resolved a tenant, so `brandName()` could only ever take a
| string — and a string computed there would be whichever tenant a worker
| happened to serve first, and then that tenant for every request after it
| under Octane. Meanwhile the *only* thing that named the current tenant was
| the switcher, which hides itself when there is nothing to switch to. A user
| who belonged to one company saw that company nowhere.
|
| And a create page's submit button said "Create <resource>" with no way to
| say anything else short of building a Wizard.
|
*/

/*
 * Session 5 — a brand resolved per request
 */

it('keeps a static brand name working exactly as it did', function (): void {
    $panel = Panel::make('static-brand')->brandName('HRMS');

    expect($panel->getBrandName())->toBe('HRMS');
});

it('falls back to the application name when the panel says nothing', function (): void {
    config()->set('app.name', 'Fallback');

    expect(Panel::make('unbranded')->getBrandName())->toBe('Fallback');
});

it('evaluates a closure brand every time it is read, not once at boot', function (): void {
    $calls = 0;

    $panel = Panel::make('dynamic-brand')->brandName(function () use (&$calls): string {
        $calls++;

        return 'Tenant '.$calls;
    });

    // Nothing is memoized. A value cached on the panel would outlive the
    // request under Octane and show one tenant's name to the next.
    expect($panel->getBrandName())->toBe('Tenant 1')
        ->and($panel->getBrandName())->toBe('Tenant 2')
        ->and($calls)->toBe(2);
});

it('resolves nothing about a tenant while the panel is being configured', function (): void {
    $resolved = false;

    // Building the panel must not run the closure: at this point in a service
    // provider's boot there is no request, no user and no tenant.
    Panel::make('lazy-brand')->brandName(function () use (&$resolved): string {
        $resolved = true;

        return 'Too early';
    });

    expect($resolved)->toBeFalse();
});

/*
 * Tenant identity, separate from the switcher
 */

describe('tenant identity', function (): void {
    beforeEach(function (): void {
        TenancyPanel::boot();
        TenancyPanel::reset();

        $this->acme = Workspace::query()->create(['name' => 'Acme']);
        $this->beta = Workspace::query()->create(['name' => 'Beta']);

        Document::query()->create([
            'workspace_id' => $this->acme->getKey(),
            'title' => 'Acme plan',
        ]);

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

    it('names the current tenant for a user who has only one', function (): void {
        // The case the switcher used to swallow whole. Ada belongs to Acme and
        // nowhere else, so there was nothing to switch to, so nothing rendered
        // and the company she was working in appeared nowhere in the shell.
        $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenancy.label', 'Acme')
                ->has('tenancy.available', 1));
    });

    it('names it for a user who has several, alongside the switcher', function (): void {
        $this->user->workspaces()->attach($this->beta->getKey());

        $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenancy.label', 'Acme')
                ->has('tenancy.available', 2));
    });

    it('follows the tenant of the request rather than the first one seen', function (): void {
        $this->user->workspaces()->attach($this->beta->getKey());

        Document::query()->create([
            'workspace_id' => $this->beta->getKey(),
            'title' => 'Beta plan',
        ]);

        $first = $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey());
        $second = $this->get(TenancyPanel::url('documents').'?workspace='.$this->beta->getKey());

        // Two requests in one process, which is the shape an Octane worker
        // serves them in. A label cached anywhere that outlives a request
        // would answer 'Acme' twice.
        expect($first->viewData('page')['props']['tenancy']['label'])->toBe('Acme')
            ->and($second->viewData('page')['props']['tenancy']['label'])->toBe('Beta');
    });

    it('lets the panel decide what a tenant is called', function (): void {
        $panel = app(PanelManager::class)->get(TenancyPanel::ID);

        $panel->tenantLabel(fn (Workspace $workspace): string => 'PT '.$workspace->name);

        try {
            $this->get(TenancyPanel::url('documents').'?workspace='.$this->acme->getKey())
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('tenancy.label', 'PT Acme'));
        } finally {
            $panel->tenantLabel(null);
        }
    });

    it('defaults to the tenant\'s own name', function (): void {
        $panel = app(PanelManager::class)->get(TenancyPanel::ID);

        expect($panel->getTenantLabel($this->acme))->toBe(Tenancy::nameOf($this->acme));
    });

    it('has no label outside a tenant, and does not crash asking', function (): void {
        $panel = app(PanelManager::class)->get(TenancyPanel::ID);

        expect($panel->getTenantLabel(null))->toBeNull();
    });

    it('says nothing tenant-shaped for a panel with no tenancy', function (): void {
        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/admin');

        expect($response->viewData('page')['props']['tenancy'])->toBeNull();
    });
});

/*
 * Session 6 — the submit button
 */

describe('submit labels', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());

        app(PanelManager::class)->setCurrentPanel(panel('admin'));
    });

    it('sends no label from a create page that names none', function (): void {
        // Null rather than a string, so the page reads exactly as it did:
        // the default lives in the component that already had it.
        expect(propsOf(new CreateUser)['submitLabel'])->toBeNull();
    });

    it('sends no label from an edit page that names none', function (): void {
        $record = User::factory()->create();

        expect(propsOf(new EditUser, (string) $record->getKey())['submitLabel'])->toBeNull();
    });

    it('sends the label a create page declared', function (): void {
        expect(propsOf(new LabelledCreateUser)['submitLabel'])->toBe('Process schedule');
    });

    it('sends the label an edit page computed', function (): void {
        $record = User::factory()->create();

        // Resolved through the method, so a label needing the locale, the
        // record, or anything else only the page knows has somewhere to live.
        expect(propsOf(new LabelledEditUser, (string) $record->getKey())['submitLabel'])
            ->toBe('Save and continue');
    });

    it('changes nothing else about the page', function (): void {
        $plain = propsOf(new CreateUser);
        $labelled = propsOf(new LabelledCreateUser);

        expect($labelled['submitUrl'])->toBe($plain['submitUrl'])
            ->and($labelled['optionsUrl'])->toBe($plain['optionsUrl'])
            ->and($labelled['formStateUrl'])->toBe($plain['formStateUrl']);
    });
});

/**
 * @return array<string, mixed>
 */
function propsOf(object $page, mixed ...$arguments): array
{
    return $page->render(request(), ...$arguments)
        ->toResponse(request())
        ->original
        ->getData()['page']['props'];
}
