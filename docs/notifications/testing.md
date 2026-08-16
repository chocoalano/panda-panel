# Testing Notifications

A notification has two halves that answer different questions — "was the user told" and "can they find it later" — and a notification can legitimately be one without the other. The package ships assertions for both, plus everything else in the chain is an ordinary Laravel test: an event, a row, a JSON endpoint, an Inertia prop.

## A minimal working example

```php
<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Notifications\Notification;

it('tells the user their export is ready', function (): void {
    $user = User::factory()->create();

    fakePanelNotifications();

    Notification::make('export-ready')->title('Export ready')->send($user);

    assertPanelNotificationSentTo($user, 'Export ready');
});
```

No import for the helper: it is autoloaded through Composer's `files`, so it is available in a test without a base class or a trait.

## The helpers

Five free functions, in `src/Testing/helpers.php`. Every one is guarded by `function_exists`, so an application that already has a function of the same name keeps it.

| Function | Signature | Asserts on |
| --- | --- | --- |
| `fakePanelNotifications` | `fakePanelNotifications(): void` | — starts recording |
| `assertPanelNotificationSentTo` | `assertPanelNotificationSentTo(Authenticatable $user, ?string $title = null): void` | the broadcast |
| `assertNoPanelNotifications` | `assertNoPanelNotifications(): void` | the broadcast |
| `assertPanelNotificationStoredFor` | `assertPanelNotificationStoredFor(Authenticatable $user, ?string $title = null): void` | the database |
| `assertNoPanelNotificationsStoredFor` | `assertNoPanelNotificationsStoredFor(Authenticatable $user): void` | the database |

Each delegates to `PandaPanel\Testing\TestsNotifications`, which is public for a test that would rather hold the class:

```php
use PandaPanel\Testing\TestsNotifications;

TestsNotifications::fake();
TestsNotifications::assertSentTo($user, 'Export ready');
TestsNotifications::assertNothingSent();
TestsNotifications::assertStoredFor($user, 'Export ready');
TestsNotifications::assertNothingStoredFor($user);
```

### `fakePanelNotifications()`

```php
Event::fake([PanelNotificationSent::class]);
```

Narrow on purpose. Faking every event would silence the model events the panel relies on, and a test that did so would pass while a resource's lifecycle hooks were broken. Call it **before** the code under test — an event dispatched before the fake was installed is not recorded.

### The broadcast assertions

```php
use App\Models\User;
use PandaPanel\Notifications\Notification;

it('broadcasts to the right user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    fakePanelNotifications();

    Notification::make('saved')->title('Saved.')->send($user);

    assertPanelNotificationSentTo($user);            // any title
    assertPanelNotificationSentTo($user, 'Saved.');  // this title
});

it('says nothing when the action was refused', function (): void {
    fakePanelNotifications();

    // … code that should notify nobody …

    assertNoPanelNotifications();
});
```

The match is on `$event->user->getAuthIdentifier()` and, when given, `$event->payload['title']` — the title as it was resolved, so a notification that took its title from `Str::headline($name)` is matched by the headline.

### The database assertions

These read the database rather than the event, because that is the question: a broadcast that was not persisted is gone the moment the tab closes.

```php
it('leaves a notification the user can find later', function (): void {
    $user = User::factory()->create();

    Notification::make('export')->title('Export ready')->persistent()->send($user);

    assertPanelNotificationStoredFor($user, 'Export ready');
});

it('does not fill the bell with "Saved."', function (): void {
    $user = User::factory()->create();

    Notification::make('saved')->title('Saved.')->send($user);

    assertNoPanelNotificationsStoredFor($user);
});
```

No fake is needed — and no fake helps, since these query `$user->notifications()`. A user model without a `notifications()` method reads as empty rather than throwing, which makes `assertNothingStoredFor()` vacuously true on such a model.

## Asserting the payload itself

`Notification::toArray()` is pure, so the shape can be asserted without sending anything:

```php
use PandaPanel\Notifications\Enums\NotificationColor;
use PandaPanel\Notifications\Notification;

it('takes its icon from its colour unless it names one', function (): void {
    $default = Notification::make('a')->warning()->toArray();
    $named = Notification::make('b')->warning()->icon('users')->toArray();

    expect($default['icon'])->toBe('triangle-alert')
        ->and($named['icon'])->toBe('users')
        ->and($default['type'])->toBe('warning')
        ->and(NotificationColor::Danger->toastType())->toBe('error');
});
```

The same for the bare broadcast event, which is a value object:

```php
use PandaPanel\Broadcasting\PanelNotification;

$event = new PanelNotification($user, 'Export finished', 'success');

expect($event->broadcastAs())->toBe('panel.notification')
    ->and($event->broadcastWith())->toBe([
        'type' => 'success',
        'message' => 'Export finished',
        'url' => null,
        'urlLabel' => null,
    ])
    ->and($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$user->getKey());
```

`fakePanelNotifications()` does **not** cover `PanelNotification` — it fakes `PanelNotificationSent` alone. Assert on the other one with a fake of its own:

```php
use Illuminate\Support\Facades\Event;
use PandaPanel\Broadcasting\PanelNotification;

Event::fake([PanelNotification::class]);

PanelNotification::dispatch($user, 'Export finished');

Event::assertDispatched(PanelNotification::class, fn (PanelNotification $event): bool =>
    $event->message === 'Export finished' && $event->user->is($user));
```

## Testing the notification centre

Ordinary HTTP tests against the panel's endpoints:

