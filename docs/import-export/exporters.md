# Exporter Classes

A `PandaPanel\Actions\Exports\Exporter` is what an export *is*: a set of columns, a query, and where the file lands. You write one per thing that gets exported, and hand its class name to [`ExportAction`](export-action.md).

It is an abstract class with static methods rather than a closure on the action, for the reason a policy is a class: a queued export runs in a different process from the request that asked for it, and only a class name crosses that gap. Everything is static because there is no state an export needs between rows that is not in the query.

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

That is a complete exporter. `columns()` is the only abstract method; every other method has a default that works.

## Every method

| Method | Signature | Default |
| --- | --- | --- |
| `columns` | `abstract public static function columns(): array` | — required, `list<ExportColumn>` |
| `query` | `public static function query(Builder $query): Builder` | the query unchanged |
| `fileName` | `public static function fileName(): string` | `{kebab-class-basename}-Y-m-d-His` |
| `disk` | `public static function disk(): string` | `'local'` |
| `directory` | `public static function directory(): string` | `'panel-exports'` |
| `formats` | `public static function formats(): array` | `[SpreadsheetFormat::Csv, SpreadsheetFormat::Xlsx]` |
| `escapesFormulas` | `public static function escapesFormulas(): bool` | `true` |
| `chunkSize` | `public static function chunkSize(): int` | `500` |
| `queueAfter` | `public static function queueAfter(): int` | `2000` |
| `completedMessage` | `public static function completedMessage(int $records): string` | `Your export of {n} records is ready.` |

### `columns()`

The columns offered in the dialog, **in the order they are written**. Every `ExportColumn` setter is covered in [Columns and mapping](columns-mapping.md); the short version:

```php
use PandaPanel\Actions\Exports\ExportColumn;

public static function columns(): array
{
    return [
        ExportColumn::make('id')->label('ID'),
        ExportColumn::make('name'),
        // Dot notation reads through relations with data_get().
        ExportColumn::make('company.name')->label('Company'),
        ExportColumn::make('is_admin')
            ->label('Administrator')
            ->formatUsing(static fn (mixed $value): string => $value ? 'Yes' : 'No'),
        // Offered in the dialog but unticked.
        ExportColumn::make('updated_at')->label('Last updated')->enabledByDefault(false),
    ];
}
```

Two columns with the same name throw `PanelSchemaException::duplicateExportColumns()`: the file would carry two identical headings, and the column picker keys its selection by name, so ticking one would tick both.

A column whose value should never leave the application is a column you do not declare. There is no "hidden" flag, and there does not need to be — an exporter's column list is the whole surface.

### `query()`

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @param  Builder<covariant Model>  $query
 * @return Builder<covariant Model>
 */
public static function query(Builder $query): Builder
{
    // Eager loads for relation columns, and a stable order.
    return $query->with('company')->reorder('id');
}
```

The builder handed over is already whatever the export covers — the resource's scope plus the table's filters and search for `ExportAction::make()`, or the selection for `ExportAction::bulk()`. Two things belong here and nowhere else:

- **Eager loads.** An export of ten thousand rows with a relation column is ten thousand queries without them.
- **A deterministic order.** `reorder('id')` makes two exports of the same records comparable line by line; without it the order follows whatever the list was sorted by when the button was pressed.

It is not the place to add constraints that narrow the record set — that is what the table's filters are for, and a constraint here would apply to the bulk export too, quietly dropping records the user had explicitly ticked.

### `fileName()`

Without an extension; `ExportRun` appends the format's own.

```php
public static function fileName(): string
{
    return 'users-'.date('Y-m-d');
}
```

The default is the kebab-cased class basename plus `Y-m-d-His` — `user-exporter-2026-08-15-114233` — because the second thing anyone asks about an export file is when it was made. A name with no timestamp means the next export overwrites the last one, which is a reasonable choice for "the current list" and a bad one for an audit trail.

### `disk()` and `directory()`

```php
public static function disk(): string
{
    return 'reports';       // a disk in config/filesystems.php
}

public static function directory(): string
{
    return 'exports/users';
}
```

The file is written to `{directory}/{ownerKey}/{fileName}.{ext}`, where the owner is the key of the user who asked for it. The download endpoint builds that middle segment from whoever is asking rather than from the request, so one user cannot name another's export however they spell the path.

`local` rather than `public` is the default on purpose: an export is a copy of records somebody was allowed to see, and a public disk would put it at a URL anybody can guess. If you point this at another disk, keep it private and keep it out of the web root.

### `formats()`

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;

public static function formats(): array
{
    return [SpreadsheetFormat::Csv];
}
```

Both are offered by default. The first entry is the dialog's default choice, so ordering the list is how you choose which format most people get. An exporter that offers only one still shows the radio; the field is required and there is one option.

### `escapesFormulas()`

