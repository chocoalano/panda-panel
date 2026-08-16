# Testing Notifications

A panel notification has two halves that answer different questions — "was the user told" and "can they find it later" — and a notification can legitimately be one without the other. Five helpers cover both, and they are separate on purpose: a broadcast that was not persisted is gone the moment the tab closes, so the two assertions are not interchangeable.

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

No import for the helpers: they are autoloaded through Composer's `files`.

## What is actually being asserted

`Notification::send()` does two things, in this order:

```php
if ($this->persistent && method_exists($user, 'notify')) {
    $user->notify(new PanelDatabaseNotification($this->toArray()));
}

if ($this->broadcast) {
    event(new PanelNotificationSent($user, $this->toArray()));
}
```

The broadcast assertions look at the event. The stored assertions read `$user->notifications()`. `persistent()` is **off** by default and `broadcast()` is **on**, so a plain `Notification::make(...)->send($user)` is a toast and nothing else.

## Every helper

| Function | Signature | Reads |
| --- | --- | --- |
| `fakePanelNotifications` | `fakePanelNotifications(): void` | installs `Event::fake([PanelNotificationSent::class])` |
| `assertPanelNotificationSentTo` | `assertPanelNotificationSentTo(Authenticatable $user, ?string $title = null): void` | the dispatched event |
| `assertNoPanelNotifications` | `assertNoPanelNotifications(): void` | the dispatched event |
| `assertPanelNotificationStoredFor` | `assertPanelNotificationStoredFor(Authenticatable $user, ?string $title = null): void` | the database |
| `assertNoPanelNotificationsStoredFor` | `assertNoPanelNotificationsStoredFor(Authenticatable $user): void` | the database |

Each delegates to `PandaPanel\Testing\TestsNotifications`, which is public and entirely static:

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

### `assertPanelNotificationSentTo()`

Matches on `$event->user->getAuthIdentifier()` and, when a title is given, on `$event->payload['title']`:

```php
it('broadcasts to the right user', function (): void {
    $user = User::factory()->create();

    fakePanelNotifications();

    Notification::make('saved')->title('Saved.')->send($user);

    assertPanelNotificationSentTo($user);            // any title
    assertPanelNotificationSentTo($user, 'Saved.');  // this title
});
```

The title compared is the **resolved** one. A notification that declares no title takes `Str::headline($name)`, so `Notification::make('export-ready')->send($user)` is matched by `'Export Ready'`.

### `assertNoPanelNotifications()`

```php
it('says nothing when the action was refused', function (): void {
    fakePanelNotifications();

    // … code that should notify nobody …

    assertNoPanelNotifications();
});
```

`Event::assertNotDispatched()` underneath, so it is about the whole test rather than about one user.

### `assertPanelNotificationStoredFor()`

Reads the rows, not the event:

```php
it('leaves a notification the user can find later', function (): void {
    $user = User::factory()->create();

    Notification::make('export')->title('Export ready')->persistent()->send($user);

    assertPanelNotificationStoredFor($user, 'Export ready');
});
```

No fake is needed, and no fake helps: the assertion queries `$user->notifications()` and reads `data['title']` from each row. Without a title it asserts only that the user has at least one stored notification.

### `assertNoPanelNotificationsStoredFor()`

```php
it('does not fill the bell with "Saved."', function (): void {
    $user = User::factory()->create();

    Notification::make('saved')->title('Saved.')->send($user);

    assertNoPanelNotificationsStoredFor($user);
});
```

This is the assertion that catches a notification made `persistent()` by accident, which is how a notification centre becomes unreadable.

## Asserting the payload without sending

`Notification::toArray()` is pure, so the shape can be asserted directly — no user, no fake, no database:

```php
use PandaPanel\Notifications\Notification;

$payload = Notification::make('import-failed')->warning()->body('Row 12')->toArray();

expect($payload['title'])->toBe('Import Failed')
    ->and($payload['type'])->toBe('warning')
    ->and($payload['icon'])->toBe('triangle-alert')
    ->and($payload['body'])->toBe('Row 12');
```

| Builder method | Signature |
| --- | --- |
| `make` | `static make(?string $name = null): self` |
| `title` | `title(string $title): self` |
| `body` | `body(string $body): self` |
| `icon` | `icon(string $icon): self` |
| `color` | `color(NotificationColor $color): self` |
| `success` / `info` / `warning` / `danger` | `(): self` |
| `actions` | `actions(array $actions): self` |
| `persistent` | `persistent(bool $persistent = true): self` |
| `broadcast` | `broadcast(bool $broadcast = true): self` |
| `send` | `send(Authenticatable $user): self` |
| `toArray` | `toArray(): array` |

The two flags are worth a test of their own where a notification deliberately uses one channel only:

```php
$notification = Notification::make('report')->persistent()->broadcast(false);

expect($notification->isPersistent())->toBeTrue()
    ->and($notification->isBroadcast())->toBeFalse();
```

## Notifications a panel action sends

The usual reason to reach for these helpers is an action, an import or an export that notifies as a side effect. Fake, run the thing through the helper that runs it properly, then assert:

```php
use App\Panels\Admin\Resources\Users\UserResource;

it('notifies the administrator after a bulk verification', function (): void {
    fakePanelNotifications();

    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'verify',
        'records' => [$unverified->id],
    ]);

    assertPanelNotificationSentTo($this->admin);
});
```

For a queued import or export, the job's methods are ordinary methods — calling `failed()` is how the failure path is tested without making the job actually fail. See [Queued imports](../import-export/queued-imports.md).

## Gotchas

- **Fake before, assert after.** `Event::fake` only records what is dispatched afterwards.
- **The fake does not stop persistence.** `Event::fake` intercepts the broadcast, not the `notify()` call, so the stored assertions still work under a fake. The two halves are independent, which is what lets one test assert both.
- **Titles compare exactly.** `'Export ready.'` does not match `'Export ready'`. Omit the title when the copy is not what the test is about.
- **`assertNoPanelNotificationsStoredFor()` passes for a model with no `notifications()` method.** It has nothing to ask, so it reads as empty. Assert the `Notifiable` trait separately if that is the property you care about.
- **`fakePanelNotifications()` covers one event class.** `PandaPanel\Broadcasting\PanelNotification` — the bare toast event — is not faked by it. Use `Event::fake([PanelNotification::class])` for that one.
- **Testbench has no broadcaster.** Any assertion about the `broadcasting` shared prop needs `Config::set('broadcasting.default', …)` first, or it reads `enabled: false` and the test is asserting the wrong thing.

## See also

- [Testing helpers](helpers.md) and [test setup](setup.md)
- [Notifications: testing](../notifications/testing.md) — the endpoints, the shared props, the channel callback and the flash bridge
- [Toast notifications](../notifications/toast.md), [database notifications](../notifications/database.md)
- [Notification center](../notifications/notification-center.md), [broadcasting](../notifications/broadcasting.md)
- [Queued imports](../import-export/queued-imports.md) and [queued exports](../import-export/queued-exports.md)
