# Importer Classes

A `PandaPanel\Actions\Imports\Importer` is what an import *is*: a model, a set of columns, and how a row becomes a record. You write one per thing that gets imported and hand its class name to [`ImportAction`](import-action.md).

It is a class for the same reason an exporter is one — a queued import runs in a different process from the request that uploaded the file, and only a class name crosses that gap. Everything on it is static.

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
            ImportColumn::make('email')->required()->rules(['email', 'max:255']),
        ];
    }
}
```

Two abstract methods, and that is a working import: every row with a name and a valid email address becomes a new `User`; every row without one goes to the failure report.

## Every method

| Method | Signature | Default |
| --- | --- | --- |
| `model` | `abstract public static function model(): string` | — required, `class-string<Model>` |
| `columns` | `abstract public static function columns(): array` | — required, `list<ImportColumn>` |
| `resolve` | `public static function resolve(array $data): ?Model` | `new $model` — an insert |
| `rules` | `public static function rules(): array` | `[]` |
| `chunkSize` | `public static function chunkSize(): int` | `200` — declared, but nothing reads it |
| `queueAfter` | `public static function queueAfter(): int` | `500` |
| `disk` | `public static function disk(): string` | `'local'` |
| `directory` | `public static function directory(): string` | `'panel-imports'` |
| `completedMessage` | `public static function completedMessage(int $imported, int $failed): string` | `Imported {n} rows.`, or a message naming the failures |

### `model()`

```php
public static function model(): string
{
    return User::class;
}
```

The model rows are written to. It is used twice: to build the blank instance `ImportRun` resolves relations and attribute names against, and as the default return of `resolve()`. The record `forceFill()` is called on is whatever `resolve()` handed back, which need not be an instance of this class at all.

### `columns()`

Where each cell lands and what it must be. Every `ImportColumn` setter is covered in [Columns and mapping](columns-mapping.md); the short version:

```php
use PandaPanel\Actions\Imports\ImportColumn;

public static function columns(): array
{
    return [
        ImportColumn::make('name')
            ->guess(['full name', 'user'])
            ->required()
            ->rules(['string', 'max:255']),
        ImportColumn::make('email')
            ->guess(['e-mail', 'email address'])
            ->required()
            ->rules(['email', 'max:255'])
            ->castUsing(static fn (string $value): string => mb_strtolower(trim($value))),
        ImportColumn::make('company')
            ->relationship('company', 'name')
            ->createRelated(),
    ];
}
```

The column list is also the write whitelist: rows are written with `forceFill()`, so a column you do not declare cannot be set from a file, and `$fillable` has no say over one you do. A password column is the obvious thing to leave out — a password that arrived in a spreadsheet is a password that was in a spreadsheet.

### `resolve()`

The method that turns an import into a re-import.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $data  the row, already cast and with relations resolved
 */
public static function resolve(array $data): ?Model
{
    $email = $data['email'] ?? null;

    if (! is_string($email) || $email === '') {
        return null;
    }

    $user = User::query()->where('email', $email)->first();

    if ($user !== null) {
        return $user;      // an update
    }

    // Only for a new account: an existing one keeps the password it has,
    // which a re-upload must not reset.
    return (new User)->forceFill([
        'password' => Hash::make(Str::random(32)),
        'email_verified_at' => null,
    ]);
}
```

| Return | Meaning |
| --- | --- |
| an existing model | the row is an **update** |
| a new model | the row is an **insert** |
| `null` | the row is **skipped** — nothing is written, and it counts toward `imported` rather than `failed` |

`$data` is keyed by column name and holds the values after `cast()` and after relation columns have been turned into foreign keys — so a `company` column reaches `resolve()` as an integer key, not as the text in the cell.

Returning `null` exists for a file that legitimately contains rows this import is not about: a mixed export where some rows are another kind of record. It is not the way to reject a bad row — a bad row belongs in the report, and validation puts it there.

Matching an existing record is what makes a failure report worth downloading: correct the four rows it names, upload the same file again, and the rows that already landed are updated in place rather than duplicated.

### `rules()`

Rules applied to the whole row, on top of the columns' own.

```php
public static function rules(): array
{
    return [
        'sku' => ['required_without:barcode'],
        'barcode' => ['required_without:sku'],
    ];
}
```

For the checks that are about a row rather than a cell — "one of these two must be present", a uniqueness rule that spans columns. They are merged after the per-column rules, so a key here replaces the column's own entry for that name.

The rules are Laravel's, applied per row with `Validator::make()`. That is the whole safety story for an import: a file is request input like any other, and the fact that it arrived as a spreadsheet does not make it trustworthy.

### `chunkSize()`

```php
public static function chunkSize(): int
{
    return 500;
}
```

Declared, with a default of `200`, but nothing in the package reads it. `ImportRun` streams the file one row at a time through a generator and saves each row in its own transaction, so an import already holds one row at a time whatever this returns and overriding it changes nothing. `Exporter::chunkSize()` is the one that is used, by `->lazy()`.