```php
public static function escapesFormulas(): bool
{
    return false;   // only for a file another program parses
}
```

On, a CSV cell beginning with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed with an apostrophe so a spreadsheet shows it instead of running it. That is CWE-1236, and the attacker is anyone who can type into a text field while the victim is the administrator who opens the export.

Turn it off only for a file another *program* reads, where nothing evaluates anything and the leading apostrophe would be data corruption rather than a fix. It has no effect on XLSX, which needs none of it — see [CSV and XLSX](csv-xlsx.md).

### `chunkSize()`

```php
public static function chunkSize(): int
{
    return 1000;
}
```

How many records are held in memory at once. Rows are produced by a generator over `->lazy(chunkSize())`, so an export of a hundred thousand records holds this many at a time and no more. Raise it to trade memory for fewer round trips; lower it for a model with large columns.

### `queueAfter()`

```php
public static function queueAfter(): int
{
    return 5000;
}
```

Above this many records the export is dispatched to `PandaPanel\Jobs\RunPanelExport` instead of running in the request. A number rather than a flag: a small export in a background job is a worse experience than the wait it avoided, and a large one in a request is a timeout.

| Value | Behaviour |
| --- | --- |
| `0` | always queued |
| `2000` | the default — queued above 2000 records |
| any negative number | never queued |

### `completedMessage()`

```php
public static function completedMessage(int $records): string
{
    return $records === 1
        ? 'Your export of 1 record is ready.'
        : sprintf('Your export of %s records is ready.', number_format($records));
}
```

The title of the notification and the text of the toast, in both the inline and the queued path.

## A complete exporter

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
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
            ExportColumn::make('reference')->label('Order'),
            ExportColumn::make('customer.name')->label('Customer'),
            ExportColumn::make('total')
                ->formatUsing(static fn (mixed $value): string => number_format((float) $value, 2)),
            ExportColumn::make('status'),
            ExportColumn::make('placed_at')->label('Placed'),
            ExportColumn::make('internal_note')->enabledByDefault(false),
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function query(Builder $query): Builder
    {
        return $query->with('customer')->reorder('id');
    }

    public static function fileName(): string
    {
        return 'orders-'.date('Y-m-d');
    }

    public static function formats(): array
    {
        return [SpreadsheetFormat::Xlsx, SpreadsheetFormat::Csv];
    }

    public static function chunkSize(): int
    {
        return 1000;
    }

    public static function queueAfter(): int
    {
        return 5000;
    }

    public static function completedMessage(int $records): string
    {
        return sprintf('%s orders exported.', number_format($records));
    }
}
```

## Running one without the action

`PandaPanel\Actions\Exports\ExportRun::write()` is the whole writer, and it is public. A console command or a scheduled report can use it directly:

```php
use App\Models\Order;
use App\Panels\Admin\Resources\Orders\Exports\OrderExporter;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportRun;

$result = ExportRun::write(
    OrderExporter::class,
    Order::query()->where('status', 'shipped'),
    ['reference', 'total'],          // the chosen names; [] means every column
    SpreadsheetFormat::Xlsx,
    $user->getKey(),                 // the owner directory the file is filed under
);

// ['path' => 'panel-exports/7/orders-2026-08-15.xlsx', 'file' => 'orders-2026-08-15.xlsx', 'records' => 412]
```

The same code runs inside the request and inside the queued job. A queued export that produced a different file from an immediate one would be a bug nobody found until the row count crossed a threshold.

Two details it enforces regardless of what you pass:

- Columns are written in the order **`columns()`** declared them, never the order they were listed in the call. A file whose columns move cannot be diffed against last week's.
- `columns: []` writes every column rather than an empty file.

## Notes

- **Everything is static, and the class is never instantiated.** `Exporter` has no constructor, no properties, and no `$this`. Configuration that would live on an instance belongs in the methods.
- **The file is assembled on local disk first.** `ExportRun` writes to `tempnam(sys_get_temp_dir(), 'panel-export-')`, then streams it onto the disk and unlinks the temporary file. Both writers need a real path — a CSV streams to a handle and an XLSX is a zip `ZipArchive` opens by name.
- **The exporter is not a table.** `ExportColumn` is deliberately not a table column: a table column knows how to sort, search and render HTML, and reusing it would put a badge's colour in a spreadsheet.
- **Nothing prunes old files.** See [Storage and cleanup](storage-cleanup.md).

## See also

- [ExportAction](export-action.md)
- [Columns and mapping](columns-mapping.md) — every `ExportColumn` method
- [Queued exports](queued-exports.md)
- [CSV and XLSX](csv-xlsx.md)
- [Storage and cleanup](storage-cleanup.md)
- [Importer classes](importers.md)
- [Import and export actions](../actions/import-export.md)
- [Table query builder](../tables/query-builder.md)
