# Queued Exports

An export above `Exporter::queueAfter()` records is handed to `PandaPanel\Jobs\RunPanelExport` instead of being written in the request. The request returns straight away, and the finished file arrives as a notification carrying a download link.

Reach for this page when you are deciding where the threshold should sit, when a queued export is not arriving, or when you want to produce one from a console command rather than from a button.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Exports;

use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class OrderExporter extends Exporter
{
    /**
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('reference'),
            ExportColumn::make('total'),
        ];
    }

    /**
     * Anything over a thousand orders goes to a worker.
     */
    public static function queueAfter(): int
    {
        return 1000;
    }
}
```

```bash
php artisan queue:work
```

Nothing else changes. The same action, the same dialog, the same `ExportRun` — only the process that runs it differs.

## The threshold

`ExportAction` counts the records **before** anything is written: `count()` on the constrained query for the table export, `count($keys)` for a bulk one.

```php
if ($exporter::queueAfter() >= 0 && $count > $exporter::queueAfter()) {
    RunPanelExport::dispatch(/* … */);

    return;
}
```

| `queueAfter()` | Behaviour |
| --- | --- |
| `0` | always queued |
| `2000` | the default — queued above 2000 records |
| any negative number | never queued, whatever the count |

A number rather than a flag, because both extremes are bad: a small export in a background job is a worse experience than the wait it avoided, and a large one in a request is a timeout. Where the line sits depends on your columns and your database, not on a rule — an export with six scalar columns is far cheaper per record than one that walks two relations.

## The job

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Jobs\RunPanelExport;

new RunPanelExport(
    string $exporter,             // class-string<Exporter>
    string $resource,             // class-string<PandaPanel\Resources\Resource>
    array $columns,               // list<string>, the chosen column names
    SpreadsheetFormat $format,
    int|string $owner,            // the key of the user the file belongs to
    array $tableState,            // the query string the list was showing
    ?array $keys,                 // an explicit selection, or null for the whole list
    string $panelId,
);
```

Everything it carries is a scalar or a plain array, and that is not a style choice: an Eloquent builder holds a closure and cannot be serialized, so a job that took "the query" would fail the moment it was queued. It takes the *description* of a query instead and rebuilds it on the other side.

`handle()` does four things:

1. resolves the panel by id and makes it current — a resource's scope, its table and its URLs are all read through the current panel, and without this the job would run outside any panel;
2. rebuilds the query: `whereKey($keys)` for a selection, otherwise the resource's query constrained by `PandaPanel\Tables\TableQuery` over a `Request::create('/', 'GET', $tableState)`;
3. writes the file with `ExportRun::write()` — the same writer the inline path uses;
4. looks the owner up with `Auth::getProvider()->retrieveById()` and sends the notification.

Rebuilding through the same `TableQuery` the list uses is what keeps the file honest: the rows in it are the rows that were on screen, filters and search included, rather than everything the resource can see.

## Retries

```php
public int $tries = 3;

public function backoff(): array
{
    return [10, 60];
}
```

An export only reads rows and writes a file, so a run that failed halfway has changed nothing anybody can see — the half-written file is replaced by the next attempt. That makes the usual causes worth retrying: a database connection that dropped, a disk that was briefly unreachable, a worker restarted mid-run. Backing off rather than hammering, because three attempts a second apart is three attempts against the same outage.

Import is the opposite case and is configured the opposite way — see [Queued imports](queued-imports.md).

## What the user gets

On success, a persistent notification that is also broadcast:

```php
Notification::make('export-ready')
    ->title($exporter::completedMessage($result['records']))
    ->success()
    ->icon('download')
    ->persistent()
    ->actions([
        NotificationAction::make('download')->label('Download')->url(/* panel.{id}.export-file */),
    ])
    ->send($user);
```

Persistent because a file is the point: a toast that appeared while the user was on another tab would leave them with a finished export and no way to find it. Unlike the inline path, this one **is** broadcast — there is no response to carry it.

After the last attempt fails:

```php
Notification::make('export-failed')
    ->title('Export failed')
    ->body($exception?->getMessage() ?? 'The file could not be written.')
    ->danger()
    ->icon('triangle-alert')
    ->persistent()
    ->send($user);
```

