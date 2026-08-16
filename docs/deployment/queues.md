# Queues

The panel dispatches work to a queue in three places: large exports, large
imports, and outbound integration deliveries. Two more things ride the queue
without being panel jobs — every realtime notification, because `ShouldBroadcast`
events are queued, and the emailed sign-in code, which is `ShouldQueue`. Reach
for this page when setting up workers, when an export never arrives, or when a
toast fires locally and not in production.

## A minimal working example

```bash
# .env
QUEUE_CONNECTION=database
```

```bash
php artisan make:queue-table && php artisan migrate   # only if this app has no jobs table
php artisan queue:work --queue=default --timeout=900 --max-time=3600
```

And on every deploy:

```bash
php artisan queue:restart
```

Do not pass `--tries` on the worker. Every panel job declares its own `$tries`
for a reason specific to that job, and a job's property is what applies — the
worker option is only the fallback for jobs that state nothing.

## What actually queues

| Work | Job | Queued when |
| --- | --- | --- |
| Export | `PandaPanel\Jobs\RunPanelExport` | the record count exceeds `Exporter::queueAfter()` |
| Import | `PandaPanel\Jobs\RunPanelImport` | the row count exceeds `Importer::queueAfter()` |
| Integration delivery | `PandaPanel\Jobs\SendPanelIntegration` | the trigger is an `after_*` one |
| A broadcast notification | Laravel's `BroadcastEvent`, wrapping `PanelNotificationSent` | always, since the event is `ShouldBroadcast` |
| The emailed sign-in code | `PandaPanel\Notifications\TwoFactorCode` | always, since it is `ShouldQueue` |

Nothing else. Table rendering, form submission, authorization and navigation are
all synchronous, and no part of a panel page waits on a worker.

### Neither export nor import queues unconditionally

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
use PandaPanel\Actions\Exports\Exporter;

final class UserExporter extends Exporter
{
    public static function queueAfter(): int
    {
        return 0;      // always queue
    }
}
```

Return `-1` to never queue: the `>= 0` guard skips the branch entirely, and
everything runs inside the request. A number rather than a flag, because a small
export in a background job is a worse experience than the wait it avoided, and a
large one in a request is a timeout.

The count is taken before anything is written, so the decision is made on the
real size rather than on a guess.

## The jobs, in full

### `RunPanelExport`

```php
use PandaPanel\Jobs\RunPanelExport;

RunPanelExport::dispatch(
    $exporter,     // class-string<Exporter>
    $resource,     // class-string<Resource>
    $columns,      // list<string>
    $format,       // PandaPanel\Actions\Enums\SpreadsheetFormat
    $owner,        // int|string — the user's auth identifier
    $tableState,   // array<string, mixed> — the query string the list was showing
    $keys,         // list<int|string>|null — an explicit selection, or null for the whole list
    $panelId,      // string
);
```

| Property | Value | Why |
| --- | --- | --- |
| `$tries` | `3` | an export only reads rows and writes a file; a half-written file is replaced by the next attempt |
| `backoff()` | `[10, 60]` | the failures worth retrying need a moment — three attempts a second apart is three attempts against the same outage |

Everything it carries is a scalar or a plain array, because an Eloquent builder
holds a closure and cannot be serialized. It carries the *description* of a
query — the resource, the table state, or an explicit selection — and rebuilds it
through the same `TableQuery` the list uses, so the rows in the file are the rows
that were on screen, filters and search included.

On success it sends a persistent notification with a Download action. On final
failure, `failed(?Throwable $exception): void` sends a `danger` notification
carrying the exception's message, because a user who asked for a file and was
told it was being prepared is otherwise watching a bell that will never ring.

### `RunPanelImport`

```php
use PandaPanel\Jobs\RunPanelImport;

RunPanelImport::dispatch(
    $importer,     // class-string<Importer>
    $path,         // string — the uploaded file, already on the disk
    $mapping,      // array<string, int>
    $owner,        // int|string
    $panelId,      // string
);
```

| Property | Value | Why |
| --- | --- | --- |
| `$tries` | `1` | an import writes rows; a run that failed halfway has already written some, and there is no general way to know which |

Retrying would turn one bad import into two, and the second failure would look
exactly like the first. A failure is reported instead: the user gets the report
of what did land and re-uploads the rest.

Both `handle()` and `failed()` delete the uploaded file. The upload was a means,
not a record — keeping it would accumulate copies of customer data nobody asked
to store.

### `SendPanelIntegration`

```php
use PandaPanel\Jobs\SendPanelIntegration;

SendPanelIntegration::dispatch(
    $integrationId,   // int
    $payload,         // array<string, mixed>
    $timeout,         // int — the HTTP timeout for the outbound request
    $deliveryId,      // string|null
);
```

| Property | Value |
| --- | --- |
| `$tries` | `3` |
| `backoff()` | `[10, 60]` |

Only `after_*` triggers are queued. A `before_*` trigger describes the record as
it is about to be written, and by the time a worker picked it up that state would
be gone — so those are sent inline, with a short timeout and their failures
swallowed by the dispatcher.

The payload travels as an array rather than a serialized model, because the
record may not exist any more by the time this runs — `after_delete` is precisely
that case, and `SerializesModels` would try to reload it and throw.

The `$timeout` constructor argument is the **HTTP** timeout for the outbound
request. It is not the job timeout; see below.

## Panel context inside a job

A resource's scope, its table and its URLs are all read through the *current*
panel, and a queued job runs outside any request that resolved one. Both file
jobs carry the panel id and set it first:

```php
use PandaPanel\Core\PanelManager;

