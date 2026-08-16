# Database Notifications

A persistent notification is a row the user can find later. Reach for one whenever the message outlives the request that produced it — a queued export that finished, an import that partially failed, anything a user who was away still needs to see. The panel stores it through Laravel's own `notifications` table rather than one of its own, so `unreadNotifications`, `markAsRead()` and the rest work without a line of code.

## A minimal working example

```php
<?php

use PandaPanel\Notifications\Notification;

Notification::make('export-ready')
    ->title('Your export is ready')
    ->body('1,204 records')
    ->success()
    ->persistent()
    ->send($user);
```

One row in `notifications`, one toast in any panel the user has open, and a bell that now reads 1.

## What `persistent()` does

```php
public function persistent(bool $persistent = true): self
```

Off by default. Inside `send()` it is one branch:

```php
if ($this->persistent && method_exists($user, 'notify')) {
    $user->notify(new PanelDatabaseNotification($this->toArray()));
}
```

`method_exists()` rather than a type check: what matters is whether this notifiable can be notified, and a user model without the trait has nowhere to store one — the toast still goes out.

The order inside `send()` is persist, then broadcast. A user who clicks the toast's action and lands on the notification centre must find the row already there.

## `PanelDatabaseNotification`

`PandaPanel\Notifications\PanelDatabaseNotification` extends `Illuminate\Notifications\Notification` and does two things:

```php
public function via(object $notifiable): array     // ['database']
public function toArray(object $notifiable): array // the payload, unchanged
```

Broadcasting is deliberately **not** on `via()`. The panel pushes its own event carrying the same payload, and the bell refetches when that event says a row was written — one subscription, and no second event to disagree with the first.

The `data` column therefore holds exactly `Notification::toArray()`:

```php
[
    'name' => 'export-ready',
    'title' => 'Your export is ready',
    'body' => '1,204 records',
    'color' => 'success',
    'icon' => 'download',
    'actions' => [
        ['name' => 'download', 'label' => 'Download', 'url' => '/admin/exports/users.csv',
         'variant' => 'outline', 'markAsRead' => true, 'newTab' => false],
    ],
    'type' => 'success',
    'persistent' => true,
]
```

## The user model

The notification centre needs a user model that is `Notifiable`. `PandaPanel\Contracts\PanelNotifiable` names that requirement:

```php
namespace PandaPanel\Contracts;

interface PanelNotifiable
{
    public function notifications();        // MorphMany<DatabaseNotification, Model>
    public function unreadNotifications();  // MorphMany<DatabaseNotification, Model>
    public function notify($instance);      // void
}
```

Nothing has to implement it. Laravel's own trait already provides every method, and the controller accepts a model that merely uses the trait:

```php
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable
{
    use Notifiable;
}
```

The interface exists to be written down: "the user model must be `Notifiable`" is a requirement the panel has either way, and an interface makes it one static analysis can see rather than one discovered when the bell throws. Implement it if you want PHPStan to check it; the runtime does not care.

## The table

The package ships `database/migrations/2026_08_14_130919_create_notifications_table.php`, which is Laravel's own schema:

| Column | Type |
| --- | --- |
| `id` | `uuid`, primary |
| `type` | `string` |
| `notifiable_type`, `notifiable_id` | `morphs` |
| `data` | `text` |
| `read_at` | `timestamp`, nullable |
| `created_at`, `updated_at` | `timestamps` |

with `index(['notifiable_type', 'notifiable_id', 'read_at'])` — the only two questions ever asked of this table are "what does this user have" and "how many has this user not read".

```bash
php artisan migrate
```

`up()` returns early when the table already exists, because most applications already have it and a package migration that assumed otherwise would fail the first `migrate` after install.

`down()` is asymmetric on purpose. It calls `PandaPanel\Support\PackageSchema::dropIfOwned()`, which drops the table only when no other ran migration is named `*_create_notifications_table` **and** the column list is exactly the one `up()` creates. Anything less than a clear yes leaves the table standing: an empty table nobody drops costs nothing, and the other mistake costs an application's notifications.

## Reading them back

Ordinary Eloquent, because they are ordinary Laravel notifications:

```php
$user->notifications;                    // newest first, all of them
$user->unreadNotifications;              // read_at is null
$user->unreadNotifications()->count();

$user->notifications()->first()?->markAsRead();
$user->unreadNotifications->markAsRead();   // the collection method

$user->notifications()->first()?->data['title'];
```

The panel's own reads are two: the notification centre's `index` endpoint (newest 30) and the unread count that `SharePanelData` puts on every panel request. See [Notification center](notification-center.md).

## Notifications sent by the rest of the application

Being Laravel's table rather than the panel's has one consequence worth planning for: **any** notification your application delivers on the `database` channel shows up in the panel's bell.

```php
use Illuminate\Notifications\Notification as LaravelNotification;

final class InvoiceOverdue extends LaravelNotification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The keys the bell reads. Anything else in here is stored and ignored.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Invoice overdue',
            'body' => 'INV-2043 was due on Tuesday.',
            'color' => 'warning',
            'icon' => 'triangle-alert',
            'actions' => [],
        ];
    }
}
```

The controller narrows a stored row to `id`, `title`, `body`, `color`, `icon`, `actions`, `read` and `createdAt`. A row with no `title` renders an empty title; a `color` outside the four falls back to `info`; an action that does not parse is dropped. Nothing throws.

## Gotchas

- **A persistent notification is still broadcast.** `->persistent()` adds the row; it does not remove the toast. Use `->persistent()->broadcast(false)` when the response already carries the message, which is what `ExportAction` does.
- **`send()` writes nothing for a model without `notify()`.** No exception, no row, and the toast still goes out. If a persistent notification never appears in the bell, check the trait first.
- **No migration, no crash.** `SharePanelData::unreadCount()` catches `QueryException` and answers 0, so a panel installed before `migrate` shows an empty bell rather than a 500 on every page.
- **The bell holds 30.** `PanelNotificationController::LIMIT` is a private constant, not configuration. Rows past that are still in the table and still reachable with Eloquent; they are not in the centre.
- **Nothing prunes the table.** A notification lives until somebody clears it or you delete it. `$user->notifications()->where('created_at', '<', now()->subMonths(3))->delete()` in a scheduled command is the usual answer.

## See also

- [Notification center](notification-center.md) — the bell, its endpoints and its limits
- [Notification actions](actions.md) — the buttons stored beside a row
- [Toast notifications](toast.md) — the transient half
- [Queued notifications](queues.md) — where most persistent notifications come from
- [Migrations configuration](../configuration/migrations.md)
- [Testing notifications](testing.md)
