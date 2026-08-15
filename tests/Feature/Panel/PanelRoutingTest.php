<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

it('registers a predictable dashboard route name for every panel', function (): void {
    expect(Route::has('panel.admin.dashboard'))->toBeTrue()
        ->and(Route::has('panel.app.dashboard'))->toBeTrue()
        ->and(route('panel.admin.dashboard', absolute: false))->toBe('/admin')
        ->and(route('panel.app.dashboard', absolute: false))->toBe('/app');
});

it('points panel routes at controllers so they stay route cacheable', function (): void {
    $action = Route::getRoutes()->getByName('panel.admin.dashboard')?->getAction('uses');

    expect($action)->toBeString()
        ->and($action)->toContain('PanelDashboardController');
});

it('applies the panel middleware stack to panel routes', function (): void {
    $middleware = Route::getRoutes()->getByName('panel.admin.dashboard')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('web')
        ->and($middleware)->toContain('auth')
        ->and($middleware)->toContain('verified')
        ->and(implode(' ', $middleware))->toContain('ResolvePanel:admin');
});

it('renders the panel dashboard component with page metadata', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/Dashboard')
            ->where('panel.id', 'admin')
            ->where('page.heading', 'Dashboard')
            ->has('page.breadcrumbs', 1)
            ->where('page.breadcrumbs.0.current', true)
        );
});

it('renders each panel with its own identity', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('panel.id', 'app')
            ->where('panel.path', 'app')
            ->where('panel.name', 'Application')
        );
});

it('does not share panel props outside a panel', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('panel', null)
            ->where('navigation', [])
        );
});

it('keeps the existing starter kit routes working', function (): void {
    $user = User::factory()->create();

    $this->get('/')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    // Settings moved into the panel, so the starter kit address is now an
    // alias for it rather than a second screen.
    $this->actingAs($user)->get('/settings/profile')
        ->assertRedirect('/app/settings/profile');
});
