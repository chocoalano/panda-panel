# Import and export failures

An export that never arrives, an import where every row failed, a download that 404s, a file a
spreadsheet refuses to open. Each of those has one cause and one fix, and most of them are decided
by a static method on your own `Exporter` or `Importer`. Reach for this page when a file did not
appear, did not import, or did not contain what you expected.

## Start here

Most reports come down to one of three questions: was it queued, is there a worker, and which rows
failed.

```php
use App\Panels\Admin\Resources\Users\UserExporter;
use App\Panels\Admin\Resources\Users\UserImporter;

UserExporter::queueAfter();   // 2000 by default — above this, the export is a job
UserImporter::queueAfter();   // 500
UserExporter::disk();         // 'local'
UserExporter::directory();    // 'panel-exports'
```

```bash
php artisan queue:work                 # a queued export or import needs one
php artisan queue:failed               # what died, with the exception
ls storage/app/private/panel-exports   # or wherever the disk points
```

## Which path did the run take

| Records / rows | Behaviour | Result reaches the user as |
| --- | --- | --- |
| ≤ `queueAfter()` | written inside the request | a flash toast, with a Download link, and a persisted notification |
| > `queueAfter()` | dispatched as a job | a persisted notification when the job finishes |
| `queueAfter()` returns `0` | always queued | as above |
| `queueAfter()` returns a negative number | never queued | in the request, whatever the size |

The count is taken **before** anything is written — `$query->count()` for an export of the list,
`ImportRun::countRows()` for an import — because the count is what decides whether the request waits
or a worker takes over.

A small export in a background job is a worse experience than the wait it avoided; a large one
inside a request is a timeout. That is why the threshold is a number rather than a flag.

## Nothing ever arrived

**Symptom.** The dialog closes, a toast says the import has started, and nothing else happens.

**Cause, almost always.** No queue worker. `RunPanelExport` and `RunPanelImport` are ordinary queued
jobs.

```bash
php artisan queue:work
```

**The two jobs are configured differently, on purpose:**

| Job | `$tries` | `backoff()` | Why |
| --- | --- | --- | --- |
| `PandaPanel\Jobs\RunPanelExport` | `3` | `[10, 60]` | an export only reads rows and writes a file, so a half-finished run has changed nothing anybody can see |
| `PandaPanel\Jobs\RunPanelImport` | `1` | — | an import writes rows; a run that failed halfway has already written some of them, and retrying would turn one bad import into two |

Both implement `failed()`, so a genuine failure is a notification rather than a silence:

```text
Export failed — The file could not be written.
Import failed — That file is not a readable spreadsheet.
```

`RunPanelImport::failed()` also deletes the uploaded file. Without that the upload would stay on the
disk forever — a copy of somebody's customer data that nothing will ever delete.

## Every row failed with the same message

**Symptom.** "The email field is required", ten thousand times.

**Cause.** The file has no column for a required one, so every row fails identically. That is ten
thousand true statements about the wrong thing.

The import now stops before reading a single row and raises a validation error on the file field:

```text
This file has no column for [email], and it is required. Its headings are: name, e-mail,
company. Rename the column in the file, or map it by hand before importing.
```

```php
use PandaPanel\Actions\Imports\ImportRun;

$headings = ImportRun::headings($localPath);
$mapping = ImportRun::guessMapping(UserImporter::class, $headings);

ImportRun::unmappedRequiredColumns(UserImporter::class, $mapping);
// ['email']

ImportRun::missingColumnsMessage(['email'], $headings);
// 'This file has no column for [email], and it is required. …'
```

**Fix, either way:** rename the heading in the file so the guess matches, teach the column to
recognise the heading, or pick the column by hand in the mapping step.

```php
use PandaPanel\Actions\Imports\ImportColumn;

ImportColumn::make('email')
    ->label('Email address')
    ->guess(['e-mail', 'e-mail address', 'mail'])
    ->required()
    ->rules(['email', 'unique:users,email']);
```

`headings()` on a column is `[name, label, ...guesses]`, lowercased and trimmed — so a column named
`email` labelled `Email address` already answers to both without a single guess declared.

## Some rows failed

That is the intended outcome, not a failure. An import of a thousand rows where the four-hundredth
has a bad date should import nine hundred and ninety-nine and tell you about the one.

