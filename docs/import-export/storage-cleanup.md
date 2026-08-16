# Storage And Cleanup

Imports and exports put four kinds of file on disk: the spreadsheet an export produced, the spreadsheet a user uploaded, the failure report an import wrote, and the temporary files both use while assembling one. Some of them are deleted for you and some are not, and the difference matters — an export is a copy of records somebody was allowed to see, and a failure report is a copy of the data they tried to import.

Reach for this page when you are choosing disks, hardening a deployment, or writing the pruning job the package deliberately does not ship.

## A minimal working example

The defaults, spelled out:

```php
use PandaPanel\Actions\Exports\Exporter;
use PandaPanel\Actions\Imports\Importer;

Exporter::disk();        // 'local'
Exporter::directory();   // 'panel-exports'

Importer::disk();        // 'local'
Importer::directory();   // 'panel-imports'
```

On a stock Laravel 12/13 application the `local` disk is rooted at `storage_path('app/private')`, so a finished export for user 7 is:

```text
storage/app/private/panel-exports/7/users-2026-08-15.csv
```

Nothing is served from the web root, and nothing has a public URL.

## Where each file lands

| File | Path | Written by |
| --- | --- | --- |
| export | `{Exporter::directory()}/{ownerKey}/{fileName}.{ext}` | `ExportRun::write()` |
| import upload | `{Importer::directory()}/{random}.{ext}` | the panel's upload endpoint |
| failure report | `{Importer::directory()}/{ownerKey}/failed-rows-{Y-m-d-His}.csv` | `ImportRun::run()` |
| temporary export | `sys_get_temp_dir()/panel-export-*` | `ExportRun::write()` |
| temporary report | `sys_get_temp_dir()/panel-import-failures-*` | `ImportRun::run()` |

The owner segment is the authenticated user's `getAuthIdentifier()`. It is not decoration: the download endpoints build that segment from **whoever is asking** rather than from the request, so one user cannot name another's file however they spell the path. The upload has no owner segment because it is short-lived and is never served back.

## Why the disk is private

`Exporter::disk()` returns `local` rather than `public`, and it should stay that way. An export is a copy of records somebody was allowed to see. A public disk would put that at a URL anybody can guess, and an export is exactly the kind of file worth guessing at. The download goes through the panel instead, which asks the question again:

```php
// PanelExportController, in outline
abort_if($user === null, 403);
abort_if($file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..'), 404);
abort_unless(is_subclass_of($exporter, Exporter::class), 404);

$path = $exporter::directory().'/'.$user->getAuthIdentifier().'/'.$file;

abort_unless($disk->exists($path), 404);

return $disk->download($path, $file);
```

The caller names a file, never a path, so a traversal has nowhere to go. `PanelImportController` applies the same rules to failure reports.

## Choosing another disk

```php
// config/filesystems.php
'disks' => [
    'panel-files' => [
        'driver' => 'local',
        'root' => storage_path('app/panel-files'),
        'throw' => false,
    ],
],
```

```php
final class OrderExporter extends Exporter
{
    public static function disk(): string
    {
        return 'panel-files';
    }

    public static function directory(): string
    {
        return 'exports/orders';
    }
}
```

Two constraints, and only one of them is a preference:

- **An exporter may use a remote disk.** `ExportRun` writes to a local temporary file and then streams it onto the target with `Storage::disk(...)->put()`, and the download endpoint uses `$disk->download()`. Both work on S3. Keep the bucket private.
- **An importer may not.** The reader is handed `Storage::disk($importer::disk())->path($stored)` and opens it with `fopen()` or `ZipArchive`, so the disk's `path()` must return a readable filesystem path. A remote driver cannot be read from.

Never point either at the `public` disk.

## What is deleted for you

| File | Deleted |
| --- | --- |
| import upload, inline run | after `ImportRun::run()` returns |
| import upload, required column missing | immediately, before the `ValidationException` is thrown |
| import upload, queued run | by `RunPanelImport::handle()` on success |
| import upload, queued failure | by `RunPanelImport::failed()` |
| temporary export file | after it has been streamed onto the disk |
| temporary report file | after it has been streamed onto the disk |

The upload is deleted in every one of those cases because it was a means, not a record. Keeping it would accumulate copies of customer data nobody asked to store, and a failure is exactly when nobody is watching for it.

## What is not