An export that fails silently is worse than one that fails loudly: the user asked for a file, was told it was being prepared, and is now watching a bell that will never ring.

If the owner no longer exists — an account deleted between the request and the run — both paths return without sending anything. That is a normal race, not a second error to raise inside a failure handler.

See [Import and export notifications](notifications.md).

## Dispatching one yourself

The constructor is public, so a console command or a scheduled task can queue an export without a panel screen:

```php
use App\Panels\Admin\Resources\Orders\Exports\OrderExporter;
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Jobs\RunPanelExport;

RunPanelExport::dispatch(
    OrderExporter::class,
    OrderResource::class,
    ['reference', 'total'],
    SpreadsheetFormat::Xlsx,
    $user->getKey(),
    ['filters' => ['status' => 'shipped']],   // as the list's query string would spell it
    null,                                     // or a list of keys for an explicit selection
    'admin',                                  // the panel id
)->onQueue('reports');
```

`RunPanelExport` uses Laravel's `Queueable` trait and names no connection or queue of its own, so it goes to the default ones unless you say otherwise. Dispatching it yourself is also how you put panel exports on a dedicated queue — the action always dispatches to the default.

The panel id must be one the registry knows; an unknown id throws `PanelRegistrationException::unknownPanel()` inside the job.

## Testing

```php
use Illuminate\Support\Facades\Queue;
use PandaPanel\Jobs\RunPanelExport;

it('queues an export above the threshold', function (): void {
    Queue::fake();

    // … trigger the action …

    Queue::assertPushed(RunPanelExport::class);
});
```

To assert on the notification instead, run the job and use the package's own helpers:

```php
fakePanelNotifications();

$job = new RunPanelExport(
    UserExporter::class,
    UserResource::class,
    ['name'],
    SpreadsheetFormat::Csv,
    $user->getKey(),
    [],
    null,
    'admin',
);

$job->failed(new RuntimeException('the disk went away'));

assertPanelNotificationSentTo($user, 'Export failed');
```

## Gotchas

- **A queued export needs a worker.** With `QUEUE_CONNECTION=sync` the job runs inline, which defeats the threshold but still produces the file. With a real connection and no worker running, nothing arrives and nothing errors.
- **The success flash still says the export is ready.** The action endpoint redirects `back()->with('success', $action->getSuccessMessage())`, and the queued path flashes no toast of its own, so the default `Your export is ready.` is shown at the moment the job is queued. Override it on an exporter that queues: `->successMessage('Preparing your export. You will be notified when it is ready.')`.
- **The table state is a snapshot.** The job re-runs the query when the worker picks it up, so records that changed in between are exported as they are then, not as they were when the button was pressed.
- **Route URLs are built inside the job.** The notification's link comes from `route($panel->routeName('export-file'), …, absolute: false)`, so a worker with a misconfigured `APP_URL` still produces a usable relative path.
- **Retries rewrite the same file name** when `Exporter::fileName()` is timestamped to the second and the attempts fall in the same second. That is harmless — the content is identical — but a `fileName()` with no timestamp at all means the previous export is replaced.
- **The job binds the panel, not the tenant.** `Tenancy::bind()` is called by the `ResolveTenant` middleware, which a worker never runs, so `Resource::query()` on a tenant-scoped resource reaches `Tenancy::require()` with nothing bound and throws `PanelRegistrationException::noCurrentTenant()`. On a tenant-scoped panel, keep `queueAfter()` negative so exports run in the request, or dispatch your own job that wraps the work in `PandaPanel\Tenancy\Tenancy::for($tenant, …)`.

## See also

- [Exporter classes](exporters.md) — `queueAfter()`, `chunkSize()`, `completedMessage()`
- [ExportAction](export-action.md)
- [Queued imports](queued-imports.md)
- [Import and export notifications](notifications.md)
- [Storage and cleanup](storage-cleanup.md)
- [Notification queues](../notifications/queues.md)
- [Queues in production](../deployment/queues.md)
- [Testing notifications](../testing/notifications.md)
