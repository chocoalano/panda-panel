<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Http\Responses\PanelLoginResponse;
use PandaPanel\Http\Responses\PanelRegisterResponse;
use PandaPanel\Http\Responses\PanelTwoFactorLoginResponse;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Support\PanelPostLogin;

/*
|--------------------------------------------------------------------------
| Where signing in actually lands
|--------------------------------------------------------------------------
|
| A panel's login page posts to Fortify's own endpoint on purpose: one
| implementation of rate limiting, two-factor, passkeys and session fixation.
| The cost is that Fortify then answers with its own redirect, to
| `fortify.home` — `/dashboard` in every starter kit, and a route a blank
| application does not have at all.
|
| So signing in *at* `/gate/login` used to put you on the application's
| dashboard, which is the first thing anybody does after `panel:install` and
| the first thing that looked broken.
|
*/

/**
 * A panel with a front door of its own, registered once for this file.
 */
function gatePanel(): Panel
{
    $manager = app(PanelManager::class);

    if (! $manager->has('gate')) {
        $panel = $manager->register(
            Panel::make('gate')
                ->path('gate')
                ->settings(false)
                ->auth(verified: false)
                ->login()
                ->registration()
                ->passwordReset(),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return $manager->get('gate');
}

beforeEach(function (): void {
    $this->panel = gatePanel();
    $this->user = User::factory()->admin()->create();
});

/**
 * Fortify's own login POST, which is what every panel login form submits to.
 */
function signIn(User $user): TestResponse
{
    return test()->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
}

/*
 * The panel the sign-in started at
 */

it('lands in the panel whose login page was opened', function (): void {
    $this->get('/gate/login')->assertOk();

    signIn($this->user)->assertRedirect('/gate');
});

it('lands in the panel that was opened, not the first one registered', function (): void {
    // The bug `PanelHomeRedirect` could not fix: it answers with the first
    // panel the account can enter, so a second panel's door took you to the
    // first panel's dashboard.
    expect(app(PanelManager::class)->firstAccessibleTo($this->user)->getId())->toBe('admin');

    $this->get('/gate/login')->assertOk();

    signIn($this->user)->assertRedirect('/gate');
});

it('remembers the panel from any of its auth pages, not only the login', function (): void {
    // Somebody who started at `/gate/forgot-password` meant `/gate`, and the
    // reset finishes by signing them in.
    $this->get('/gate/forgot-password')->assertOk();

    signIn($this->user)->assertRedirect('/gate');
});

it('forgets the panel after one sign-in', function (): void {
    $this->get('/gate/login')->assertOk();

    expect(session(PanelPostLogin::SESSION_KEY))->toBe('gate');

    signIn($this->user)->assertRedirect('/gate');

    // A stale id would send the *next* sign-in — from the application's own
    // login page — somewhere it was never asked to go.
    expect(session(PanelPostLogin::SESSION_KEY))->toBeNull();
});

it('falls through when the account cannot enter the panel it signed in at', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('vault')) {
        $vault = $manager->register(
            Panel::make('vault')
                ->path('vault')
                ->settings(false)
                ->auth(verified: false)
                ->login()
                // A guest has to be let past, or the panel cannot serve the
                // login page that would make them anything else.
                ->canAccess(static fn (?Authenticatable $user): bool => $user === null
                    || ($user instanceof User && $user->is_admin)),
        );

        app(PanelRouteRegistrar::class)->register($vault);

        Route::getRoutes()->refreshNameLookups();
    }

    $member = User::factory()->create();

    $this->get('/vault/login')->assertOk();

    // Not `/vault`, which would 403 and bounce straight back out again.
    signIn($member)->assertRedirect('/app');
});

/*
 * A sign-in that did not start at a panel
 */

it('hands an application sign-in to the first panel, while home_redirect is on', function (): void {
    signIn($this->user)->assertRedirect('/admin');
});

it('leaves an application sign-in alone when home_redirect is off', function (): void {
    // That flag is the application saying it means to keep its own dashboard,
    // and this is the same decision.
    Config::set('panda-panel.home_redirect.enabled', false);

    signIn($this->user)->assertRedirect(config('fortify.home'));
});

/*
 * The intended URL
 */

it('still prefers where the guest was actually going', function (): void {
    // The whole point of `intended`: a guest who asked for a page and was sent
    // to a login comes back to the page.
    $this->get('/gate/search')->assertRedirect('/gate/login');

    $this->get('/gate/login')->assertOk();

    signIn($this->user)->assertRedirect('/gate/search');
});

it('drops an intended URL the panel has taken over', function (): void {
    // `/dashboard` is behind `auth`, so a guest who opens it has it stored as
    // their intended URL — and `home_redirect` would then bounce them out of
    // it again. In an application with no such route it is a 404 instead.
    $this->get('/dashboard')->assertRedirect(route('login'));

    expect(session('url.intended'))->toContain('/dashboard');

    $this->get('/gate/login')->assertOk();

    signIn($this->user)->assertRedirect('/gate');
});

/*
 * Staying out of the way
 */

it('is what Fortify resolves, in place of Fortify’s own', function (): void {
    // Bound in `register()` because Fortify binds its own there too, and the
    // last binding wins. A test that only checked the redirect would pass
    // just as well with Fortify's response and a lucky `home_redirect`.
    expect(app(LoginResponse::class))->toBeInstanceOf(PanelLoginResponse::class)
        ->and(app(TwoFactorLoginResponse::class))->toBeInstanceOf(PanelTwoFactorLoginResponse::class)
        ->and(app(RegisterResponse::class))->toBeInstanceOf(PanelRegisterResponse::class);
});

it('does not resolve a panel at all when it is turned off', function (): void {
    Config::set('panda-panel.login_redirect', false);

    $this->get('/gate/login')->assertOk();

    expect(PanelPostLogin::for(request()->merge([])))->toBeNull();
});

it('answers JSON exactly as Fortify does', function (): void {
    // A client reading a status code is not following a redirect, and
    // rewriting the body to mention a panel would break somebody's SPA.
    $this->get('/gate/login')->assertOk();

    $this->postJson('/login', [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk()->assertExactJson(['two_factor' => false]);
});
