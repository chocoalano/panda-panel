# Import and Export

Products in and out of a spreadsheet: an exporter, an importer, the two actions that put them on the table, and the endpoints that hand the files back. Read this page when a resource needs a "download as CSV" button or a bulk upload. It builds on [Product Resource](product-resource.md), and the shipped `UserExporter` / `UserImporter` in [`examples/`](../../examples/) are the same shapes against a smaller model.

There is no generator for either. Both are one class with two or three static methods, and a stub would be longer than the class.

## A minimal working example

```php
// app/Panels/Admin/Resources/Products/Exports/ProductExporter.php

namespace App\Panels\Admin\Resources\Products\Exports;

use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class ProductExporter extends Exporter
{
    /**
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('sku')->label('SKU'),
            ExportColumn::make('name'),
            ExportColumn::make('stock'),
        ];
    }
}
```

```php
// app/Panels/Admin/Resources/Products/Tables/ProductsTable.php

use App\Panels\Admin\Resources\Products\Exports\ProductExporter;
use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Actions\ExportAction;

->headerActions([
    ExportAction::make(ProductExporter::class, ProductResource::class),
])
```

That is a working export: a dialog offering the three columns and two formats, a file written to the `local` disk, a toast with a download link, and a notification with the same link.

## Why both are classes

An export or an import above a threshold is handed to a queued job that runs in a different process from the request that asked for it, and only a class name crosses that gap. A closure on the action could not. Everything on both is static, because there is no state either needs between rows that is not in the query or the file.

