<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Broadcasting\NotificationChannel;
use PandaPanel\Broadcasting\PanelNotification;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Notifications\PanelNotificationSent;
use PandaPanel\Support\PanelContext;
use PandaPanel\Tenancy\Tenancy;
use Tests\Fixtures\Panel\Tenancy\TenancyPanel;
use Tests\Fixtures\Panel\Tenancy\TenantUser;
use Tests\Fixtures\Panel\Tenancy\Workspace;

/*
|--------------------------------------------------------------------------
| Notification channels across a tenant boundary
|--------------------------------------------------------------------------
|
| `App.Models.User.1` identifies a row, and a row id is unique only inside one
| database. An application with a database per tenant has a user 1 in each of
| them and they are different people — but broadcasting is a single shared
| namespace regardless, one Reverb or one Pusher app, so every one of those
| users landed on the same private channel.
|
| Whether tenant B's employee received tenant A's notification then came down
| to who happened to be connected. That is not a bug you can see in a test of
| one tenant, which is why it survived: every notification test passes with
| one tenant in the database.
|
*/

beforeEach(function (): void {
    TenancyPanel::boot();
    TenancyPanel::reset();

    $this->acme = Workspace::query()->create(['name' => 'Acme']);
    $this->beta = Workspace::query()->create(['name' => 'Beta']);

    $this->user = TenantUser::query()->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => bcrypt('secret-secret'),
    ]);

    $this->other = TenantUser::query()->create([
        'name' => 'Grace',
        'email' => 'grace@example.test',
        'password' => bcrypt('secret-secret'),
    ]);
});

afterEach(function (): void {
    clearTenant();
});

/**
 * Unbinds the tenant while leaving the panel bound.
 *
 * There is no `Tenancy::forget()`; the panel context holds both, and clearing
 * it wholesale would also unbind the panel — which is not what a central page
 * looks like. A central request has the panel resolved and no tenant, and
 * that distinction is exactly what this test is about.
 */
function clearTenant(): void
{
    $context = app(PanelContext::class);
    $panel = $context->panel();

    $context->forget();

    if ($panel !== null) {
        $context->setPanel($panel);
    }
}

/** The channel a user resolves to while the given tenant is bound. */
function channelIn(?Workspace $tenant, Authenticatable $user): string
{
    $tenant === null ? clearTenant() : Tenancy::bind($tenant);

    return NotificationChannel::for($user);
}

/*
 * T5 — the finding itself
 */

it('does not share a notification broadcast channel between tenants that reuse the same user id', function (): void {
    $a = channelIn($this->acme, $this->user);
    $b = channelIn($this->beta, $this->user);

    expect($a)->not->toBe($b)
        ->and($a)->toContain((string) $this->acme->getKey())
        ->and($b)->toContain((string) $this->beta->getKey());
});

/*
 * T3 / T4 / T6 / T7 — every other pair stays distinct too
 */

it('gives one tenant\'s user a deterministic channel', function (): void {
    expect(channelIn($this->acme, $this->user))
        ->toBe(channelIn($this->acme, $this->user));
});

it('separates two users inside one tenant', function (): void {
    expect(channelIn($this->acme, $this->user))
        ->not->toBe(channelIn($this->acme, $this->other));
});

it('separates a central context from every tenant', function (): void {
    $central = channelIn(null, $this->user);

    // Not the bare user id: falling back to that outside a tenant is the
    // collision this exists to remove.
    expect($central)->not->toBe(channelIn($this->acme, $this->user))
        ->and($central)->not->toBe(channelIn($this->beta, $this->user))
        ->and($central)->not->toBe('App.Models.User.'.$this->user->getKey());
});

/*
 * T8 / T9 — nothing is memoized on the panel
 */

it('follows the tenant of the moment rather than the first one seen', function (): void {
    $first = channelIn($this->acme, $this->user);
    $second = channelIn($this->beta, $this->user);
    $back = channelIn($this->acme, $this->user);

    // Three resolutions, one long-lived Panel instance — the shape an Octane
    // worker serves requests in.
    expect($first)->not->toBe($second)
        ->and($back)->toBe($first);
});

/*
 * T10 — what actually gets broadcast
 */

