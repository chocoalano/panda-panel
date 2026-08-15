<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Pages\Settings;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('registers a standalone page under the panel route naming convention', function (): void {
    expect(Route::has('panel.admin.pages.settings'))->toBeTrue()
        ->and(route('panel.admin.pages.settings', absolute: false))->toBe('/admin/settings');
});

it('does not register the page in a panel that never declared it', function (): void {
    expect(Route::has('panel.app.pages.settings'))->toBeFalse();
});

it('renders the page with its own component', function (): void {
    $this->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Panels/Admin/Pages/Settings')
            ->where('page.title', 'Settings')
            ->where('page.heading', 'Settings')
            ->where('page.subheading', 'Application-wide configuration.')
            ->has('settings')
        );
});

it('builds breadcrumbs from the dashboard through the navigation group', function (): void {
    $this->get('/admin/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.breadcrumbs.0.label', 'Dashboard')
            ->where('page.breadcrumbs.0.href', '/admin')
            ->where('page.breadcrumbs.1.label', 'System')
            ->where('page.breadcrumbs.1.href', null)
            ->where('page.breadcrumbs.2.label', 'Settings')
            ->where('page.breadcrumbs.2.current', true)
        );
});

it('ships page props alongside the page metadata', function (): void {
    $this->get('/admin/settings')
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = array_column($page->toArray()['props']['settings'], 'label');

            expect($labels)->toContain('Application name', 'Environment');
        });
});

it('appears in the navigation of its own panel only', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $groups = $page->toArray()['props']['navigation'];
            $system = collect($groups)->firstWhere('label', 'System');

            expect($system)->not->toBeNull()
                ->and(array_column($system['items'], 'label'))->toContain('Settings');
        });
});

it('exposes a panel-aware url helper', function (): void {
    expect(Settings::url('admin'))->toBe('/admin/settings')
        ->and(Settings::routeName('admin'))->toBe('panel.admin.pages.settings');
});

it('is not a resource', function (): void {
    // A page has no index, create, or record routes: those belong to
    // resources, and conflating the two is what this proves it does not do.
    expect(Route::has('panel.admin.resources.settings.index'))->toBeFalse();

    $this->get('/admin/settings/create')->assertNotFound();
});

it('keeps panel routes cacheable with pages registered', function (): void {
    $this->artisan('route:cache')->assertSuccessful();
    $this->artisan('route:clear')->assertSuccessful();
});
