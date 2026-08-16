# Queued Imports

A file with more rows than `Importer::queueAfter()` is handed to `PandaPanel\Jobs\RunPanelImport` instead of being read in the request. The user is told the import has started, and the result — including a link to the rows that could not be imported — arrives as a notification when the worker is done.

Reach for this page when you are choosing the threshold, when a queued import is not arriving, or before you decide to retry one.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Imports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Actions\Imports\Importer;

final class ProductImporter extends Importer
{
    /**
     * @return class-string<Model>
     */
    public static function model(): string
    {
        return Product::class;
    }

    /**
     * @return list<ImportColumn>
     */
    public static function columns(): array
    {
        return [
            ImportColumn::make('sku')->required()->rules(['string', 'max:64']),
            ImportColumn::make('name')->required(),
        ];
    }

    /**
     * Anything over two hundred rows goes to a worker.
     */
    public static function queueAfter(): int
    {
        return 200;
    }
}
```

```bash
php artisan queue:work
```

## The threshold

The file is **read** to count its rows before the decision is made, never estimated — a guess would put a large file in the request or a small one behind a queue nobody is watching. For XLSX, that read still enforces the 64 MiB per-XML-part cap; queuing is not a bypass for a workbook that is too large to parse safely.

```php
$rows = ImportRun::countRows($local);   // the header is not a row

if ($importer::queueAfter() >= 0 && $rows > $importer::queueAfter()) {
    RunPanelImport::dispatch($importer, $stored, $mapping, $owner, $panel->getId());
}
```

| `queueAfter()` | Behaviour |
| --- | --- |
| `0` | always queued |
| `500` | the default — queued above 500 rows |
| any negative number | never queued, whatever the file holds |

The request then returns immediately with an informational toast:

```text
Your import has started. You will be notified when it finishes.
```

## The job

```php
use PandaPanel\Jobs\RunPanelImport;

new RunPanelImport(
    string $importer,   // class-string<Importer>
    string $path,       // where the uploaded file is
    array $mapping,     // array<string, int> — column name => position
    int|string $owner,  // the key of the user who uploaded it
    string $panelId,
);
```

The file is already on the disk by the time this runs — the upload put it there, and the job carries the path rather than the contents. A queue payload holding a spreadsheet would be a spreadsheet in the database.

`handle()`:

1. resolves the panel by id and makes it current, because the importer's model, its scopes and the panel's URLs are all read through it;
2. runs `ImportRun::run()` — the same reader the inline path uses;
3. deletes the uploaded file, because the upload was a means, not a record;
4. looks the owner up with `Auth::getProvider()->retrieveById()` and notifies.

## Retries: exactly one attempt

```php
public int $tries = 1;
```

Deliberately. An import writes rows; a run that failed halfway has already written some of them and there is no general way to know which — the importer decides what a row means, and only an importer keyed on something unique could be replayed safely. Retrying would turn one bad import into two, and the second failure would look exactly like the first.

A failure is therefore reported rather than retried: the user gets the report of what did land and re-uploads the rest. That is a worse automatic story and a much better manual one. Export is the opposite case and is configured the opposite way — see [Queued exports](queued-exports.md).

Row-level failures are not job failures. `ImportRun` collects them, so a file where four hundred rows are invalid still finishes successfully and produces a [failure report](failure-reports.md). The job only fails when the *file* cannot be read.

## What the user gets

On completion, always — unlike the inline path, which stays quiet when nothing failed:

```php
$notification = Notification::make('import-finished')
    ->title($importer::completedMessage($result['imported'], $result['failed']))
    ->icon($result['failed'] === 0 ? 'check' : 'triangle-alert')
    ->persistent();

$result['failed'] === 0
    ? $notification->success()
    : $notification->warning();

if ($result['report'] !== null) {
    $notification->actions([
        NotificationAction::make('failed-rows')
            ->label('Download failed rows')
            ->url(/* panel.{id}.import-file */),
    ]);
}

$notification->send($user);
```

The request that started the import is long gone, so there is nothing else to tell the user it finished. The report travels with the notification rather than being something to go looking for — it is the whole reason a partial import is acceptable.

On failure:

```php
Notification::make('import-failed')
    ->title('Import failed')
    ->body($exception?->getMessage() ?? 'The file could not be read.')
    ->danger()
    ->icon('triangle-alert')
    ->persistent()
    ->send($user);