it('broadcasts on the channel of the tenant that sent it', function (): void {
    Tenancy::bind($this->acme);

    $event = new PanelNotification($this->user, 'Payroll ready');

    expect($event->broadcastOn()[0]->name)->toBe('private-'.channelIn($this->acme, $this->user));
});

it('keeps the channel it was created with when the tenant is gone', function (): void {
    Tenancy::bind($this->acme);

    // Constructed inside the request that had the tenant bound.
    $event = new PanelNotification($this->user, 'Payroll ready');
    $expected = NotificationChannel::for($this->user);

    // The queue worker that broadcasts it has no tenant of its own.
    clearTenant();

    expect($event->broadcastOn()[0]->name)->toBe('private-'.$expected);
});

it('does the same for the toast event', function (): void {
    Tenancy::bind($this->acme);

    $event = new PanelNotificationSent($this->user, ['message' => 'hi']);
    $expected = NotificationChannel::for($this->user);

    clearTenant();

    expect($event->broadcastOn()[0]->name)->toBe('private-'.$expected);
});

/*
 * T12 / T13 / T14 — authorization uses the same answer
 */

it('lets a user subscribe to their own channel', function (): void {
    Tenancy::bind($this->acme);

    expect(NotificationChannel::authorize($this->user, NotificationChannel::for($this->user)))
        ->toBeTrue();
});

it('refuses another tenant\'s channel for the same user id', function (): void {
    $beta = channelIn($this->beta, $this->user);

    Tenancy::bind($this->acme);

    // The whole point: same user id, different tenant, refused.
    expect(NotificationChannel::authorize($this->user, $beta))->toBeFalse();
});

it('refuses another user\'s channel in the same tenant', function (): void {
    Tenancy::bind($this->acme);

    $theirs = NotificationChannel::for($this->other);

    expect(NotificationChannel::authorize($this->user, $theirs))->toBeFalse();
});

it('refuses a guest', function (): void {
    Tenancy::bind($this->acme);

    expect(NotificationChannel::authorize(null, NotificationChannel::for($this->user)))
        ->toBeFalse();
});

/*
 * T11 — the browser subscribes to what the server resolved
 */

it('hands the browser the same channel the producer broadcasts on', function (): void {
    Tenancy::bind($this->acme);

    $panel = app(PanelManager::class)->get(TenancyPanel::ID);

    // `SharePanelData` builds the `broadcasting.channel` prop from exactly
    // this call, so producer and subscriber cannot drift: one resolver, two
    // readers. The client never invents the name.
    expect($panel->getBroadcastChannel($this->user))
        ->toBe(NotificationChannel::for($this->user))
        ->toContain((string) $this->acme->getKey());
});

/*
 * T2 / T17 / T18 — the explicit seam, for tenancy this package cannot see
 */

it('uses a resolver the panel declared', function (): void {
    $panel = app(PanelManager::class)->get(TenancyPanel::ID);

    $panel->broadcastChannelUsing(
        static fn (Authenticatable $user): string => 'custom.'.$user->getAuthIdentifier(),
    );

    try {
        expect(NotificationChannel::for($this->user))
            ->toBe('custom.'.$this->user->getKey());
    } finally {
        $panel->broadcastChannelUsing(static fn (Authenticatable $u): string => NotificationChannel::for($u));
    }
});

it('refuses to fall back when a declared resolver answers with nothing', function (): void {
    $panel = Panel::make('resolver-probe')
        ->tenant(Workspace::class, static fn (): ?Workspace => null)
        ->broadcastChannelUsing(static fn (): string => '');

    // Falling back would be `App.Models.User.1` — the exact collision the
    // resolver was declared to prevent, restored silently.
    expect(fn () => NotificationChannel::for($this->user, $panel))
        ->toThrow(RuntimeException::class, 'non-empty channel name');
});

/*
 * T1 — an application with no tenancy is untouched
 */

it('leaves a panel without tenancy on the channel it always had', function (): void {
    $plain = Panel::make('plain-probe');
    $user = User::factory()->create();

    expect(NotificationChannel::for($user, $plain))
        ->toBe('App.Models.User.'.$user->getKey());
});

/*
 * T15 — broadcasting off stays off
 */

it('offers no channel when broadcasting is disabled', function (): void {
    $panel = Panel::make('quiet-probe')->broadcasting(false);

    expect($panel->getBroadcastChannel($this->user))->toBeNull();
});
