# Import And Export Actions

`PandaPanel\Actions\ExportAction` writes a resource's records to a spreadsheet; `PandaPanel\Actions\ImportAction` reads records back in. Both are ordinary actions with a form: the dialog asks the questions that belong to the file — which columns, which format, which column of the upload is which — and the work happens after it is submitted.

Both take a **class name** rather than a closure, because a queued run happens in a different process from the request that asked for it, and only a class name crosses that gap.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Exports;

use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class UserExporter extends Exporter
{
    /**
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('email'),
        ];
    }
}
```

```php
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\ExportAction;

$table->headerActions([
    ExportAction::make(UserExporter::class, UserResource::class),
]);
```

An Export button appears above the table. It opens a dialog offering the three columns and the two formats, writes the file, and hands back a download link.

## The three factories

```php
use PandaPanel\Actions\Action;

ExportAction::make(string $exporter, string $resource): Action   // the list, as currently filtered
ExportAction::bulk(string $exporter, string $resource): Action   // the selection
ImportAction::make(string $importer, string $resource): Action
```

| Factory | Name | Label | Icon | Variant | Scope | Authorized by |
| --- | --- | --- | --- | --- | --- | --- |
| `ExportAction::make()` | `export` | Export | `download` | outline | table | `Resource::canViewAny()` |
| `ExportAction::bulk()` | `export` | Export | `download` | outline | bulk | `Resource::canViewAny()` |
| `ImportAction::make()` | `import` | Import | `upload` | outline | table | `Resource::canCreate()` |

All three open a `ModalWidth::Large` dialog. The export headings are `Export records` / `Export`; the import's are `Import records` / `Import`, plus a description and `closeByClickingAway(false)` — a long dialog with an upload in it is exactly where a stray click costs the most.

```php
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;

$table
    ->headerActions([
        ImportAction::make(UserImporter::class, UserResource::class),
        ExportAction::make(UserExporter::class, UserResource::class),
    ])
    ->bulkActions([
        ExportAction::bulk(UserExporter::class, UserResource::class),
    ]);
```

`make()` and `bulk()` share everything but which records they cover, because "which columns" is a question about the file, not about how the records were chosen.

## Which records an export covers

- **`bulk()`** exports the selection: `$query->whereKey($keys)`.
- **`make()`** exports the list as the table state describes it, through the same `PandaPanel\Tables\TableQuery` the list itself uses — so the file and the screen cannot disagree.

The client sends that state with the request as `tableState`. The server puts every value back through the table's own schema, which is the whitelist, so a filter the table never declared is ignored exactly as it is when it arrives in a URL. The worst a crafted payload can describe is a list the user could have navigated to.

## The export dialog

Two fields, both ordinary:

```php
use PandaPanel\Forms\Components\CheckboxList;
use PandaPanel\Forms\Components\Radio;

CheckboxList::make('columns')->options(/* the exporter's columns */)->columns(2)->bulkToggleable()->required();
Radio::make('format')->options(/* the exporter's formats */)->inline()->required();
```

Because they are fields, a column name that is not one of the exporter's is refused by the same `in:` rule any other choice field uses. An empty choice writes every column rather than an empty file.

## `Exporter`

Everything is static. There is no state an export needs between rows that is not in the query.

| Method | Signature | Default |
| --- | --- | --- |
| `columns` | `abstract static columns(): array` | — required, `list<ExportColumn>` |
| `query` | `static query(Builder $query): Builder` | the query unchanged |
| `fileName` | `static fileName(): string` | `{kebab-class-basename}-Y-m-d-His` |
| `disk` | `static disk(): string` | `local` |
| `directory` | `static directory(): string` | `panel-exports` |
| `formats` | `static formats(): array` | `[SpreadsheetFormat::Csv, SpreadsheetFormat::Xlsx]` |
| `escapesFormulas` | `static escapesFormulas(): bool` | `true` |
| `chunkSize` | `static chunkSize(): int` | `500` |
| `queueAfter` | `static queueAfter(): int` | `2000` |
| `completedMessage` | `static completedMessage(int $records): string` | `Your export of {n} records is ready.` |

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class UserExporter extends Exporter
{
    /**
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('email'),
            ExportColumn::make('company.name')->label('Company'),
            ExportColumn::make('is_admin')
                ->label('Administrator')
                ->formatUsing(static fn (mixed $value): string => $value ? 'Yes' : 'No'),
            ExportColumn::make('updated_at')->label('Last updated')->enabledByDefault(false),
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function query(Builder $query): Builder
    {
        // Stable regardless of how the list was sorted, and eager loaded so a
        // relation column is not one query per row.
        return $query->with('company')->reorder('id');
    }

    public static function fileName(): string
    {
        return 'users-'.date('Y-m-d');
    }

    public static function formats(): array
    {
        return [SpreadsheetFormat::Csv];
    }

    public static function queueAfter(): int
    {
        return 5000;
    }
}
```

