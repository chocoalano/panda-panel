<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PandaPanel\Support\PanelHomeRedirect;

/*
 * The starter kit's `/dashboard` is a placeholder, and Fortify points its
 * post-login redirect at it. These are the rules that hand it to the panel
 * without the package editing a single application file.
 */

it('sends a signed-in user from the starter kit dashboard into their panel', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertRedirect('/admin');
});

it('sends a user to the first panel they can actually enter', function (): void {
    // A member fails the admin panel's own access check, so the admin panel
    // is not a destination even though it is registered first.
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertRedirect('/app');
});

it('leaves a guest to the application own auth', function (): void {
    // Not our redirect: `/dashboard` is behind `auth`, and a guest belongs at
    // the login rather than at a panel they are not signed in to.
    $this->get('/dashboard')->assertRedirect(route('login'));
});

it('leaves every other application route alone', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertOk();
});

it('leaves the starter kit dashboard alone when it is turned off', function (): void {
    Config::set('panda-panel.home_redirect.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertOk();
});

it('takes over only the paths it is configured with', function (): void {
    Config::set('panda-panel.home_redirect.paths', ['reports/*']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertOk();
});

it('does not redirect a request that is already inside a panel', function (): void {
    // The guard against a panel mounted on a path this would otherwise take
    // over, which would redirect to itself forever.
    Config::set('panda-panel.home_redirect.paths', ['admin', 'admin/*']);

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk();
});

it('does not redirect a request that wants json', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/dashboard')
        ->assertOk();
});

it('changes nothing for a path it was not given', function (): void {
    $user = User::factory()->admin()->create();

    $request = function (string $uri) use ($user): Request {
        $request = Request::create($uri);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };

    expect(PanelHomeRedirect::for($request('/settings/profile')))->toBeNull()
        ->and(PanelHomeRedirect::for($request('/dashboard')))->toBe('/admin');
});
