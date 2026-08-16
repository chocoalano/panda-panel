# Failure Reports

When an import cannot accept a row, it does not stop. The row is collected with the reason it was refused, and at the end of the run every refused row is written to a CSV — the row exactly as it was, plus an `Error` column. That file is the failure report, and it is what makes a partial import an acceptable outcome rather than a problem.

Reach for this page when you want to know what lands in the report, who can download it, and how a corrected report is re-uploaded.

## A minimal working example

```php
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use PandaPanel\Actions\Imports\ImportRun;

$path = storage_path('app/private/panel-imports/people.csv');

$result = ImportRun::run(
    UserImporter::class,
    $path,
    ImportRun::guessMapping(UserImporter::class, ImportRun::headings($path)),
    $user->getKey(),
);

// ['imported' => 2, 'failed' => 1, 'report' => 'failed-rows-2026-08-15-114233.csv']
```

Given this file:

```text
name,email,is_admin
Grace Hopper,grace@example.test,yes
Broken,not-an-email,no
Alan Turing,alan@example.test,
```

two rows are imported and the report holds:

```text
name,email,is_admin,Error
Broken,not-an-email,no,The email field must be a valid email address.
```

## What counts as a failure

`ImportRun` runs each row inside its own transaction and asks for a reason to reject it. There are two:

| Cause | Reason recorded |
| --- | --- |
| validation failed | every message from the validator, joined with a space |
| the row threw | the exception's `getMessage()` |

Everything else is not a failure:

- a row that saved cleanly;
- a row `Importer::resolve()` returned `null` for — it is skipped, writes nothing, and counts toward `imported`;
- a row whose optional columns were unmapped or blank.

A row that throws is caught because a database constraint, a mutator, or an observer blowing up on one record says nothing about the other nine hundred and ninety-nine. The message goes into the report beside the data that produced it.

Each row being its own transaction is what stops one bad row from undoing the good rows before it, or leaving behind a related record it had just created halfway through.

## The file

| Property | Value |
| --- | --- |
| format | always CSV, whatever the upload was |
| disk | `$importer::disk()` |
| path | `{$importer::directory()}/{ownerKey}/failed-rows-{Y-m-d-His}.csv` |
| header | the **source file's** first row, plus `Error` |
| body | one row per failure: the original cells, then the reason |
| returned as | the basename only — `failed-rows-2026-08-15-114233.csv` |

CSV whatever the upload was, because this is a file to correct and re-upload and a CSV opens everywhere. It is written with `PandaPanel\Support\Spreadsheet\Csv`, so it carries a byte-order mark and its cells are neutralised against [formula injection](csv-xlsx.md) — always, taking `Csv::write()`'s default, because an importer has no equivalent of `Exporter::escapesFormulas()` to turn it off with.

The header comes from the uploaded file rather than from the importer's column names, which is what makes the report a *correctable copy of the original*: the columns are in the positions the mapping already knows about, so re-uploading it needs no re-mapping. It is literally the file's first row — the row the reader skips — so a file that begins with data rather than headings has that first record as its report header, and that record is missing from the report.

When nothing failed, `ImportRun::run()` returns `'report' => null` and no file is written.

## Downloading it

```text
GET {panel}/imports/{file}?importer=App\Panels\Admin\…\UserImporter
route name: panel.{panelId}.import-file
```

`PandaPanel\Http\Controllers\PanelImportController` answers it, and it is the same rule the export download follows:

1. no authenticated user is a 403;
2. a `file` that is empty or contains `/`, `\` or `..` is a 404 — the request names a file, never a path;
3. an `importer` that is not a subclass of `PandaPanel\Actions\Imports\Importer` is a 404;
4. the directory segment is built from `$request->user()->getAuthIdentifier()`, so the only reports reachable are that user's own;
5. a missing file is a 404, anything else is `Storage::disk($importer::disk())->download($path, $file)`.

A failure report is a copy of the data somebody tried to import, which is every bit as worth protecting as the export it resembles.

## How the user reaches it

The URL is built for them and attached to whatever told them the import finished.

| Path | What the user gets |
| --- | --- |
| inline, nothing failed | a `success` toast, and **no** notification |
| inline, something failed | a `warning` toast with a *Download failed rows* link, **and** a persistent `import-finished` notification carrying the same link |
| queued, nothing failed | a persistent `success` notification |
| queued, something failed | a persistent `warning` notification with the link |

A clean inline import is answered by the toast and nothing else: a bell that fills up with "imported 40 rows" is a bell nobody reads. See [Import and export notifications](notifications.md).

## Correcting and re-uploading

The report is designed to be edited and uploaded again as it stands. That only works if a re-uploaded row updates the record it describes instead of creating a second one, which is what `Importer::resolve()` is for:

```php
use Illuminate\Database\Eloquent\Model;

/**
 * @param  array<string, mixed>  $data
 */
public static function resolve(array $data): ?Model
{
    $email = $data['email'] ?? null;

    if (! is_string($email) || $email === '') {
        return null;
    }

    return User::query()->where('email', $email)->first() ?? new User;
}
```

With that, the workflow is: import, download the report, fix the rows it names, upload the report itself. The rows that already landed are absent from it, and the ones that were half-right are updated in place.

The extra `Error` column does not need removing. It is not one of the importer's columns, so unless a column is explicitly mapped to that position it is ignored — the same as any other column the importer does not know about.

## Writing your own reason

The reason text is whatever the validator or the exception produced, so both are yours to shape.

Custom validation messages come from Laravel's own mechanisms — a custom rule object, or `Rule` instances with their own message:

```php
use Illuminate\Validation\Rule;

ImportColumn::make('status')
    ->required()
    ->rules([Rule::in(['draft', 'published', 'archived'])]);
// "The selected status is invalid."
```

An exception message reaches the report unchanged, which is the shortest way to say something specific:

```php
public static function resolve(array $data): ?Model
{
    $product = Product::query()->where('sku', $data['sku'])->first();

    if ($product !== null && $product->isLocked()) {
        throw new RuntimeException('This product is locked and cannot be updated by import.');
    }

    return $product ?? new Product;
}
```

Because the row runs in a transaction, throwing also rolls back anything that row had already written — a related record created moments earlier, for instance.

## Notes

- **The report is written once, at the end of the run**, from the failures held in memory. The reading is streamed a row at a time, but nothing bounds the collected failures: a file where every row fails holds every row in memory before the report is written.
- **Row numbers are not in the report.** What is there is the row's own data, which is what you need to fix it. If you want the source line, add a column to your file and declare it in the importer.
- **The reason column is always called `Error`**, and there is no setter for it.
- **Reports are never deleted.** They accumulate under `{directory}/{owner}/` until something removes them — see [Storage and cleanup](storage-cleanup.md).
- **A report is not itself an import.** Uploading it starts a fresh run with fresh mapping and fresh validation; nothing about the previous run is remembered.
- **A failure report is not produced for a file that could not be read at all.** That is a `SpreadsheetException`, which fails the whole import and is reported as a message rather than as rows.

## See also

- [Importer classes](importers.md) — `resolve()`, `rules()`, `completedMessage()`
- [Columns and mapping](columns-mapping.md) — per-column rules and casting
- [ImportAction](import-action.md)
- [Queued imports](queued-imports.md)
- [Import and export notifications](notifications.md)
- [CSV and XLSX](csv-xlsx.md)
- [Storage and cleanup](storage-cleanup.md)
- [Form validation](../forms/validation.md)