`disk()` is `local` rather than `public` on purpose: an export is a copy of records somebody was allowed to see, and a public disk would put it at a URL anybody can guess. The download goes through the panel, which asks the question again.

`escapesFormulas()` neutralises a CSV cell a spreadsheet would otherwise run as a formula. Turn it off only for a file another *program* reads, where nothing evaluates anything and the leading apostrophe would be corruption rather than a fix. Never for a file a person opens.

## `ExportColumn`

```php
use Closure;

ExportColumn::make(string $name): self
ExportColumn::label(string $label): self
ExportColumn::formatUsing(Closure $callback): self     // fn (mixed $value, Model $record): mixed
ExportColumn::enabledByDefault(bool $enabled = true): self
ExportColumn::getName(): string
ExportColumn::getLabel(): string
ExportColumn::isEnabledByDefault(): bool
ExportColumn::toCell(Model $record): string
```

The name is read with `data_get()`, so dot notation reads through relations — `author.name` is a column rather than a reason to write a formatter. Without a label, the heading is `Str::headline()` of the name with dots turned into spaces.

`toCell()` turns everything into a string in one place, so the two file formats cannot disagree about what a boolean or a date looks like:

| Value | Cell |
| --- | --- |
| `null` | `''` |
| `bool` | `Yes` / `No` |
| `DateTimeInterface` | `Y-m-d H:i:s` |
| any scalar | cast to string |
| anything else | `json_encode()` |

An export column is deliberately not a table column. A table column knows how to sort, search, and render HTML; an export column turns a record into one string.

## Where the file goes

`PandaPanel\Actions\Exports\ExportRun::write()` does the writing, whether it runs in the request or in a queued job — a queued export that produced a different file from an immediate one would be a bug nobody found until the row count crossed a threshold.

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportRun;