```php
it('lists only the asking user\'s own notifications', function (): void {
    $other = User::factory()->create();

    Notification::make('mine')->title('Mine')->persistent()->send($this->admin);
    Notification::make('theirs')->title('Theirs')->persistent()->send($other);

    $response = $this->actingAs($this->admin)->getJson('/admin/notifications');

    expect(array_column($response->json('notifications'), 'title'))->toBe(['Mine'])
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
```

The security property is worth asserting in your own suite too, because it is the one that has no policy behind it:

```php
it('cannot be pointed at another user\'s notification', function (): void {
    $other = User::factory()->create();

    Notification::make('theirs')->title('Theirs')->persistent()->send($other);

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/read', ['id' => $other->notifications()->first()?->getKey()])
        ->assertOk();

    // Matched nothing rather than 403'd — the same outcome, one fewer leak.
    expect($other->unreadNotifications()->count())->toBe(1);
});
```

And that a stored row is treated as untrusted:

```php
$this->admin->notifications()->create([
    'id' => (string) Str::uuid(),
    'type' => 'panel',
    'data' => ['title' => 'Odd', 'color' => 'chartreuse', 'actions' => ['nonsense']],
    'read_at' => null,
]);

$entry = $this->actingAs($this->admin)->getJson('/admin/notifications')->json('notifications.0');

expect($entry['color'])->toBe('info')
    ->and($entry['icon'])->toBe('info')
    ->and($entry['actions'])->toBe([]);
```

## Testing the shared props

```php
use Inertia\Testing\AssertableInertia;

it('sends the unread count with every panel request', function (): void {
    Notification::make('a')->title('A')->persistent()->send($this->admin);

    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $notifications = $page->toArray()['props']['notifications'];

            expect($notifications['enabled'])->toBeTrue()
                ->and($notifications['unread'])->toBe(1)
                ->and($notifications['indexUrl'])->toContain('/admin/notifications');
        });
});
```

The broadcasting prop needs the application to look like it can broadcast, which a bare test skeleton does not:

```php
use Illuminate\Support\Facades\Config;

Config::set('broadcasting.default', 'reverb');
Config::set('broadcasting.connections.reverb.driver', 'reverb');

$this->actingAs($this->admin)->get('/admin')
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->where('broadcasting.enabled', true)
        ->where('broadcasting.channel', 'App.Models.User.'.$this->admin->getKey()));
```

State the config rather than assuming it: a channel is only sent to an application that can actually broadcast, and the negative cases are worth their own tests — `broadcasting.default` set to `null`, a default naming a connection that was never defined, and the `null` and `log` drivers all produce `enabled: false`.

## Testing the channel callback

Read it back from the broadcaster rather than restating the rule:

```php
use Illuminate\Support\Facades\Broadcast;

$callback = Broadcast::connection()->getChannels()->get('App.Models.User.{id}');

expect($callback)->not->toBeNull()
    ->and($callback($this->admin, $this->admin->getKey()))->toBeTrue()
    ->and($callback($this->admin, $other->getKey()))->toBeFalse();
```

## Testing the flash toast bridge

Inertia puts flash data beside `props` on the page object, not inside it, so the Inertia prop assertions do not reach it:

```php
use Illuminate\Testing\TestResponse;

function flashedToast(TestResponse $response): ?array
{
    $page = $response->viewData('page');

    $toast = is_array($page) ? ($page['flash']['toast'] ?? null) : null;

    return is_array($toast) ? $toast : null;
}

it('maps a conventional success flash onto the toast channel', function (): void {
    $this->get('/__test/flash-success')->assertRedirect('/');

    expect(flashedToast($this->get('/')))->toBe(['type' => 'success', 'message' => 'Saved.']);
});
```

The message is flashed on one request and rendered on the next, so the assertion always follows a second `get()`.

## Testing a job's notifications

Call the job's methods directly. Both package jobs are constructed from scalars, which makes this cheap:

```php
use PandaPanel\Jobs\RunPanelImport;

it('tells the user an import failed, and why', function (): void {
    Storage::fake(UserImporter::disk());

    fakePanelNotifications();

    $job = new RunPanelImport(UserImporter::class, 'imports/people.csv', ['name' => 0], $this->user->getKey(), 'admin');

    $job->failed(new RuntimeException('column count mismatch on row 12'));

    assertPanelNotificationSentTo($this->user, 'Import failed');
});
```

`failed()` is an ordinary method: invoking it is how you test the failure path without making the job actually fail.

## Gotchas

- **Fake before, assert after.** `fakePanelNotifications()` installs `Event::fake`, which only records what is dispatched afterwards.
- **A faked event is not persisted differently.** `Event::fake` stops the broadcast, not the `notify()` call, so the database assertions still work under a fake — the two halves are independent.
- **Titles are compared exactly.** `assertPanelNotificationSentTo($user, 'Export ready.')` fails against `'Export ready'`. Omit the title when the copy is not what the test is about.
- **`assertNothingStoredFor()` passes for a non-`Notifiable` model**, because it has nothing to ask. Assert the trait separately if that is the property you care about.
- **Testbench has no broadcaster.** Any assertion about `broadcasting.channel` needs `Config::set()` first, or it reads `false`/`null` and the test is asserting the wrong thing.

## See also

- [Toast notifications](toast.md)
- [Database notifications](database.md)
- [Notification center](notification-center.md)
- [Broadcasting](broadcasting.md) and [channel authorization](channel-authorization.md)
- [Queued notifications](queues.md)
- [Testing helpers](../testing/helpers.md)
- [Testing setup](../testing/setup.md)
