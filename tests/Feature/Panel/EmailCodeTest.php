<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Auth\EmailCodeChallenge;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Http\Controllers\PanelTwoFactorController;
use PandaPanel\Notifications\TwoFactorCode;
use PandaPanel\Routing\PanelRouteRegistrar;

/**
 * A panel a signed-in user can reach, so the challenge is the only thing in
 * the way.
 */
function codePanel(): Panel
{
    $manager = app(PanelManager::class);

    if (! $manager->has('coded')) {
        $panel = $manager->register(
            Panel::make('coded')->path('coded')->auth(verified: false),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return $manager->get('coded');
}

beforeEach(function (): void {
    codePanel();

    $this->user = User::factory()->create();
});

/*
 * The challenge itself
 */

it('issues a six-digit code and stores it hashed', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    $code = $challenge->issue($this->user);

    expect($code)->toMatch('/^\d{6}$/')
        ->and($challenge->pending($this->user))->toBeTrue()
        // A cache a support engineer can read is a cache that can be read,
        // and a code in it is a password for one login.
        ->and(cache()->get('panel.mfa.email.code.'.$this->user->getKey()))
        ->not->toBe($code);
});

it('accepts the code once and never again', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    $code = (string) $challenge->issue($this->user);

    expect($challenge->verify($this->user, $code))->toBeTrue()
        // A code read over somebody's shoulder is worth nothing once used.
        ->and($challenge->verify($this->user, $code))->toBeFalse();
});

it('refuses a code that is not the one', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    $code = (string) $challenge->issue($this->user);
    $wrong = $code === '000000' ? '111111' : '000000';

    expect($challenge->verify($this->user, $wrong))->toBeFalse()
        // The real one still works: a wrong guess must not spend it.
        ->and($challenge->verify($this->user, $code))->toBeTrue();
});

it('stops guessing after a handful of tries', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    $code = (string) $challenge->issue($this->user);

    // Six digits is a million tries at machine speed, which is minutes
    // without a limit.
    foreach (range(1, 5) as $ignored) {
        $challenge->verify($this->user, '999999');
    }

    expect($challenge->verify($this->user, $code))->toBeFalse();
});

it('stops sending after a handful of requests', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    foreach (range(1, 5) as $ignored) {
        expect($challenge->issue($this->user))->not->toBeNull();
    }

    // A mailbox is not a place to be flooded.
    expect($challenge->issue($this->user))->toBeNull()
        ->and($challenge->secondsUntilNextSend($this->user))->toBeGreaterThan(0);
});

it('throws away an outstanding code when the factor is turned off', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    $challenge->issue($this->user);
    $challenge->forget($this->user);

    expect($challenge->pending($this->user))->toBeFalse();
});

/*
 * The gate
 */

it('leaves an account that never turned it on alone', function (): void {
    $this->actingAs($this->user)->get('/coded')->assertOk();
});

it('holds a session that has not answered a code', function (): void {
    Notification::fake();

    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $this->actingAs($this->user)
        ->get('/coded')
        ->assertRedirect(route('panel.coded.auth.two-factor.challenge', absolute: false));

    // Where they were going, so answering returns them there.
    expect(session('url.intended'))->toContain('/coded');
});

it('sends a code when the challenge page is opened', function (): void {
    Notification::fake();

    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $this->actingAs($this->user)
        ->get('/coded/two-factor/challenge')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/auth/EmailCode')
            // Enough to recognise the inbox, not enough to learn it from
            // somebody else's screen.
            ->where('sentTo', fn (string $sent): bool => str_contains($sent, '@')
                && ! str_contains($sent, (string) $this->user->email))
        );

    Notification::assertSentTo($this->user, TwoFactorCode::class);
});

it('lets the session through once the code is answered', function (): void {
    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $code = (string) app(EmailCodeChallenge::class)->issue($this->user);

    $this->actingAs($this->user)
        ->post('/coded/two-factor/verify', ['code' => $code])
        ->assertRedirect();

    $this->actingAs($this->user)->get('/coded')->assertOk();
});

it('refuses a wrong code with a message rather than a pass', function (): void {
    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    app(EmailCodeChallenge::class)->issue($this->user);

    $this->actingAs($this->user)
        ->post('/coded/two-factor/verify', ['code' => '000000', 'from' => 'test'])
        ->assertSessionHasErrors('code');

    expect(session()->has(PanelTwoFactorController::SESSION_KEY))->toBeFalse();
});

it('will not accept a code that is not six digits', function (): void {
    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    $this->actingAs($this->user)
        ->post('/coded/two-factor/verify', ['code' => '12'])
        ->assertSessionHasErrors('code');
});

it('lets the challenge route itself through', function (): void {
    Notification::fake();

    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    // Or answering it would be refused by the thing being answered.
    $this->actingAs($this->user)->get('/coded/two-factor/challenge')->assertOk();
});

/*
 * Turning it on and off
 */

it('counts an emailed code as a second factor for a panel that demands one', function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('coded-strict')) {
        $panel = $manager->register(
            Panel::make('coded-strict')
                ->path('coded-strict')
                ->auth(verified: false)
                ->requireTwoFactor(),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();

    // Demanding an authenticator app from somebody already using this would
    // be demanding a second second factor.
    $this->actingAs($this->user)
        ->withSession([PanelTwoFactorController::SESSION_KEY => now()->timestamp])
        ->get('/coded-strict')
        ->assertOk();
});

it('forgets an outstanding code when the factor is turned off', function (): void {
    $challenge = app(EmailCodeChallenge::class);

    $this->user->forceFill(['two_factor_email_confirmed_at' => now()])->save();
    $challenge->issue($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post('/coded/two-factor/disable')
        ->assertRedirect();

    expect($this->user->fresh()?->two_factor_email_confirmed_at)->toBeNull()
        ->and($challenge->pending($this->user))->toBeFalse();
});

afterEach(function (): void {
    RateLimiter::clear('panel.mfa.email.send.'.$this->user->getKey());
    RateLimiter::clear('panel.mfa.email.attempt.'.$this->user->getKey());
});