ExportRun::write(
    string $exporter,
    Builder $query,
    array $columns,            // the chosen names
    SpreadsheetFormat $format,
    int|string $owner,         // the key of the user the file belongs to
): array                       // {path, file, records}
```

The path is `{directory}/{owner}/{fileName}.{ext}`. Filed under the user it belongs to, and the download endpoint builds that segment from whoever is asking rather than from the request — one user cannot name another's export however they spell the path.

Columns are written in the order the **exporter** declared them, never the order they were ticked: a file whose columns move cannot be diffed against last week's. Two columns with one name throws `PanelSchemaException::duplicateExportColumns()`.

Rows are produced by a generator over `->lazy(chunkSize())`, so an export of a hundred thousand records holds `chunkSize()` of them at a time and no more.

## Queued exports

Above `Exporter::queueAfter()` records, `PandaPanel\Jobs\RunPanelExport` is dispatched and the file arrives as a persistent notification with a download link. Below it, the export runs in the request and the link comes back on the response. Zero always queues; a negative number never does.

A number rather than a flag: a small export in a background job is a worse experience than the wait it avoided, and a large one in a request is a timeout.

The job carries only scalars — the exporter class, the resource class, the chosen columns, the format, the owner key, the table state, an optional selection, and the panel id — and rebuilds the query on the other side through the same `TableQuery`. It retries three times with a `[10, 60]` backoff, because an export only reads rows and writes a file, so a half-written one is replaced by the next attempt. On final failure it sends an `Export failed` notification rather than leaving somebody watching a bell that will never ring.

## Downloading

```text
GET {panel}/exports/{file}?exporter=App\…\UserExporter    route name: panel.{panelId}.export-file
GET {panel}/imports/{file}?importer=App\…\UserImporter    route name: panel.{panelId}.import-file
```

The request names a file and nothing else. The directory is built from the authenticated user, so a path traversal has nowhere to go — the caller never supplies a path. A `file` containing `/`, `\`, or `..` is a 404, as is an `exporter` that is not a subclass of `Exporter`.

## `ImportAction`

The dialog is two steps in one: the file is uploaded by the ordinary `FileUpload` field, which stores it before the form is submitted, and the submit says which column of the file feeds which column of the import. That ordering is what makes mapping possible at all — the headings cannot be offered until the file exists.

The upload accepts CSV and XLSX media types with `maxSize(20480)` (20 MB), on the importer's own disk and directory. Each declared column gets a searchable `Select` named `map_{column}`, offering spreadsheet-style positions `A`, `B`, … `Z`, `AA` up to 200 columns. A column left blank is filled in from the file's headings by `ImportRun::guessMapping()`; an explicit choice is never overridden by a guess.

Before a single row is read, `ImportRun::unmappedRequiredColumns()` checks that every required column found a position. If one did not, the upload is deleted and a `ValidationException` names the missing columns and lists the headings the file actually has. A required column with no heading would otherwise fail every row identically, which is ten thousand true statements about the wrong thing.

## `Importer`

| Method | Signature | Default |
| --- | --- | --- |
| `model` | `abstract static model(): string` | — required, `class-string<Model>` |
| `columns` | `abstract static columns(): array` | — required, `list<ImportColumn>` |
| `resolve` | `static resolve(array $data): ?Model` | a new model — an insert |
| `rules` | `static rules(): array` | `[]` |
| `chunkSize` | `static chunkSize(): int` | `200` |
| `queueAfter` | `static queueAfter(): int` | `500` |
| `disk` | `static disk(): string` | `local` |
| `directory` | `static directory(): string` | `panel-imports` |
| `completedMessage` | `static completedMessage(int $imported, int $failed): string` | `Imported {n} rows.` or a message naming the failures |

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
            ImportColumn::make('name')
                ->guess(['full name', 'user'])
                ->required()
                ->rules(['string', 'max:255']),
            ImportColumn::make('email')
                ->label('Email')
                ->guess(['e-mail', 'email address'])
                ->required()
                ->rules(['email', 'max:255'])
                ->castUsing(static fn (string $value): string => mb_strtolower(trim($value))),
            ImportColumn::make('company')
                ->relationship('company', 'name')
                ->createRelated(),
        ];
    }

    /**
     * Matched on the address, so a corrected file updates rather than
     * duplicating.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?Model
    {
        $email = $data['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        return (new User)->forceFill([
            'password' => Hash::make(Str::random(32)),
        ]);
    }
}
```

`resolve()` is the difference between an import and a re-import. Returning an existing record makes the row an update; returning a new one makes it an insert; returning `null` skips the row **without counting it as a failure**, for a file that legitimately contains rows this import is not about.

`rules()` adds checks about the whole row on top of the columns' own — "one of these two must be present", a uniqueness rule that spans columns.

## `ImportColumn`

```php
use Closure;

ImportColumn::make(string $name): self
ImportColumn::label(string $label): self
ImportColumn::guess(array $guesses): self                              // list<string>
ImportColumn::rules(array $rules): self                                // list<mixed>
ImportColumn::required(bool $required = true): self
ImportColumn::castUsing(Closure $callback): self                       // fn (string $value): mixed
ImportColumn::relationship(string $relationship, string $column = 'name'): self
ImportColumn::createRelated(bool $create = true): self
ImportColumn::getName(): string
ImportColumn::getLabel(): string
ImportColumn::isRequired(): bool
ImportColumn::getRelationship(): ?string
ImportColumn::headings(): array
ImportColumn::validationRules(): array
ImportColumn::cast(string $value): mixed
ImportColumn::resolveRelated(Model $model, mixed $value): ?int
ImportColumn::attribute(Model $model): string
```

`headings()` is the set a column answers to, lowercased: its name, its label, and everything in `guess()`. A file exported from somewhere else calls the column "E-mail Address", and asking a person to rename it before importing is asking them to do the computer's job.

`validationRules()` is `required` or `nullable` followed by whatever `rules()` was given. The rules are Laravel's, applied per row — a file is request input like any other, and arriving as a spreadsheet does not make it trustworthy.

`castUsing()` turns the cell's text into the value the column holds. A spreadsheet has no types: `1`, `yes`, and `TRUE` all mean the same thing to a person and nothing to a boolean column. Without one, the value is trimmed and an empty string becomes `null`.

`relationship()` resolves a cell against a `BelongsTo` and stores the foreign key, so the importer never has to know that `company` means `company_id`. It is `BelongsTo` and nothing else — those are the relations whose value is a column of the row being imported. When the lookup finds nothing and `createRelated()` was not set, the value is `null` and the row fails validation on the key it did not get, which is a better answer than a record quietly attached to nothing.