- **Each row is its own transaction.** One bad row must not undo the good rows before it, and a row
  that creates a related record and then fails must not leave that record behind.
- **A row that threw is a failed row, not a failed import.** The exception message goes into the
  report beside the data that produced it.
- **The report is always CSV**, whatever the upload was: it is a file to correct and re-upload, and
  a CSV opens everywhere.
- **It is filed as `{importer::directory()}/{user key}/failed-rows-{date}.csv`** and linked from the
  notification, because "412 of 500 rows imported" without that link is a problem rather than a
  result.

```php
use PandaPanel\Actions\Imports\ImportRun;

ImportRun::run(UserImporter::class, $localPath, $mapping, $owner);
// ['imported' => 412, 'failed' => 88, 'report' => 'failed-rows-2026-08-16-101500.csv']
```

## A row imported into the wrong column

The mapping is `column name => zero-based position in the file`. Two things build it, and the order
matters:

1. `ImportRun::guessMapping()` matches each column's `headings()` against the file's first row.
2. The mapping selects in the dialog override any column the user chose explicitly.

An explicit choice is never overridden by a heading that happens to look right, and a column with no
match is **absent** from the mapping rather than pointing at position zero — which is what stops the
first column being imported into every unmatched field.

The select's options are spreadsheet column letters (`A`, `B`, … `Z`, `AA`) rather than numbers,
because "C" is findable in the file and "2" is not. The list stops at 200 columns; a file wider than
that still imports, because heading matching has no bound at all.

## The download 404s

Both download endpoints take a **file name**, never a path, and build the directory from whoever is
asking:

```text
GET /{panel}/exports/{file}?exporter=App\Panels\Admin\Resources\Users\UserExporter
GET /{panel}/imports/{file}?importer=App\Panels\Admin\Resources\Users\UserImporter
```

| Route name | Controller | 404s when |
| --- | --- | --- |
| `panel.{id}.export-file` | `PanelExportController` | the name contains `/`, `\` or `..`; the `exporter` query parameter is missing or is not an `Exporter` subclass; the file is not in **this user's** directory |
| `panel.{id}.import-file` | `PanelImportController` | the same, with `importer` |

```php
route($panel->routeName('export-file'), [
    'file' => 'users-2026-08-16-101500.csv',
    'exporter' => UserExporter::class,
], absolute: false);
```

The path is `{directory()}/{$user->getAuthIdentifier()}/{file}`, so a path traversal has nowhere to
go — the caller never supplies a directory. That is also why one user cannot download another's
export however they spell the file name.

A guest gets 403. Everything else is 404.

## The file opens as gibberish, or runs a formula

| Symptom | Cause | Fix |
| --- | --- | --- |
| Accented characters mangled in Excel | missing BOM | none needed — `Csv::open()` writes one; check nothing has rewritten the file |
| A cell shows `'=SUM(A1)` with a leading apostrophe | formula neutralisation, working as intended | `Exporter::escapesFormulas()` returns `false` for a machine-read feed |
| A spreadsheet ran something out of a text field | an export written with escaping off | turn it back on |

A CSV cell beginning with `=`, `+`, `-`, `@`, a tab or a carriage return is a formula as far as
Excel, LibreOffice and Sheets are concerned, and they evaluate it when the file is opened. The
attacker is anyone who can write a record field; the victim is the administrator who opens the
export. Escaping prefixes such a cell with an apostrophe, which every spreadsheet reads as "this is
text" and does not display.

```php
public static function escapesFormulas(): bool
{
    return false;   // only for a file another program parses
}
```

Never turn it off for a file a person opens. XLSX is unaffected either way: the writer emits
`t="inlineStr"` cells, and a formula in that format lives in an `<f>` element it never writes.

## The exporter API

