<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

/**
 * A panel with its own front door, registered once for this file.
 */
function authPanel(): Panel
{
    $manager = app(PanelManager::class);

    if (! $manager->has('door')) {
        $panel = $manager->register(
            Panel::make('door')
                ->path('door')
                ->settings(false)
                ->auth(verified: false)
                ->login()
                ->registration()
                ->passwordReset()
                ->emailVerification(),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return $manager->get('door');
}

beforeEach(function (): void {
    $this->panel = authPanel();
});

/*
 * The front door
 */

it('serves the panel\'s own login page to a guest', function (): void {
    $this->get('/door/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/auth/Login')
            // The panel's identity is the whole reason it has a door of its
            // own rather than sharing the application's.
            ->where('panel.name', 'Door')
            ->where('canResetPassword', true)
            ->where('canRegister', true)
        );
});

it('registers the pages a panel asked for and no others', function (): void {
    expect(Route::has('panel.door.auth.login'))->toBeTrue()
        ->and(Route::has('panel.door.auth.register'))->toBeTrue()
        ->and(Route::has('panel.door.auth.password.request'))->toBeTrue()
        ->and(Route::has('panel.door.auth.verification.notice'))->toBeTrue()
        // The admin panel declared none, so it has none.
        ->and(Route::has('panel.admin.auth.login'))->toBeFalse();
});

it('sends a guest to this panel\'s door, keeping where they were going', function (): void {
    $this->get('/door')
        ->assertRedirect('/door/login');

    expect(session('url.intended'))->toContain('/door');
});

it('leaves a signed-in user alone', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/door')
        ->assertOk();
});

it('protects panel routes with auth by default', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('default-auth')) {
        $panel = $manager->register(
            Panel::make('default-auth')
                ->path('default-auth')
                ->settings(false),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    $this->get('/default-auth')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())
        ->get('/default-auth')
        ->assertOk();
});

it('makes a public panel an explicit middleware choice', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('public-door')) {
        $panel = $manager->register(
            Panel::make('public-door')
                ->path('public-door')
                ->settings(false)
                ->authMiddleware([]),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    $this->get('/public-door')->assertOk();
});

/*
 * The access contract
 */

it('lets a user model refuse a panel for itself', function (): void {
    $user = new class extends User implements PanelUser
    {
        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return $panel->getId() !== 'door';
        }
    };

    $user->forceFill(User::factory()->make()->getAttributes())->save();

    // A rule about the *user* rather than about the panel: written on the
    // model it applies to every panel at once and cannot be forgotten when a
    // new one is added.
    $this->actingAs($user)->get('/door')->assertForbidden();
});

it('asks both questions and lets either refuse', function (): void {
    $manager = app(PanelManager::class);

    $panel = Panel::make('both')
        ->path('both')
        ->canAccess(static fn (?Authenticatable $user): bool => false);

    $manager->register($panel);

    $permissive = new class extends User implements PanelUser
    {
        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    };

    // A user model that says yes cannot overrule a panel that says no.
    expect($panel->isAccessibleTo($permissive))->toBeFalse();
});

it('refuses nothing to a user model that implements neither', function (): void {
    expect(authPanel()->isAccessibleTo(User::factory()->make()))->toBeTrue();
});

/*
 * Required second factor
 */

/**
 * A panel where a second factor is not optional.
 */
function lockedPanel(): Panel
{
    $manager = app(PanelManager::class);

    if (! $manager->has('locked')) {
        $panel = $manager->register(
            Panel::make('locked')
                ->path('locked')
                ->auth(verified: false)
                ->requireTwoFactor(),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return $manager->get('locked');
}

it('holds a user at the security page until they have a second factor', function (): void {
    lockedPanel();

    $this->actingAs(User::factory()->create())
        ->get('/locked')
        // Sent to the one page where the thing being demanded can be done.
        ->assertRedirect(route('panel.locked.pages.settings-security', absolute: false));
});

it('lets the security page itself through, or there is nowhere to go', function (): void {
    lockedPanel();

    $response = $this->actingAs(User::factory()->create())
        ->get('/locked/settings/security');

    // That page asks for the password first, which is its own middleware and
    // not this one's business. What matters is that it is not redirected
    // *back to itself*, which would be a loop.
    expect($response->headers->get('Location'))
        ->not->toContain('/locked/settings/security');
});

it('lets a user through once a second factor exists', function (): void {
    lockedPanel();

    $user = User::factory()->create();

    // Fortify's own flag: a confirmed authenticator app.
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)->get('/locked')->assertOk();
});

it('fails closed when required two-factor has no security page', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('locked-missing-page')) {
        $panel = $manager->register(
            Panel::make('locked-missing-page')
                ->path('locked-missing-page')
                ->settings(false)
                ->auth(verified: false)
                ->requireTwoFactor(),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    $this->actingAs(User::factory()->create())
        ->get('/locked-missing-page')
        ->assertForbidden();
});

it('leaves a panel that does not demand one alone', function (): void {
    $this->actingAs(User::factory()->create())->get('/door')->assertOk();
});