## What a row does

`PandaPanel\Actions\Imports\ImportRun::run()` walks the file and, for each row, inside its own transaction:

1. casts every mapped cell with `ImportColumn::cast()`;
2. resolves relation columns to foreign keys;
3. validates the assembled row against the columns' rules plus `Importer::rules()`;
4. calls `Importer::resolve()`;
5. `forceFill()`s the attributes onto the record and saves.

A row that fails validation, or throws, is collected rather than thrown. An import of a thousand rows where the four-hundredth has a bad date imports nine hundred and ninety-nine and writes the rest to a failure report — the row exactly as it was, plus a reason column. That file is correctable and re-uploadable as it stands, which is what makes `resolve()` matching an existing record worth writing.

Each row being its own transaction is what stops one bad row from undoing the good rows before it, or leaving behind a related record it had just created.

## Queued imports

Above `Importer::queueAfter()` rows, `PandaPanel\Jobs\RunPanelImport` is dispatched, the user is told the import has started, and the result arrives as a notification with a link to the failed rows. Below it, the import runs in the request and the upload is deleted afterwards.

The job's `$tries` is **1**, deliberately. An import writes rows; a run that failed halfway has already written some of them and there is no general way to know which. Retrying would turn one bad import into two. On failure it deletes the upload — the file was a means, not a record — and sends an `Import failed` notification carrying the reader's own message, which is what tells somebody how to fix their file.

A clean import sends no notification at all: the toast on the response says so, and a bell that fills up with "imported 40 rows" is a bell nobody reads.

## Formats

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;

SpreadsheetFormat::Csv;    // 'csv'  → label 'CSV'
SpreadsheetFormat::Xlsx;   // 'xlsx' → label 'Excel (XLSX)'

SpreadsheetFormat::Csv->label();       // 'CSV'
SpreadsheetFormat::Csv->extension();   // 'csv'
SpreadsheetFormat::Csv->mimeTypes();   // ['text/csv', 'text/plain', 'application/csv']
SpreadsheetFormat::fromPath('a.xlsx'); // SpreadsheetFormat::Xlsx
```

Both are read and written without a spreadsheet dependency; see `PandaPanel\Support\Spreadsheet\Csv` and `PandaPanel\Support\Spreadsheet\Xlsx` for what that costs and what it deliberately does not do. The failure report is always CSV whatever the upload was — it is a file to correct and re-upload, and a CSV opens everywhere.

## Gotchas

- **The exporter and importer are class names, not instances.** Everything on them is static, because the queued job carries the name and nothing else.
- **`queueAfter()` counts before writing.** An export counts the query; an import reads the file to count its rows. A guess would put a large file in the request or a small one behind a queue nobody is watching.
- **A queued run needs a worker.** With `QUEUE_CONNECTION=sync` it happens inline, which defeats the threshold but still works. With a real queue and no worker, the notification never arrives.
- **The mapping selects are not `live()`.** Their options are positions, not the file's headings, because the form is built before any file has been uploaded. The guess from the headings is what fills them in.
- **A column beyond position 200 cannot be mapped by hand**, but is still mapped automatically if its heading is recognisable — heading matching has no bound.
- **`forceFill()` writes the row.** Mass-assignment protection is not consulted; the column list is the whitelist.
- **The failure report is filed under the user too**, and reachable only through `panel.{panelId}.import-file` by that user.
- **Uploads are deleted after the import**, on success and on failure. They are a means, not a record.
- **An export of a relation column without an eager load is one query per row.** `Exporter::query()` is where `with()` belongs.

## See also

- [Action basics](overview.md)
- [Action forms](forms.md) and [Action modals](modals.md)
- [Bulk actions](bulk-actions.md)
- [Built-in actions](built-in-actions.md)
- [Export action](../import-export/export-action.md) and [Import action](../import-export/import-action.md)
- [Exporters](../import-export/exporters.md) and [Importers](../import-export/importers.md)
- [Columns and mapping](../import-export/columns-mapping.md)
- [Queued exports](../import-export/queued-exports.md) and [Queued imports](../import-export/queued-imports.md)
- [Failure reports](../import-export/failure-reports.md)
- [CSV and XLSX](../import-export/csv-xlsx.md)
- [Storage and cleanup](../import-export/storage-cleanup.md)
- [Import and export notifications](../import-export/notifications.md)
- [File uploads](../forms/file-uploads.md)