public function handle(PanelManager $manager): void
{
    $panel = $manager->get($this->panelId);

    $manager->setCurrentPanel($panel);

    // … Resource::query() and $panel->routeName() now answer correctly …
}
```

Do the same in any job of your own that queries a panel resource or builds a
panel URL. Without it `Resource::query()` answers for no panel at all.

Tenant-scoped work has to enter a tenant explicitly:

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($tenant, fn () => InvoiceResource::query()->count());
```

A scoped resource asked outside a tenant raises rather than running unscoped,
which is the whole point: an unscoped query would return every tenant's records
and look like a working page.

## Resolving the user

```php
use Illuminate\Support\Facades\Auth;

$user = Auth::getProvider()->retrieveById($this->owner);

if ($user === null) {
    return;
}
```

A scalar key rather than the model, for the same reason the export carries a
description of a query rather than a builder: a queue payload is data. Resolving
through the auth provider gives the right answer for a custom provider, and
returns `null` for a user deleted between the dispatch and the run — which is a
case to return on, not to crash on.

## Two hops, not one

A notification sent from a job needs **two** workers' worth of work:

| Hop | What is queued | Configured by |
| --- | --- | --- |
| Your job | the `ShouldQueue` class | `queue.default`, the job's `$connection` / `$queue` |
| The broadcast | Laravel's `BroadcastEvent` wrapping `PanelNotificationSent` | `queue.default`, unless the event names otherwise |

`PanelNotificationSent` and `PandaPanel\Broadcasting\PanelNotification` implement
`ShouldBroadcast`, not `ShouldBroadcastNow`, so dispatching one pushes a job
rather than talking to the broadcaster inline. With `QUEUE_CONNECTION=sync` both
hops run immediately — which is exactly why realtime notifications appear to work
locally and stop working the moment the queue is real.

The database half is not queued: `PanelDatabaseNotification` is not
`ShouldQueue`, so `->persistent()` writes its row inside the job that called
`send()`. A notification is therefore stored even when no worker ever picks up
the broadcast.

## Worker configuration

Nothing here is panel-specific, but three settings interact with the panel's jobs
in ways worth stating.

### Timeout

Neither `RunPanelExport` nor `RunPanelImport` declares a `$timeout`, so the
worker's applies — 60 seconds by default. An export of a hundred thousand rows
does not finish in 60 seconds.

```bash
php artisan queue:work --timeout=900
```

Or per job, in your own subclass of the work rather than in the package's:
raise `Exporter::chunkSize()` (default `500`) and `Importer::chunkSize()`
(default `200`) only if memory allows, and prefer a longer worker timeout to a
larger chunk.

### Restarting

```bash
php artisan queue:restart
```

Last step of a deploy. A worker holds the code it booted with, including the
panel manifest it loaded; one that started before the deploy is running the
previous release against the new database.

### Supervisor

```ini
[program:panel-worker]
command=php /var/www/current/artisan queue:work --queue=default --timeout=900 --max-time=3600
directory=/var/www/current
autostart=true
autorestart=true
stopwaitsecs=3600
numprocs=2
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/panel-worker.log
```

`stopwaitsecs` above the longest export, so a restart does not kill a run
halfway. `--max-time` recycles the process on a schedule, which is the cheap
answer to any memory a long-running PHP process accumulates.

### A separate queue for exports

Exports are slow and integrations are latency-sensitive. Splitting them keeps one
from waiting behind the other:

```php
use PandaPanel\Jobs\RunPanelExport;

RunPanelExport::dispatch(/* … */)->onQueue('exports');
```

```bash
php artisan queue:work --queue=integrations,default
php artisan queue:work --queue=exports
```

The package's actions dispatch onto the default queue. Routing them elsewhere
means dispatching the job yourself from a custom action, or configuring the
connection's queue — the jobs declare no `$queue` of their own.

## Failures

```bash
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
```

| Job | On final failure |
| --- | --- |
| `RunPanelExport` | `failed()` notifies the owner with the exception's message, persistently |
| `RunPanelImport` | `failed()` deletes the upload and notifies with the exception's message |
| `SendPanelIntegration` | nothing user-facing; the attempt is recorded in the delivery history |

The exception's own message is used rather than a generic sentence, because
"unsupported file format" or "column count mismatch on row 12" tells somebody how
to fix their file.

`failed()` runs after the last attempt, not each one. For `RunPanelExport` that
means three attempts before the user hears anything, which is the intended trade.

## Gotchas

- **No worker, no toast.** The notification row is written and the bell count is
  right on the next navigation, but the websocket stays silent because the
  `BroadcastEvent` job is still queued. This is the most common "broadcasting is
  broken" report.
- **`QUEUE_CONNECTION=sync` in production** turns every queued export into a web
  request that will eventually time out.
- **A worker that did not restart is running the previous release**, including
  the previous panel manifest. Every deploy ends with `queue:restart`.
- **`SendPanelIntegration`'s `$timeout` is the HTTP timeout**, not the job
  timeout. The job timeout is the worker's.
- **A deleted user silently ends the job.** `retrieveById()` returns `null` and
  the package's jobs return. Keep that guard in your own: `send()` would fatal on
  `null`.
- **Queued work is outside a request, and therefore outside the panel and the
  tenant.** Set both explicitly.
- **`->persistent()` is what survives a missing worker.** A broadcast-only
  notification sent from a job is lost if the browser is closed or the queue is
  backed up.

## See also

- [Production checklist](production-checklist.md), [Broadcasting server](broadcasting.md), [Monitoring](monitoring.md)
- [Storage setup](storage.md) — where export and import files land
- [Queued notifications](../notifications/queues.md) — the same jobs from the notification side
- [Queued exports](../import-export/queued-exports.md), [Queued imports](../import-export/queued-imports.md), [Failure reports](../import-export/failure-reports.md)
- [Tenancy concepts](../tenancy/concepts.md)
- [Octane](octane.md) — the other long-lived process