Every method is static, because there is no state an export needs between rows that is not in the
query. A class rather than a closure, because a queued job runs in a different process and carries
the class name and nothing else.

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class UserExporter extends Exporter
{
    /** @return list<ExportColumn> */
    public static function columns(): array
    {
        return [
            ExportColumn::make('name')->label('Name'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('company.name')->label('Company')->enabledByDefault(false),
        ];
    }

    public static function query(Builder $query): Builder
    {
        return $query->with('company');
    }
}
```

| Method | Signature | Default |
| --- | --- | --- |
| `columns` | `abstract static columns(): array` | — |
| `query` | `static query(Builder $query): Builder` | the query unchanged |
| `fileName` | `static fileName(): string` | kebab class basename + `-Y-m-d-His` |
| `disk` | `static disk(): string` | `'local'` |
| `directory` | `static directory(): string` | `'panel-exports'` |
| `formats` | `static formats(): array` | `[SpreadsheetFormat::Csv, SpreadsheetFormat::Xlsx]` |
| `escapesFormulas` | `static escapesFormulas(): bool` | `true` |
| `chunkSize` | `static chunkSize(): int` | `500` |
| `queueAfter` | `static queueAfter(): int` | `2000` |
| `completedMessage` | `static completedMessage(int $records): string` | `'Your export of N records is ready.'` |

**`query()` is where eager loads belong.** An export of ten thousand rows with a relation column is
ten thousand queries without them — and unlike a list screen, nothing on a table asserts it.

`disk()` is `local` rather than `public` on purpose: an export is a copy of records somebody was
allowed to see, and a public disk would put it at a URL anybody can guess. The download goes through
the panel, which asks the question again.

## The importer API

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Actions\Imports\Importer;

final class UserImporter extends Importer
{
    public static function model(): string
    {
        return App\Models\User::class;
    }

    /** @return list<ImportColumn> */
    public static function columns(): array
    {
        return [
            ImportColumn::make('name')->required(),
            ImportColumn::make('email')->required()->rules(['email']),
            ImportColumn::make('company')->relationship('company', 'name')->createRelated(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function resolve(array $data): ?Model
    {
        return App\Models\User::query()->firstOrNew(['email' => $data['email']]);
    }
}
```

| Method | Signature | Default |
| --- | --- | --- |
| `model` | `abstract static model(): string` | — |
| `columns` | `abstract static columns(): array` | — |
| `resolve` | `static resolve(array $data): ?Model` | `new $model` — an insert |
| `rules` | `static rules(): array` | `[]` — row-level rules on top of the columns' own |
| `chunkSize` | `static chunkSize(): int` | `200` |
| `queueAfter` | `static queueAfter(): int` | `500` |
| `disk` | `static disk(): string` | `'local'` |
| `directory` | `static directory(): string` | `'panel-imports'` |
| `completedMessage` | `static completedMessage(int $imported, int $failed): string` | `'Imported N rows.'`, or the same with a report sentence |

`resolve()` is the difference between an import and an update: returning an existing record makes
the row an update, a new one makes it an insert, and **`null` skips the row without counting it as a
failure** — for a file that legitimately contains rows this import is not about.

### `ImportColumn`

| Method | Signature | Notes |
| --- | --- | --- |
| `make` | `static make(string $name): self` | the name is the data key and the default heading |
| `label` | `label(string $label): self` | also a heading it answers to |
| `guess` | `guess(array $guesses): self` | extra headings, compared lowercased and trimmed |
| `rules` | `rules(array $rules): self` | appended after `required`/`nullable` |
| `required` | `required(bool $required = true): self` | drives `unmappedRequiredColumns()` |
| `castUsing` | `castUsing(Closure $callback): self` | turns the trimmed cell text into a value |
| `relationship` | `relationship(string $relationship, string $column = 'name'): self` | resolves a `belongsTo` by a named column |
| `createRelated` | `createRelated(bool $create = true): self` | creates the related record when nothing matched |
| `headings` | `headings(): array` | `[name, label, ...guesses]`, lowercased |
| `validationRules` | `validationRules(): array` | `[required\|nullable, ...rules]` |
| `attribute` | `attribute(Model $model): string` | a relation column writes the **foreign key** |

A relation column that matches nothing and was not told to create one resolves to `null`, and the
row then fails validation on the key it did not get — which is a better answer than a record quietly
attached to nothing.

## Reading a file yourself

```php
use PandaPanel\Actions\Imports\ImportRun;

ImportRun::headings('/path/to/file.csv');    // list<string> — the first row only
ImportRun::countRows('/path/to/file.xlsx');  // int, not counting the header
```

| Method | Signature |
| --- | --- |
| `headings` | `static headings(string $path): array` |
| `countRows` | `static countRows(string $path): int` |
| `guessMapping` | `static guessMapping(string $importer, array $headings): array` |
| `unmappedRequiredColumns` | `static unmappedRequiredColumns(string $importer, array $mapping): array` |
| `missingColumnsMessage` | `static missingColumnsMessage(array $missing, array $headings): string` |
| `run` | `static run(string $importer, string $path, array $mapping, int\|string $owner): array` |

And for the writing half:

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportRun;

ExportRun::write(UserExporter::class, UserResource::query(), ['name', 'email'], SpreadsheetFormat::Csv, $userKey);
// ['path' => 'panel-exports/1/users-….csv', 'file' => 'users-….csv', 'records' => 1204]
```

`SpreadsheetFormat` is a closed enum — `Csv` and `Xlsx` — because each case maps to a reader and a
writer this package ships:

| Member | Signature | Value |
| --- | --- | --- |
| `label` | `label(): string` | `CSV`, `Excel (XLSX)` |
| `extension` | `extension(): string` | `csv`, `xlsx` |
| `mimeTypes` | `mimeTypes(): array` | what the upload is validated against |
| `fromPath` | `static fromPath(string $path): self` | from the extension — a claim, not proof |

## Exceptions you may see

| Exception | Message | Cause |
| --- | --- | --- |
| `PandaPanel\Support\Spreadsheet\SpreadsheetException` | `That file is not a readable spreadsheet.` | an XLSX that `ZipArchive` cannot open |
| | `That spreadsheet is too large to read safely.` | an XLSX XML part is above the reader's safety cap |
| | `That spreadsheet could not be read.` | a part inside the archive is not parseable XML |
| | `That workbook has no readable sheet.` | no worksheet part |
| | `Cannot write to /tmp/…` / `Cannot read …` | a filesystem problem under the export or report writer |
| `PandaPanel\Exceptions\PanelSchemaException` | `An exporter declares more than one column named [email]…` | two `ExportColumn`s with one name — the picker keys its selection by name, so choosing one chose both |
| `Illuminate\Validation\ValidationException` | `This file has no column for [email]…` | a required column the file has no heading for |

## Notes

- **The exporter's disk and the importer's disk must be able to answer `path()`.** Both readers need
  a real filesystem path — a CSV streams from a handle and an XLSX is a zip opened by name — so the
  import file is read through `Storage::disk(...)->path($stored)`. A disk that cannot produce a local
  path is not usable for imports.
- **The uploaded file is deleted after the import**, on success and on failure. The upload was a
  means, not a record.
- **A queued export rebuilds the query from the table state**, through the same `TableQuery` the list
  uses, so the file holds the rows that were on screen — filters and search included — rather than
  everything the resource can see. A bulk export carries explicit keys instead.
- **Column order comes from the exporter, not from the request.** A file whose columns move because
  of how a checkbox list was clicked is a file that cannot be diffed against last week's.
- **An empty column choice exports every column**, not an empty file.
- **Queued exports and imports carry a panel id and no tenant.** In a single-database tenant
  arrangement a scoped resource makes them throw; return a negative `queueAfter()` to keep them in
  the request, or dispatch your own job. See [Tenancy scope leaks](tenancy-scope-leaks.md).
- **`Auth::getProvider()->retrieveById()` is how both jobs find the recipient.** A user deleted while
  the job was queued gets no notification, and the run still completes.
- **XLSX support needs `ext-zip`**, which is a hard requirement in `composer.json` — an XLSX file is
  a zip archive.

## See also

- [Export action](../import-export/export-action.md), [import action](../import-export/import-action.md)
- [Exporters](../import-export/exporters.md), [importers](../import-export/importers.md)
- [Column mapping](../import-export/columns-mapping.md), [CSV and XLSX](../import-export/csv-xlsx.md)
- [Queued exports](../import-export/queued-exports.md), [queued imports](../import-export/queued-imports.md)
- [Failure reports](../import-export/failure-reports.md), [storage and cleanup](../import-export/storage-cleanup.md)
- [Import/export notifications](../import-export/notifications.md)
- [Queues in deployment](../deployment/queues.md), [storage](../deployment/storage.md)
- [Upload failures](uploads.md), [tenancy scope leaks](tenancy-scope-leaks.md)
