# ImportAction

`PandaPanel\Actions\ImportAction` loads records into a resource from a CSV or XLSX file. It is a factory that returns a configured `PandaPanel\Actions\Action`, so the label, icon, modal and authorization are all still yours to change.

Reach for it when somebody has a spreadsheet and wants it in the application: a list of new users, a price update, a file exported from another system. The action owns the dialog — the upload and the column mapping — and the `Importer` class you pass owns the model, the columns, the rules, and what a row means.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Actions\Imports\Importer;

final class UserImporter extends Importer
{
    /**
     * @return class-string<Model>
     */
    public static function model(): string
    {
        return User::class;
    }

    /**
     * @return list<ImportColumn>
     */
    public static function columns(): array
    {
        return [
            ImportColumn::make('name')->required()->rules(['string', 'max:255']),
            ImportColumn::make('email')
                ->guess(['e-mail', 'email address'])
                ->required()
                ->rules(['email', 'max:255']),
        ];
    }
}
```

```php
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\ImportAction;

$table->headerActions([
    ImportAction::make(UserImporter::class, UserResource::class),
]);
```

An **Import** button appears above the table. It opens a dialog with a file field and one select per column, reads the file when the dialog is submitted, and reports how many rows landed.

## The factory

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Imports\Importer;
use PandaPanel\Resources\Resource;

/** @param class-string<Importer> $importer @param class-string<Resource> $resource */
ImportAction::make(string $importer, string $resource): Action;
```

A class name rather than a closure, because a large file is read by a queued job in another process and only a class name crosses that gap.

| Setting | Value |
| --- | --- |
| name | `import` |
| label | `Import` |
| icon | `upload` |
| variant | `ActionVariant::Outline` |
| modal heading | `Import records` |
| modal submit label | `Import` |
| modal width | `ModalWidth::Large` |
| modal description | `Upload a CSV or Excel file, then say which column is which.` |
| modal dismissal | `closeByClickingAway(false)` |
| authorization | `$resource::canCreate()` |
| handler | `->tableAction()` |

`canCreate()` rather than `canViewAny()`: an import writes rows, and the ability to read a list is not the ability to add to it. Everything above is an ordinary setter you can override:

```php
use PandaPanel\Actions\ImportAction;
use PandaPanel\Actions\Support\Modal;

ImportAction::make(UserImporter::class, UserResource::class)
    ->label('Upload users')
    ->modalHeading('Upload a user list')
    ->modal(static function (Modal $modal): void {
        $modal->description('Columns: Name, Email. Everything else is ignored.');
    });
```

The dismissal setting is worth keeping. A long dialog with an upload in it is exactly where a stray click outside costs the most.

## The dialog is two steps in one

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Layouts\Section;

FileUpload::make('file')
    ->label('File')
    ->disk($importer::disk())
    ->directory($importer::directory())
    ->acceptedTypes(array_merge(
        SpreadsheetFormat::Csv->mimeTypes(),    // text/csv, text/plain, application/csv
        SpreadsheetFormat::Xlsx->mimeTypes(),   // …spreadsheetml.sheet, application/zip
    ))
    ->maxSize(20480)                            // kilobytes — 20 MB
    ->required();

Section::make('Columns')
    ->description('Leave a column blank to skip it. Blank columns are guessed from the headings.')
    ->columns(2)
    ->schema(/* one Select per importer column */);
```

The file is uploaded by the ordinary `FileUpload` field, which stores it **before** the form is submitted, and the submit then says which column of the file feeds which column of the import. That ordering is what makes mapping possible at all: the headings cannot be offered until the file exists.

Each declared column gets its own select:

```php
Select::make('map_'.$column->getName())
    ->label($column->getLabel())
    ->options(/* col0 => 'A', col1 => 'B', … col199 => 'GR' */)
    ->searchable()
    ->helperText($column->isRequired() ? 'Required' : null);
```

The options are spreadsheet-style **positions**, not the file's headings, because the form is built before any file has been uploaded. A select left blank is filled in from the headings by `ImportRun::guessMapping()`; an explicit choice is never overridden by a guess. All of that is covered in [Columns and mapping](columns-mapping.md).

## What happens on submit

`ImportAction` runs this in order, and stops at the first thing that is wrong:

| Step | Failure |
| --- | --- |
| an authenticated user with a usable key | 403 / 500 |
| a resolved panel | 500 |
| `$data['file']` is a non-empty string | 422 `No file was uploaded.` |
| the file still exists on `$importer::disk()` | 404 `That file is no longer there.` |
| every **required** column found a position | `ValidationException` on `file`, and the upload is deleted |
| the row count decides inline or queued | — |

XLSX row counting still has to open the workbook before the inline/queued decision. The reader caps
each XML part at 64 MiB, so a workbook that is too large fails as an unreadable file instead of being
sent to a worker.

The required-column check runs before a single row is read. Without it, a required column the file has no heading for fails every row identically — "The name field is required", ten thousand times, which is a wall of true statements about the wrong thing. The message names the missing columns and lists the headings the file actually has:

```text
This file has no column for [email], and it is required. Its headings are: Full Name, Address.
Rename the column in the file, or map it by hand before importing.
```

## Inline or queued

```php
$rows = ImportRun::countRows($local);   // the file is read to count, never estimated

