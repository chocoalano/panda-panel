# Queued Notifications

A queued job has no response to ride back on. The request that started it finished minutes ago, and the user may be on another page, another panel, or nowhere at all. That is the case panel notifications exist for: persist the message so it can be found later, broadcast it so an open panel shows it now, and say something when the job fails rather than leaving somebody watching a bell that will never ring.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Notifications\Notification;
use Throwable;

final class RebuildSearchIndex implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int|string $owner) {}

    public function handle(): void
    {
        // … the work …

        $user = Auth::getProvider()->retrieveById($this->owner);

        if ($user === null) {
            return;
        }

        Notification::make('index-rebuilt')
            ->title('Search index rebuilt')
            ->body('4,102 records indexed.')
            ->success()
            ->persistent()
            ->send($user);
    }

    public function failed(?Throwable $exception): void
    {
        $user = Auth::getProvider()->retrieveById($this->owner);

        if ($user === null) {
            return;
        }

        Notification::make('index-failed')
            ->title('Search index rebuild failed')
            ->body($exception?->getMessage() ?? 'The index could not be rebuilt.')
            ->danger()
            ->persistent()
            ->send($user);
    }
}
```

```bash
php artisan queue:work
```

## Carry a key, resolve a user

Both of the package's own jobs take `int|string $owner` and resolve it on the other side:

```php
use Illuminate\Support\Facades\Auth;

$user = Auth::getProvider()->retrieveById($this->owner);

if ($user === null) {
    return;
}
```

A scalar rather than the model, for the same reason `RunPanelExport` carries a description of a query rather than a builder: a queue payload is data, and the smallest honest thing to put in it is the key. Resolving through the auth provider also gives the right answer for an application with a custom provider, and returns `null` for a user who was deleted between the dispatch and the run — which is a case to return on, not to crash on.

`Notification::send()` takes an `Authenticatable`, which is exactly what the provider returns.

## Two queues, not one

There are two separate hops, and both need a worker:

| Hop | What is queued | Configured by |
| --- | --- | --- |
| Your job | your `ShouldQueue` class | `queue.default`, the job's `$connection`/`$queue` |
| The broadcast | Laravel's `BroadcastEvent`, wrapping `PanelNotificationSent` | `queue.default`, unless the event names otherwise |

`PanelNotificationSent` and `PanelNotification` implement `ShouldBroadcast`, not `ShouldBroadcastNow`, so dispatching one pushes a job rather than talking to the broadcaster inline. With `QUEUE_CONNECTION=sync` — a common local setting, though Laravel's own default is `database` — both hops run immediately, which is why realtime notifications appear to work locally without a worker and stop working the moment the queue is real.

The database half is **not** queued: `PanelDatabaseNotification` does not implement `ShouldQueue`, so `->persistent()` writes its row inside the job that called `send()`. A notification is therefore stored even when no worker ever picks up the broadcast.

## Panel context inside a job

A resource's scope, its table and its URLs are all read through the *current* panel, and a queued job runs outside any request that resolved one. Both package jobs carry the panel id and set it before doing anything:

```php
use PandaPanel\Core\PanelManager;

public function handle(PanelManager $manager): void
{
    $panel = $manager->get($this->panelId);

    $manager->setCurrentPanel($panel);

    // … now Resource::query() and $panel->routeName() answer correctly …
}
```

Do the same in any job that builds a panel URL for a notification action:

```php
use PandaPanel\Notifications\NotificationAction;

NotificationAction::make('view')
    ->label('View import')
    ->url(route($panel->routeName('import-file'), [
        'file' => $result['report'],
        'importer' => $importer,
    ], absolute: false));
