<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Notifications\Enums\NotificationColor;
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;
use PandaPanel\Notifications\PanelNotificationSent;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

/*
 * Sending
 */

it('broadcasts without storing unless it was told to persist', function (): void {
    Event::fake([PanelNotificationSent::class]);

    Notification::make('saved')->title('Saved.')->success()->send($this->admin);

    Event::assertDispatched(PanelNotificationSent::class);

    // Most notifications answer something the user just did. A bell that
    // fills up with "Saved." is a bell nobody reads.
    expect($this->admin->notifications()->count())->toBe(0);
});

it('stores one that asked to be persistent', function (): void {
    Event::fake([PanelNotificationSent::class]);

    Notification::make('export-ready')
        ->title('Your export is ready')
        ->body('1,204 records')
        ->success()
        ->persistent()
        ->send($this->admin);

    $stored = $this->admin->notifications()->first();

    expect($this->admin->notifications()->count())->toBe(1)
        ->and($stored?->data['title'])->toBe('Your export is ready')
        ->and($stored?->data['body'])->toBe('1,204 records')
        ->and($stored?->read_at)->toBeNull();
});

it('stores without broadcasting when the toast would be a duplicate', function (): void {
    Event::fake([PanelNotificationSent::class]);

    Notification::make('export-ready')
        ->title('Ready')
        ->persistent()
        ->broadcast(false)
        ->send($this->admin);

    Event::assertNotDispatched(PanelNotificationSent::class);

    expect($this->admin->notifications()->count())->toBe(1);
});

it('carries its actions as links the server produced', function (): void {
    Notification::make('export-ready')
        ->title('Ready')
        ->persistent()
        ->actions([
            NotificationAction::make('download')
                ->label('Download')
                ->url('/admin/exports/users.csv'),
        ])
        ->send($this->admin);

    $action = $this->admin->notifications()->first()?->data['actions'][0] ?? null;

    // Never an action name to resolve: a notification outlives the page that
    // sent it, and whatever the link points at authorizes for itself.
    expect($action['label'])->toBe('Download')
        ->and($action['url'])->toBe('/admin/exports/users.csv')
        ->and($action['markAsRead'])->toBeTrue();
});

it('takes its icon from its colour unless it names one', function (): void {
    $default = Notification::make('a')->warning()->toArray();
    $named = Notification::make('b')->warning()->icon('users')->toArray();

    expect($default['icon'])->toBe('triangle-alert')
        ->and($named['icon'])->toBe('users')
        ->and($default['type'])->toBe('warning')
        ->and(NotificationColor::Danger->toastType())->toBe('error');
});

/*
 * The notification centre
 */

it('lists only the asking user\'s own notifications', function (): void {
    $other = User::factory()->create();

    Notification::make('mine')->title('Mine')->persistent()->send($this->admin);
    Notification::make('theirs')->title('Theirs')->persistent()->send($other);

    $response = $this->actingAs($this->admin)->getJson('/admin/notifications');

    $titles = array_column($response->json('notifications'), 'title');

    // The scope *is* the authorization: the query starts from the
    // authenticated user, so there is no id a request could send that would
    // reach somebody else's.
    expect($titles)->toBe(['Mine'])
        ->and($response->json('unread'))->toBe(1);
});

it('marks one read, and then all of them', function (): void {
    Notification::make('a')->title('A')->persistent()->send($this->admin);
    Notification::make('b')->title('B')->persistent()->send($this->admin);

    $first = $this->admin->notifications()->first();

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/read', ['id' => $first?->getKey()])
        ->assertOk()
        ->assertJsonPath('unread', 1);

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/read')
        ->assertOk()
        ->assertJsonPath('unread', 0);
});

it('cannot be pointed at another user\'s notification', function (): void {
    $other = User::factory()->create();

    Notification::make('theirs')->title('Theirs')->persistent()->send($other);

    $theirs = $other->notifications()->first();

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/read', ['id' => $theirs?->getKey()])
        ->assertOk();

    // Matched nothing rather than 403'd, which is the same outcome and one
    // fewer thing to leak.
    expect($other->unreadNotifications()->count())->toBe(1);
});

it('clears the read ones, or all of them when asked', function (): void {
    Notification::make('a')->title('A')->persistent()->send($this->admin);
    Notification::make('b')->title('B')->persistent()->send($this->admin);

    $this->admin->notifications()->first()?->markAsRead();

    $this->actingAs($this->admin)->postJson('/admin/notifications/clear')->assertOk();

    expect($this->admin->notifications()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/clear', ['all' => true])
        ->assertOk();

    expect($this->admin->notifications()->count())->toBe(0);
});

it('falls back rather than rendering a colour that is not one', function (): void {
    $this->admin->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'panel',
        'data' => ['title' => 'Odd', 'color' => 'chartreuse', 'actions' => ['nonsense']],
        'read_at' => null,
    ]);

    $entry = $this->actingAs($this->admin)
        ->getJson('/admin/notifications')
        ->json('notifications.0');

    // A stored row is JSON somebody wrote a week ago, which makes it as
    // untrusted as a request body.
    expect($entry['color'])->toBe('info')
        ->and($entry['icon'])->toBe('info')
        ->and($entry['actions'])->toBe([]);
});

it('sends the unread count with every panel request', function (): void {
    Notification::make('a')->title('A')->persistent()->send($this->admin);

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $notifications = $page->toArray()['props']['notifications'];

            // Read on every request rather than polled, so the bell is right
            // after any navigation without a second round trip.
            expect($notifications['enabled'])->toBeTrue()
                ->and($notifications['unread'])->toBe(1)
                ->and($notifications['indexUrl'])->toContain('/admin/notifications');
        });
});