## The exporter

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class ProductExporter extends Exporter
{
    /**
     * The columns offered, in the order they are written.
     *
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('sku')->label('SKU'),
            ExportColumn::make('name'),
            // Dot notation reads through relations, so this is a column
            // rather than a reason to write a formatter.
            ExportColumn::make('category.name')->label('Category'),
            ExportColumn::make('price_cents')
                ->label('Price')
                ->formatUsing(static fn (mixed $value): string
                    => number_format((int) $value / 100, 2, '.', '')),
            ExportColumn::make('stock'),
            ExportColumn::make('is_published')->label('Published'),
            ExportColumn::make('created_at')->label('Added'),
            // Offered but unticked: useful occasionally, noise the rest of
            // the time.
            ExportColumn::make('description')->enabledByDefault(false),
        ];
    }

    /**
     * Shapes the query the export runs.
     *
     * The action hands over whatever the table was showing — the resource's
     * scope, and the current filters when the export was started from a
     * filtered list.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function query(Builder $query): Builder
    {
        // Eager load, or a relation column is one query per row. Reordered
        // so two exports of the same records can be compared line by line
        // regardless of how the list was sorted.
        return $query->with('category')->reorder('id');
    }

    public static function fileName(): string
    {
        return 'products-'.date('Y-m-d');
    }
}
```

### `Exporter`

| Method | Signature | Default |
| --- | --- | --- |
| `columns()` | `abstract static columns(): array` | required |
| `query()` | `static query(Builder $query): Builder` | the query unchanged |
| `fileName()` | `static fileName(): string` | kebab of the class basename plus `-Y-m-d-His` |
| `disk()` | `static disk(): string` | `'local'` |
| `directory()` | `static directory(): string` | `'panel-exports'` |
| `formats()` | `static formats(): array` | `[SpreadsheetFormat::Csv, SpreadsheetFormat::Xlsx]` |
| `escapesFormulas()` | `static escapesFormulas(): bool` | `true` |
| `chunkSize()` | `static chunkSize(): int` | `500` |
| `queueAfter()` | `static queueAfter(): int` | `2000` |
| `completedMessage()` | `static completedMessage(int $records): string` | "Your export of N records is ready." |

`disk()` is `local` rather than `public` on purpose. An export is a copy of records somebody was allowed to see, and a public disk would put it at a URL anybody can guess. The download goes through the panel, which asks the question again.

`escapesFormulas()` neutralises a CSV cell that a spreadsheet would otherwise run as a formula. Leave it on for a file a person opens; turn it off only for a file another *program* reads, where nothing evaluates anything and the leading apostrophe would be corruption rather than a fix.

`queueAfter()` is a number rather than a flag: a small export in a background job is a worse experience than the wait it avoided, and a large one in a request is a timeout. Zero always queues; a negative value never does.

### `ExportColumn`

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): self` | throws on an empty name |
| `label()` | `label(string $label): self` | headline of the name, with dots as spaces |
| `formatUsing()` | `formatUsing(Closure $callback): self` — `Closure(mixed, Model): mixed` | none |
| `enabledByDefault()` | `enabledByDefault(bool $enabled = true): self` | `true` |
| `toCell()` | `toCell(Model $record): string` | everything becomes a string here |

An export column is deliberately **not** a table column. A table column knows how to sort, search, and render; an export column turns a record into one string. Reusing the table's would put a badge's colour and an icon's registry key into a spreadsheet.

`toCell()` is where every value becomes a string, so the two file formats cannot disagree about what a boolean or a date looks like: `null` is `''`, a bool is `Yes`/`No`, a `DateTimeInterface` is `Y-m-d H:i:s`, a scalar is cast, and anything else is JSON — which is at least reversible.

Nothing sensitive belongs here. `tests/Feature/Panel/ImportExportTest.php` asserts that the shipped user exporter has no `password` column, which is the kind of thing worth a test rather than a comment.

## The importer

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Imports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Actions\Imports\Importer;

/**
 * Loads products from a spreadsheet.
 *
 * Matched on the SKU, so re-uploading a corrected file updates the products
 * it describes rather than creating second copies of them. That is what makes
 * a failure report worth downloading: fix the rows it names, upload the same
 * file again, and the others are updated in place.
 */
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
            ImportColumn::make('sku')
                ->label('SKU')
                ->guess(['code', 'product code', 'item number'])
                ->required()
                ->rules(['string', 'max:64'])
                ->castUsing(static fn (string $value): string => Str::upper(trim($value))),

            ImportColumn::make('name')
                ->guess(['title', 'product', 'product name'])
                ->required()
                ->rules(['string', 'max:255']),

            // A cell says "Peripherals"; this column says that is a
            // `category` matched by `name`, and the row gets a foreign key.
            ImportColumn::make('category')
                ->guess(['category name', 'group'])
                ->relationship('category', 'name'),

            ImportColumn::make('price_cents')
                ->label('Price')
                ->guess(['price', 'unit price'])
                ->required()
                ->rules(['integer', 'min:0'])
                ->castUsing(static fn (string $value): int
                    => (int) round(((float) str_replace([',', '$'], '', $value)) * 100)),

            ImportColumn::make('stock')
                ->guess(['quantity', 'qty', 'on hand'])
                ->rules(['integer', 'min:0'])
                ->castUsing(static fn (string $value): int => (int) $value),

            ImportColumn::make('is_published')
                ->label('Published')
                ->guess(['published', 'active', 'visible'])
                ->castUsing(static fn (string $value): bool => in_array(
                    mb_strtolower($value),
                    ['1', 'yes', 'y', 'true', 'published', 'active'],
                    true,
                )),
        ];
    }

    /**
     * Finds the record a row belongs to, or a new one.
     *
     * Returning an existing record makes the import an update; returning a
     * new one makes it an insert. Returning null skips the row without
     * counting it as a failure.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?Model
    {
        $sku = $data['sku'] ?? null;

        if (! is_string($sku) || $sku === '') {
            return null;
        }

        $product = Product::query()->where('sku', $sku)->first();

        if ($product !== null) {
            return $product;
        }

        $product = new Product;

        // Only for a new product: an existing one keeps the slug it has,
        // which a re-upload must not change.
        $product->forceFill([
            'slug' => Str::slug($sku).'-'.Str::lower(Str::random(6)),
        ]);

        return $product;
    }

    /**
     * Rules about the row rather than about a cell.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [];
    }
}
```

### `Importer`

| Method | Signature | Default |
| --- | --- | --- |
| `model()` | `abstract static model(): class-string<Model>` | required |
| `columns()` | `abstract static columns(): array` | required |
| `resolve()` | `static resolve(array $data): ?Model` | a new instance of `model()` — an insert |
| `rules()` | `static rules(): array` | `[]` |
| `chunkSize()` | `static chunkSize(): int` | `200` |
| `queueAfter()` | `static queueAfter(): int` | `500` |
| `disk()` | `static disk(): string` | `'local'` |
| `directory()` | `static directory(): string` | `'panel-imports'` |
| `completedMessage()` | `static completedMessage(int $imported, int $failed): string` | "Imported N rows." plus the failure sentence |

`resolve()` is the difference between the two things people mean by "import". The default inserts; overriding it to look a record up makes the import an update, which is what a file of corrections actually is.

### `ImportColumn`

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $name): self` | throws on an empty name |
| `label()` | `label(string $label): self` | headline of the name |
| `guess()` | `guess(array $guesses): self` | `[]` |
| `rules()` | `rules(array $rules): self` | `[]` |
| `required()` | `required(bool $required = true): self` | `false` |
| `castUsing()` | `castUsing(Closure $callback): self` — `Closure(string): mixed` | trim; `''` becomes null |
| `relationship()` | `relationship(string $relationship, string $column = 'name'): self` | none |
| `createRelated()` | `createRelated(bool $create = true): self` | `false` |
| `headings()` | `headings(): array` | the name, the label, and the guesses, lowercased |
| `validationRules()` | `validationRules(): array` | `required` or `nullable`, then `rules()` |

Three of those carry most of the weight.

`guess()` is what lets a file exported from somewhere else import without being edited first. A file calls the column "E-mail Address"; asking a person to rename it is asking them to do the computer's job. The mapping step still lets them correct a guess, and an explicit choice is never overridden by a heading that happens to look right.

`castUsing()` exists because a spreadsheet has no types. Every cell is a string, and `1` / `yes` / `TRUE` all mean the same thing to a person and nothing to a boolean column.

`relationship()` resolves the cell against a relation and stores the **foreign key**, so the importer never has to know that `category` means `category_id`. It works for a `BelongsTo` and nothing else — those are the relations whose value is a column of the row being imported. A `hasMany` cannot be set from one cell, and pretending otherwise would write a foreign key onto the wrong table.

`createRelated()` is off by default: silently creating rows in another table because a cell was misspelled is how an import turns one mistake into two. Without it, a lookup that finds nothing leaves the key null and the row fails validation on the key it did not get — which is a better answer than a record quietly attached to nothing.

The rules are Laravel's, applied per row. That is the whole safety story for an import: a file is request input like any other, and the fact that it arrived as a spreadsheet does not make it trustworthy.

## Wiring both onto the table

```php
use App\Panels\Admin\Resources\Products\Exports\ProductExporter;
use App\Panels\Admin\Resources\Products\Imports\ProductImporter;
use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;

->headerActions([
    CreateAction::make(ProductResource::class),
    ImportAction::make(ProductImporter::class, ProductResource::class),
    ExportAction::make(ProductExporter::class, ProductResource::class),
])
->bulkActions([
    DeleteBulkAction::make(ProductResource::class),
    // The selection as a spreadsheet, through the same dialog.
    ExportAction::bulk(ProductExporter::class, ProductResource::class),
])
```

```php
ExportAction::make(string $exporter, string $resource): Action   // the list, as currently filtered
ExportAction::bulk(string $exporter, string $resource): Action   // the selection only
ImportAction::make(string $importer, string $resource): Action
```

Both shapes of export open the same dialog — pick the columns, pick the format — because "which columns" is a question about the file, not about how the records were chosen.

The abilities they ask:

| Action | Ability |
| --- | --- |
| `ExportAction::make()` / `::bulk()` | `$resource::canViewAny()` |
| `ImportAction::make()` | `$resource::canCreate()` |

Import needs `create` rather than `update` even when `resolve()` only ever updates: a file that can create records is a file that can create records, and the weaker of the two abilities is the wrong one to ask.

## What each dialog does

**Export.** A checkbox list of the columns, ticked according to `enabledByDefault()`, and a radio of the formats `formats()` offers. The file is written in the schema's order regardless of the order the boxes were ticked in — a file whose columns move with a click order cannot be diffed against last week's.

**Import.** A `FileUpload` field and one searchable select per declared column. The file is uploaded first, by the ordinary upload endpoint, and the *submit* says which column of the file feeds which column of the import. That ordering is what makes mapping possible at all: the headings cannot be offered until the file exists.

The selects offer spreadsheet column letters — A, B, … Z, AA, AB — because that is what a spreadsheet shows in its own headers. "C" is findable in the file; "2" is not. The list stops at 200 columns, which is past the width of anything imported by hand; heading matching has no bound at all, so a column at position 300 is still mapped automatically if its heading is recognisable.

Leaving a select blank means "guess it from the headings". A column the user *did* choose is never overridden by a guess.

Before a single row is read, a required column that mapped to nothing fails the whole upload:

```text
This file has no column for [sku], and it is required. Its headings are:
Item Number, Title, Qty. Rename the column in the file, or map it by hand
before importing.
```

A required column with no heading would otherwise fail every row identically — ten thousand true statements about the wrong thing. The uploaded file is deleted when this happens, so a retry is a fresh upload rather than a stale path.

## Immediate or queued

| | Export | Import |
| --- | --- | --- |
| Threshold | `Exporter::queueAfter()`, default `2000` records | `Importer::queueAfter()`, default `500` rows |
| Counted | before anything is written | by reading the file's row count |
| Below it | runs in the request, toast with a link | runs in the request, toast plus a report link when rows failed |
| Above it | `PandaPanel\Jobs\RunPanelExport` | `PandaPanel\Jobs\RunPanelImport` |
| Result | a persistent notification with a download action | a persistent notification with the failure report |

The queued jobs carry the class name, the chosen columns or mapping, the format, the owner's key, the table state, and the panel id — never a closure and never a model.

```php
public static function queueAfter(): int
{
    return 0;      // always queue
}
```

Make sure a worker is running, or a queued export is a notification nobody ever gets. See [Queues](../deployment/queues.md).

## Where the files go, and who can read them

An export lands at:

```text
{disk}/{Exporter::directory()}/{user key}/{Exporter::fileName()}.{csv|xlsx}
```

The per-user segment is not decoration. Two routes hand files back, and both build that segment from the **authenticated user** rather than from the request:

| Route name | Path | Controller |
| --- | --- | --- |
| `panel.admin.export-file` | `GET /admin/exports/{file}` | `PandaPanel\Http\Controllers\PanelExportController` |
| `panel.admin.import-file` | `GET /admin/imports/{file}` | `PandaPanel\Http\Controllers\PanelImportController` |

The request names a **file**, never a path. A separator, a backslash, or a dot-segment is refused outright:

```php
abort_if(
    $file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..'),
    404,
);
```

So a traversal has nowhere to go — the caller never supplies a directory — and one user naming another's file finds nothing, because the lookup is in their own directory. `tests/Feature/Panel/Negative/FileAndDataAccessTest.php` states both halves as things that must not happen, and also asserts that a user *can* download their own file, so the refusals are not just a broken endpoint.

The `exporter` (or `importer`) class name travels in the query string and is checked with `is_subclass_of()` against the base class before its `disk()` and `directory()` are read.

## Failures are the point

A partial import is the intended outcome, not an error. One bad date in row four hundred should not cost the other nine hundred and ninety-nine.

```php
[
    'imported' => 998,
    'failed' => 2,
    'report' => 'failed-rows-2026-08-16-120000.csv',   // null when nothing failed
]
```

`report` is a file **name**, written into the importer's own per-user directory — the download endpoint builds the directory, so the name is all the request ever carries.

The report is the failed rows **as they were**, with the file's own headings plus an `Error` column saying why — so it can be corrected and re-uploaded as it stands, and with `resolve()` matching on a key, the rows that already imported are updated rather than duplicated.

A clean import is answered by a toast and nothing else. A bell that fills up with "imported 40 rows" is a bell nobody reads.

## The test

```php
<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Panels\Admin\Resources\Products\Exports\ProductExporter;
use App\Panels\Admin\Resources\Products\Imports\ProductImporter;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportRun;
use PandaPanel\Actions\Imports\ImportRun;
use PandaPanel\Support\Spreadsheet\Csv;

beforeEach(function (): void {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);
});

function productFile(string $extension): string
{
    return tempnam(sys_get_temp_dir(), 'product-io-').'.'.$extension;
}

it('writes the chosen columns in the order the exporter declared them', function (): void {
    Product::factory()->create(['name' => 'Keyboard', 'sku' => 'KB-001']);

    // Requested backwards on purpose: a file whose columns move with the
    // order of a checkbox list cannot be diffed against last week's.
    $result = ExportRun::write(
        ProductExporter::class,
        Product::query(),
        ['name', 'sku'],
        SpreadsheetFormat::Csv,
        $this->admin->getKey(),
    );

    $local = productFile('csv');

    file_put_contents($local, Storage::disk('local')->get($result['path']));

    // Read back rather than string-matched: the writer quotes a field with a
    // space in it, and asserting on the raw line would assert on that.
    $rows = iterator_to_array(Csv::read($local), false);

    expect($result['records'])->toBe(1)
        ->and($rows[0])->toBe(['SKU', 'Name'])
        ->and($rows[1])->toBe(['KB-001', 'Keyboard']);
});

it('files an export under the user it belongs to', function (): void {
    Product::factory()->create();

    $result = ExportRun::write(
        ProductExporter::class,
        Product::query(),
        [],
        SpreadsheetFormat::Csv,
        $this->admin->getKey(),
    );

    expect($result['path'])->toStartWith('panel-exports/'.$this->admin->getKey().'/');
});

it('does not let one user download another user\'s export', function (): void {
    $other = User::factory()->admin()->create();

    Storage::disk('local')->put(
        ProductExporter::directory().'/'.$other->getKey().'/products.csv',
        'sku,name',
    );

    // The name is right; the directory it lives in is not this user's.
    $this->get('/admin/exports/products.csv?exporter='.urlencode(ProductExporter::class))
        ->assertNotFound();
});

it('imports the rows it can and reports the ones it cannot', function (): void {
    Category::query()->create(['name' => 'Peripherals']);

    $path = productFile('csv');
    $handle = Csv::open($path);

    Csv::write($handle, ['sku', 'name', 'category', 'price', 'qty', 'published']);
    Csv::write($handle, ['KB-001', 'Keyboard', 'Peripherals', '129.00', '4', 'yes']);
    Csv::write($handle, ['MS-001', 'Mouse', 'Peripherals', 'not-a-price', '2', 'no']);

    fclose($handle);

    $result = ImportRun::run(
        ProductImporter::class,
        $path,
        // The same guess the dialog makes when a select is left blank.
        ImportRun::guessMapping(ProductImporter::class, ImportRun::headings($path)),
        $this->admin->getKey(),
    );

    expect($result['imported'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and(Product::query()->where('sku', 'KB-001')->exists())->toBeTrue()
        ->and(Product::query()->where('sku', 'MS-001')->exists())->toBeFalse();

    $report = (string) Storage::disk('local')
        ->get('panel-imports/'.$this->admin->getKey().'/'.$result['report']);

    expect($report)->toContain('not-a-price');
});

it('updates the product a re-uploaded row describes rather than duplicating it', function (): void {
    Product::factory()->create(['sku' => 'KB-001', 'name' => 'Old name']);

    $path = productFile('csv');
    $handle = Csv::open($path);

    Csv::write($handle, ['sku', 'name', 'price']);
    Csv::write($handle, ['KB-001', 'New name', '99.00']);

    fclose($handle);

    ImportRun::run(
        ProductImporter::class,
        $path,
        ImportRun::guessMapping(ProductImporter::class, ImportRun::headings($path)),
        $this->admin->getKey(),
    );

    expect(Product::query()->where('sku', 'KB-001')->count())->toBe(1)
        ->and(Product::query()->firstWhere('sku', 'KB-001')?->name)->toBe('New name');
});
```

Both runners are public API and both do the same work inside a request and inside a job, which is exactly why they are testable without HTTP:

```php
ExportRun::write(string $exporter, Builder $query, array $columns, SpreadsheetFormat $format, int|string $owner): array
ImportRun::run(string $importer, string $path, array $mapping, int|string $owner): array
ImportRun::headings(string $path): array
ImportRun::countRows(string $path): int
ImportRun::guessMapping(string $importer, array $headings): array
ImportRun::unmappedRequiredColumns(string $importer, array $mapping): array
```

```bash
php artisan test --compact --filter=ImportExport
```

## Gotchas

- **Both readers need a real path.** A CSV streams from a handle and an XLSX is a zip opened by name, so a file on a remote disk is copied locally for the length of the read.
- **`local`, not `public`.** Moving an export to a public disk puts a copy of records at a guessable URL. The download endpoint exists so the question is asked again.
- **Eager load in `query()`.** An export of ten thousand rows with a relation column is ten thousand queries without it.
- **A queued export with no worker is silence.** Nothing fails; the notification never arrives.
- **`relationship()` is `BelongsTo` only.** Any other relation is ignored and the cell is written to an attribute of the column's own name instead.
- **`castUsing()` receives a trimmed string, always.** There is no "the cell was a number" case to branch on.
- **A blank mapping select is a guess, not a skip.** To skip a column, do not declare it.
- **Formula escaping is on.** A CSV cell beginning `=`, `+`, `-` or `@` is neutralised. `tests/Feature/Panel/Negative/SpreadsheetFormulaTest.php` is that guarantee.
- **The exporter's column order is the file's column order.** The checkbox list decides which, never in what order.

## See also

- [Product Resource](product-resource.md) — the resource these act on
- [User Resource](user-resource.md) — the shipped `UserExporter` and `UserImporter`
- [Export Action](../import-export/export-action.md), [Import Action](../import-export/import-action.md)
- [Exporters](../import-export/exporters.md), [Importers](../import-export/importers.md)
- [Column Mapping](../import-export/columns-mapping.md)
- [CSV and XLSX](../import-export/csv-xlsx.md)
- [Queued Exports](../import-export/queued-exports.md), [Queued Imports](../import-export/queued-imports.md)
- [Failure Reports](../import-export/failure-reports.md)
- [Storage and Cleanup](../import-export/storage-cleanup.md)
- [Notifications](../import-export/notifications.md)
- [Import and Export Troubleshooting](../troubleshooting/import-export.md)
- [Security](security.md)