| File | Lifetime |
| --- | --- |
| every finished export | forever |
| every failure report | forever |
| an upload whose dialog was abandoned after the file was chosen | forever |
| a temporary file left behind when a write threw partway | until the system clears its temp directory |

There is **no** `panel:prune` command and nothing scheduled. That is a gap you have to fill in any application that exports regularly: an admin panel exporting a daily user list writes 365 files a year per person who asks for one.

## Writing the pruning job

An ordinary command over the disk. The layout is fixed and shallow, so this is all it takes:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Exports\Exporter;
use PandaPanel\Actions\Imports\Importer;

final class PrunePanelFiles extends Command
{
    protected $signature = 'panel:prune-files {--days=7}';

    protected $description = 'Delete panel exports, failure reports and abandoned uploads older than N days.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'))->getTimestamp();

        $targets = [
            [Exporter::disk(), Exporter::directory()],
            [Importer::disk(), Importer::directory()],
        ];

        $deleted = 0;

        foreach ($targets as [$disk, $directory]) {
            $filesystem = Storage::disk($disk);

            // allFiles() is recursive, which covers both the per-user
            // directories and the uploads sitting at the top level.
            foreach ($filesystem->allFiles($directory) as $path) {
                if ($filesystem->lastModified($path) >= $cutoff) {
                    continue;
                }

                $filesystem->delete($path);
                $deleted++;
            }
        }

        $this->info(sprintf('Deleted %d file(s).', $deleted));

        return self::SUCCESS;
    }
}
```

Use your own exporter and importer classes if they override `disk()` or `directory()` — the abstract classes above only give you the defaults.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('panel:prune-files --days=7')->daily();
```

Seven days is a reasonable starting point: long enough that a link in last week's notification still works, short enough that nothing accumulates. A notification whose file has been pruned answers a 404 from the download endpoint, which is the correct outcome — the row is history, the file is not.

## Retention and privacy

An export contains whatever the exporter declared, for whichever records the list was showing. A failure report contains rows a person tried to import, including whatever was wrong with them. Both are personal data in most applications, and both survive the record being deleted from the database. Whatever retention period the rest of your data has, these files should not outlive it — which is another way of saying the pruning job above is not optional.

Two things reduce the surface before retention even comes up:

- Declare fewer columns. An exporter's column list is the whole surface, and a column you do not declare cannot be exported by anybody.
- Do not import what you do not need. A password column in an importer means a password in a spreadsheet on somebody's laptop.

## Testing

```php
use Illuminate\Support\Facades\Storage;

it('files an export under the user it belongs to', function (): void {
    Storage::fake('local');

    $result = ExportRun::write(UserExporter::class, User::query(), [], SpreadsheetFormat::Csv, 42);

    expect($result['path'])->toStartWith('panel-exports/42/');
});
```

`Storage::fake()` swaps the disk for a temporary one, so a test asserting on written files does not touch `storage/app`. The failure report is asserted the same way:

```php
$report = (string) Storage::disk('local')->get('panel-imports/5/'.$result['report']);

expect($report)->toContain('Error');
```

## Notes

- **The owner directory is not created by anything but a write.** A user who never exported has no directory, and the download endpoint's `exists()` check answers 404 rather than an error.
- **Two exporters may share a directory.** The file name is what distinguishes them, so `Exporter::fileName()` should be distinctive if they do.
- **`Exporter::fileName()` with no timestamp overwrites.** That is a legitimate choice for "the current list" and a bad one for anything you need a history of.
- **`ExportRun` needs a writable system temp directory.** `tempnam()` failing throws `RuntimeException('Cannot create a temporary file for the export.')`; a read-only or full `/tmp` is the usual cause.
- **A failed write can leave a temporary file behind.** `unlink()` runs after the stream has been put on the disk, so an exception in between leaves the `panel-export-*` file for the system to clean up.
- **Nothing here is configurable in `config/panda-panel.php`.** Disks and directories live on the exporter and importer classes, where the queued job can read them without a container.

## See also

- [Exporter classes](exporters.md) — `disk()`, `directory()`, `fileName()`
- [Importer classes](importers.md)
- [Failure reports](failure-reports.md)
- [ExportAction](export-action.md) and [ImportAction](import-action.md)
- [Queued imports](queued-imports.md) — why the upload is deleted on failure
- [File uploads](../forms/file-uploads.md)
- [Storage setup](../deployment/storage.md)
- [Production checklist](../deployment/production-checklist.md)