### `queueAfter()`

```php
public static function queueAfter(): int
{
    return 0;   // always queue this one
}
```

Above this many rows the import is dispatched to `PandaPanel\Jobs\RunPanelImport` rather than run in the request. The file is read to count its rows first, never estimated — a guess would put a large file in the request or a small one behind a queue nobody is watching.

| Value | Behaviour |
| --- | --- |
| `0` | always queued |
| `500` | the default — queued above 500 rows |
| any negative number | never queued |

### `disk()` and `directory()`

```php
public static function disk(): string
{
    return 'local';
}

public static function directory(): string
{
    return 'panel-imports';
}
```

Both the uploaded file and the failure report live here, and they are filed differently:

| File | Path |
| --- | --- |
| the upload | `{directory}/{random}.{ext}` |
| the failure report | `{directory}/{ownerKey}/failed-rows-{Y-m-d-His}.csv` |

The disk must be a **local** one. The reader is handed `Storage::disk($importer::disk())->path($stored)` and opens that with `fopen()` or `ZipArchive`, so a driver whose `path()` is not a readable filesystem path cannot be read.

### `completedMessage()`

```php
public static function completedMessage(int $imported, int $failed): string
{
    if ($failed === 0) {
        return sprintf('%d products updated.', $imported);
    }

    return sprintf('%d products updated, %d rows rejected.', $imported, $failed);
}
```

The title of the notification and the text of the toast, in both the inline and the queued path. The default already says the useful thing when rows failed: *"Imported 998 rows. 2 could not be imported — download the report to see why."*

## What a row does

`PandaPanel\Actions\Imports\ImportRun::run()` walks the file and, for each row, **inside its own transaction**:

1. reads the mapped cell for every declared column, or `''` when the column is unmapped;
2. casts it with `ImportColumn::cast()`;
3. resolves relation columns to foreign keys with `ImportColumn::resolveRelated()`;
4. validates the assembled row against the columns' `validationRules()` plus `Importer::rules()`;
5. calls `Importer::resolve()`, and moves on if it returned `null`;
6. `forceFill()`s the attributes onto the record — under `ImportColumn::attribute()`, so a relation column writes `company_id` — and saves.

A row that fails validation is recorded with the joined validator messages. A row that **throws** is recorded with the exception message. Neither stops the import: an import of a thousand rows where the four-hundredth has a bad date imports nine hundred and ninety-nine and writes the rest to a [failure report](failure-reports.md).

Each row being its own transaction is what stops one bad row from undoing the good rows before it, or from leaving behind a related record it had just created.

## Running one without the action

```php
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use PandaPanel\Actions\Imports\ImportRun;

$path = storage_path('app/private/panel-imports/people.csv');

$result = ImportRun::run(
    UserImporter::class,
    $path,                                                        // an absolute filesystem path
    ImportRun::guessMapping(UserImporter::class, ImportRun::headings($path)),
    $user->getKey(),                                              // whose report directory
);

// ['imported' => 998, 'failed' => 2, 'report' => 'failed-rows-2026-08-15-114233.csv']
```

The public helpers around it:

```php
ImportRun::headings(string $path): array;                                    // list<string>, the first row only
ImportRun::countRows(string $path): int;                                     // rows, not counting the header
ImportRun::guessMapping(string $importer, array $headings): array;           // column name => position
ImportRun::unmappedRequiredColumns(string $importer, array $mapping): array; // list<string>
ImportRun::missingColumnsMessage(array $missing, array $headings): string;
ImportRun::run(string $importer, string $path, array $mapping, int|string $owner): array;
```

`run()` is the same code the queued job calls. An import that behaved differently once the file got big would be a bug nobody found until it mattered.

## Notes

- **`resolve()` sees the row, not the record.** It is called after validation, so anything it reads has already passed the column rules.
- **A skipped row counts as imported.** `run()` increments `imported` for every row that produced no reason to reject it, and a `null` from `resolve()` is one of those. The count is "rows the import accepted", not "records written", so a file of five hundred rows where three hundred were not for this importer still reports five hundred imported. Say so in `completedMessage()` if that would mislead.
- **Relation columns are `BelongsTo` only.** Those are the relations whose value is a column of the row being imported. A `hasMany` cannot be set from one cell.
- **The importer never sees the file's headings.** Mapping is the action's job; `run()` is given positions.
- **The upload is deleted afterwards**, on success and on failure. It was a means, not a record.

## See also

- [ImportAction](import-action.md)
- [Columns and mapping](columns-mapping.md) — every `ImportColumn` method
- [Failure reports](failure-reports.md)
- [Queued imports](queued-imports.md)
- [Exporter classes](exporters.md)
- [Storage and cleanup](storage-cleanup.md)
- [Import and export actions](../actions/import-export.md)
- [Form validation](../forms/validation.md)