```

`failed()` also deletes the uploaded file. Without that, a failed import would leave a copy of somebody's customer data on the disk that nothing would ever remove, and a failure is exactly when nobody is watching for it. The exception's own message is kept because a reader that says *"That file is not a readable spreadsheet."* is telling somebody how to fix their file.

An owner who no longer exists — an account deleted between upload and completion — means no notification and no error. That is a normal race, not a second failure to raise inside a failure handler.

## The path a queued import is given

This is the one place the inline and the queued paths are not identical, and it is worth understanding before you rely on queued imports.

| Path | What it is handed |
| --- | --- |
| inline | `Storage::disk($importer::disk())->path($stored)` — an absolute filesystem path |
| queued | `$stored` — the path **relative to the disk root**, as the upload returned it |

`ImportRun` opens what it is given with `fopen()` or `ZipArchive::open()`, so the queued reader resolves that relative path against the **worker process's working directory** rather than against the disk root. With the default `local` disk, whose root is `storage_path('app/private')`, `panel-imports/abc.csv` does not resolve from the project root: the read throws `SpreadsheetException`, the job fails, the upload is deleted, and the user is told *"Cannot read panel-imports/abc.csv."*

Until that difference is closed, there are two dependable options:

```php
// 1. Keep the read in the request, where the path is absolute.
public static function queueAfter(): int
{
    return -1;
}
```

```php
// 2. Dispatch the job yourself with an absolute path.
use Illuminate\Support\Facades\Storage;
use PandaPanel\Jobs\RunPanelImport;

RunPanelImport::dispatch(
    ProductImporter::class,
    Storage::disk(ProductImporter::disk())->path('panel-imports/prices.csv'),
    ['sku' => 0, 'name' => 1],
    $user->getKey(),
    'admin',
)->onQueue('imports');
```

With the second, the job's own `Storage::disk(…)->delete($path)` no longer matches a file on the disk, so remove the upload yourself once the import has finished.

Whichever you choose, run one end to end on staging before turning a threshold on in production. An import that silently becomes an "Import failed" notification is a bad way to find this out.

## Dispatching one yourself

The constructor is public, so a console command can queue an import of a file your application already has:

```php
use App\Panels\Admin\Resources\Products\Imports\ProductImporter;
use PandaPanel\Actions\Imports\ImportRun;
use PandaPanel\Jobs\RunPanelImport;

$path = storage_path('app/private/panel-imports/prices.csv');

RunPanelImport::dispatch(
    ProductImporter::class,
    $path,
    ImportRun::guessMapping(ProductImporter::class, ImportRun::headings($path)),
    $user->getKey(),
    'admin',
);
```

`RunPanelImport` uses Laravel's `Queueable` trait and names no connection or queue of its own, so it goes to the defaults unless you say otherwise. The panel id must be one the registry knows; an unknown id throws `PanelRegistrationException::unknownPanel()` inside the job.

## Testing

```php
use Illuminate\Support\Facades\Storage;
use PandaPanel\Jobs\RunPanelImport;

it('deletes the uploaded file when an import fails', function (): void {
    Storage::fake(UserImporter::disk());
    Storage::disk(UserImporter::disk())->put('imports/people.csv', "name\nAda\n");

    $job = new RunPanelImport(
        UserImporter::class,
        'imports/people.csv',
        ['name' => 0],
        $this->user->getKey(),
        'admin',
    );

    $job->failed(new RuntimeException('unsupported file format'));

    expect(Storage::disk(UserImporter::disk())->exists('imports/people.csv'))->toBeFalse();
});
```

```php
fakePanelNotifications();

$job->failed(new RuntimeException('column count mismatch on row 12'));

assertPanelNotificationSentTo($this->user, 'Import failed');
```

## Gotchas

- **A queued import needs a worker.** With `QUEUE_CONNECTION=sync` it runs inline — which sidesteps the path difference above, because `sync` runs in the same process with the same working directory as the request, but also defeats the point of the threshold.
- **One attempt, on purpose.** Do not raise `$tries` on this job by extending it. Rows that were written by the failed attempt would be written again.
- **The job binds the panel, not the tenant.** `Tenancy::bind()` happens in the `ResolveTenant` middleware, which a worker never runs. An importer whose `resolve()` queries a tenant-scoped model gets no tenant, and a `Resource::query()` on a tenant-scoped resource throws `PanelRegistrationException::noCurrentTenant()`. Wrap your own dispatch in `PandaPanel\Tenancy\Tenancy::for($tenant, …)` if you need one.
- **Mapping is decided in the request.** The job receives positions, not headings, so a file whose columns moved between upload and run does not re-map itself.
- **The upload is deleted either way.** Success and failure both remove it. If you need the original file kept, copy it somewhere else before dispatching.
- **A clean queued import still notifies.** That differs from the inline path deliberately: there is no response left to carry the news.

## See also

- [Importer classes](importers.md) — `queueAfter()`, `chunkSize()`, `completedMessage()`
- [ImportAction](import-action.md)
- [Failure reports](failure-reports.md)
- [Queued exports](queued-exports.md)
- [Import and export notifications](notifications.md)
- [Storage and cleanup](storage-cleanup.md)
- [Queues in production](../deployment/queues.md)
- [Testing notifications](../testing/notifications.md)