```

`absolute: false` matters more here than in a request: a worker's `APP_URL` is not always the host the user is browsing, and a relative URL cannot be wrong about it.

## The jobs the package ships

Two, and they are configured as opposites on purpose.

### `PandaPanel\Jobs\RunPanelExport`

| Property | Value | Why |
| --- | --- | --- |
| `$tries` | `3` | an export only reads rows and writes a file; a half-written file is replaced by the next attempt |
| `backoff()` | `[10, 60]` | the failures worth retrying need a moment, not three attempts against the same outage |

On success it sends a persistent notification with a Download action; on final failure, `failed()` sends a `danger` notification carrying the exception's message. Both are persistent, because the user asked for a file and a toast they were not there to see would leave them with nothing.

### `PandaPanel\Jobs\RunPanelImport`

| Property | Value | Why |
| --- | --- | --- |
| `$tries` | `1` | an import writes rows; a run that failed halfway has already written some, and there is no general way to know which |

On success it deletes the uploaded file, then notifies — `success` when nothing failed, `warning` when something did, with a "Download failed rows" action when there is a report. `failed()` deletes the file too and notifies with the exception's own message, because "unsupported file format" or "column count mismatch on row 12" tells somebody how to fix their file.

## When a run is queued at all

Neither action queues unconditionally. Each asks the exporter or importer:

```php
if ($exporter::queueAfter() >= 0 && $count > $exporter::queueAfter()) {
    RunPanelExport::dispatch(/* … */);

    return;
}
```

| Class | Method | Default |
| --- | --- | --- |
| `PandaPanel\Actions\Exports\Exporter` | `static queueAfter(): int` | `2000` records |
| `PandaPanel\Actions\Imports\Importer` | `static queueAfter(): int` | `500` rows |

```php
final class UserExporter extends Exporter
{
    public static function queueAfter(): int
    {
        return 0;      // always queue
    }
}

final class SmallImporter extends Importer
{
    public static function queueAfter(): int
    {
        return -1;     // never queue: the >= 0 guard skips the branch entirely
    }
}
```

A number rather than a flag: a small export in a background job is a worse experience than the wait it avoided, and a large one in a request is a timeout.

The synchronous branch notifies differently — it persists with `->broadcast(false)` and flashes the toast onto the response it is already returning, because pushing the same message over a websocket would show it twice. See [Flash toast bridge](flash-bridge.md).

## Mail from a queue

One notification in the package goes to an inbox rather than a panel: `PandaPanel\Notifications\TwoFactorCode`, sent to an account that has emailed sign-in codes turned on.

```php
final class TwoFactorCode extends LaravelNotification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array   // ['mail']
    public function toMail(object $notifiable): MailMessage
}
```

It is `ShouldQueue` because sending it is the slowest part of signing in and nobody should watch an SMTP handshake. The code is already in the cache by the time it is dispatched, so a delayed mail delays the login rather than breaking it. It is deliberately not a panel notification: a credential going to one address is not something to persist in a bell somebody else might open.

## Gotchas

- **No worker, no toast.** The row is written, the badge is right on the next navigation, and the websocket stays silent — the `BroadcastEvent` job is still in the queue. This is the most common "broadcasting is broken" report.
- **`failed()` runs after the last attempt, not each one.** For `RunPanelExport` that means three attempts before the user hears anything, which is the intended trade.
- **`failed()` is not called for a job that was never dispatched.** A `dispatchSync()` that throws propagates instead; catch it yourself if the user should be told.
- **A deleted user silently ends the job.** `retrieveById()` returns `null` and both package jobs return. Keep that guard: `send()` would fatal on `null`.
- **Do not queue the panel `Notification` itself.** It is not a Laravel notification and has no `ShouldQueue` behaviour of its own; it is a builder that dispatches an event and writes a row. Queue the work around it.
- **`->persistent()` is what survives a missing worker.** A broadcast-only notification sent from a job is lost if the browser is closed or the queue is backed up. Persist anything a user would miss.

## See also

- [Database notifications](database.md) — the row a queued job leaves behind
- [Broadcasting](broadcasting.md) — the second queue hop
- [Notification actions](actions.md) — links a job attaches
- [Queued exports](../import-export/queued-exports.md) and [queued imports](../import-export/queued-imports.md)
- [Failure reports](../import-export/failure-reports.md)
- [Queues in production](../deployment/queues.md)
- [Testing notifications](testing.md)