if ($importer::queueAfter() >= 0 && $rows > $importer::queueAfter()) {
    // PandaPanel\Jobs\RunPanelImport is dispatched.
}
```

| `queueAfter()` | Behaviour |
| --- | --- |
| `0` | always queued |
| `500` (default) | queued above 500 rows |
| negative | never queued |

A queued import flashes an immediate toast and returns:

```php
Inertia::flash('toast', [
    'type' => 'info',
    'message' => 'Your import has started. You will be notified when it finishes.',
]);
```

An inline import reads the file, deletes the upload, and reports the result. See [Queued imports](queued-imports.md) for the other half.

## What comes back from an inline import

```php
$result = ImportRun::run($importer, $local, $mapping, $owner);
// ['imported' => 998, 'failed' => 2, 'report' => 'failed-rows-2026-08-15-114233.csv']
```

- The toast is `success` when nothing failed and `warning` when something did, and its message is `$importer::completedMessage($imported, $failed)`.
- A **persistent** notification is sent **only when there were failures**, carrying a *Download failed rows* link. A clean import is answered by the toast and nothing else — a bell that fills up with "imported 40 rows" is a bell nobody reads.
- The report URL is `route($panel->routeName('import-file'), ['file' => $report, 'importer' => $importer], absolute: false)`.

See [Failure reports](failure-reports.md) and [Import and export notifications](notifications.md).

## Downloading the failed rows

```text
GET {panel}/imports/{file}?importer=App\Panels\Admin\…\UserImporter
route name: panel.{panelId}.import-file
```

`PandaPanel\Http\Controllers\PanelImportController` applies the same rules the export download does: 403 without a user, 404 for a `file` containing `/`, `\` or `..`, 404 for an `importer` that is not a subclass of `PandaPanel\Actions\Imports\Importer`, and a path built as `{$importer::directory()}/{$user->getAuthIdentifier()}/{$file}` from whoever is asking. A failure report is a copy of the data somebody tried to import, which is every bit as worth protecting as the export.

## Where the upload goes

The upload posts to the panel's `uploads` endpoint, which reads the disk and directory from the **field's own declaration** — the request never names either. Because the field belongs to an action's form, the upload is authorized as that action, not as the resource's create form. The stored path is `{$importer::directory()}/{random}.{ext}`, and it is deleted:

- after an inline import finishes;
- when the required-column check refuses the file;
- by `RunPanelImport` on success **and** on failure.

An upload whose dialog was closed without submitting is the one case nothing deletes — see [Storage and cleanup](storage-cleanup.md).

## Gotchas

- **The importer's disk must be a local one.** The reader is handed `Storage::disk($importer::disk())->path($stored)` and opens it with `fopen()` / `ZipArchive`. A driver whose `path()` is not a readable filesystem path — S3, for instance — cannot be read from. The default `local` is correct.
- **20 MB is the upload ceiling**, from `maxSize(20480)`. It is enforced by the upload endpoint against the real file, not against what the browser claimed. There is no setter for it on `ImportAction`; a bigger file needs a different route into the application.
- **`ImportAction::make()` is a table action.** It declares no record handler, so it belongs in `headerActions()` or `toolbarActions()`. In `recordActions()` it cannot execute.
- **A column left unmapped is not imported.** It is treated exactly as a file missing that column would be: the cell reads as `''`, which casts to `null` and then meets the column's rules.
- **Mass assignment is not consulted.** Rows are written with `forceFill()`; the importer's column list is the whitelist. A column you do not declare cannot be written, and `$fillable` has no say over one you do.
- **A partial import is the intended outcome.** One bad date in row four hundred should not cost the other nine hundred and ninety-nine.

## See also

- [Importer classes](importers.md) — model, columns, `resolve()`, thresholds
- [Columns and mapping](columns-mapping.md) — `ImportColumn` and the mapping step in full
- [Failure reports](failure-reports.md)
- [Queued imports](queued-imports.md)
- [Storage and cleanup](storage-cleanup.md)
- [ExportAction](export-action.md)
- [Import and export actions](../actions/import-export.md)
- [File uploads](../forms/file-uploads.md)
- [Action forms](../actions/forms.md) and [Action modals](../actions/modals.md)
