# Columns And Mapping

A column is the smallest unit of an import or an export: on the way out it turns a record into one string, and on the way in it says where a cell lands and what it must be. Mapping is the step in between on the import side — which column of *this file* feeds which column of the importer.

This page covers `PandaPanel\Actions\Exports\ExportColumn` and `PandaPanel\Actions\Imports\ImportColumn` in full, then the mapping step that connects an uploaded file to the second.

## A minimal working example

```php
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Imports\ImportColumn;

// Out: the value at `author.name`, headed "Author".
ExportColumn::make('author.name')->label('Author');

// In: column "E-mail Address" of the file, lowercased, required, validated.
ImportColumn::make('email')
    ->label('Email')
    ->guess(['e-mail', 'email address'])
    ->required()
    ->rules(['email', 'max:255'])
    ->castUsing(static fn (string $value): string => mb_strtolower(trim($value)));
```

The two are separate classes because they do opposite jobs, and neither is a table column: a table column knows how to sort, search and render HTML, and reusing it would put a badge's colour and an icon's registry key in a spreadsheet.

## `ExportColumn`

```php
use Closure;
use Illuminate\Database\Eloquent\Model;

ExportColumn::make(string $name): self;
ExportColumn::label(string $label): self;
ExportColumn::formatUsing(Closure $callback): self;          // fn (mixed $value, Model $record): mixed
ExportColumn::enabledByDefault(bool $enabled = true): self;

ExportColumn::getName(): string;
ExportColumn::getLabel(): string;
ExportColumn::isEnabledByDefault(): bool;
ExportColumn::toCell(Model $record): string;
```

### `make()`

```php
ExportColumn::make('email');
ExportColumn::make('company.name');   // read with data_get(), so dot notation walks relations
```

An empty name throws `PanelSchemaException::emptyName('export column')`. The name is how the value is found and how the dialog keys its selection, so it cannot be blank.

### `label()`

```php
ExportColumn::make('created_at')->label('Joined');
```

The heading written into the file and the text beside the checkbox in the dialog. Without one it is `Str::headline()` of the name with dots turned into spaces — `company.name` becomes `Company Name`.

### `formatUsing()`

```php
use Illuminate\Database\Eloquent\Model;

ExportColumn::make('total')
    ->formatUsing(static fn (mixed $value, Model $record): string => number_format((float) $value, 2));

ExportColumn::make('status')
    ->formatUsing(static fn (mixed $value, Model $record): string => $record->isLate() ? 'Late' : (string) $value);
```

The callback receives the raw value and the record, and runs before the value is turned into a cell. Returning something that is not a string is fine — the conversion below still applies.

### `enabledByDefault()`

```php
ExportColumn::make('internal_note')->enabledByDefault(false);
```

Offered in the dialog but unticked, for a column most exports do not want: an internal note, a large blob, a column that is only occasionally the point. It affects only the dialog's default; a user can still tick it, and an export run through `ExportRun::write()` with `columns: []` writes every column regardless.

### `toCell()`

Everything becomes a string here rather than in the writer, so the two file formats cannot disagree about what a boolean or a date looks like.

| Value | Cell |
| --- | --- |
| `null` | `''` |
| `bool` | `Yes` / `No` |
| `DateTimeInterface` | `Y-m-d H:i:s` |
| any other scalar | cast to string |
| array or object | `json_encode()` |

```php
$column = ExportColumn::make('is_admin');

$column->toCell($user);    // 'Yes'
```

A date that needs another format is a `formatUsing()` away:

```php
use Illuminate\Support\Carbon;

ExportColumn::make('created_at')
    ->formatUsing(static fn (mixed $value): string => $value instanceof Carbon ? $value->toDateString() : '');
```

## `ImportColumn`

```php
use Closure;
use Illuminate\Database\Eloquent\Model;

ImportColumn::make(string $name): self;
ImportColumn::label(string $label): self;
ImportColumn::guess(array $guesses): self;                                   // list<string>
ImportColumn::rules(array $rules): self;                                     // list<mixed>
ImportColumn::required(bool $required = true): self;
ImportColumn::castUsing(Closure $callback): self;                            // fn (string $value): mixed
ImportColumn::relationship(string $relationship, string $column = 'name'): self;
ImportColumn::createRelated(bool $create = true): self;

ImportColumn::getName(): string;
ImportColumn::getLabel(): string;
ImportColumn::isRequired(): bool;
ImportColumn::getRelationship(): ?string;
ImportColumn::headings(): array;                                             // list<string>, lowercased
ImportColumn::validationRules(): array;
ImportColumn::cast(string $value): mixed;
ImportColumn::resolveRelated(Model $model, mixed $value): ?int;
ImportColumn::attribute(Model $model): string;
```

### `make()` and `label()`

```php
ImportColumn::make('email')->label('Email address');
```

The name is the key in the row array `resolve()` receives and, unless the column is a relation, the attribute written to the model. An empty name throws `PanelSchemaException::emptyName('import column')`. Without a label it is `Str::headline()` of the name — note that unlike the export column, dots are **not** replaced.

### `guess()`

```php
ImportColumn::make('email')->guess(['e-mail', 'email address', 'e-mail address']);
```

Extra headings this column recognises itself under. A file exported from somewhere else calls the column "E-mail Address", and asking a person to rename it before importing is asking them to do the computer's job.

`headings()` is the full set a column answers to, lowercased and trimmed and de-duplicated: **its name, its label, and everything in `guess()`**.

```php
ImportColumn::make('email')->label('Email')->guess(['e-mail'])->headings();
// ['email', 'e-mail']
```

### `required()` and `rules()`

```php
ImportColumn::make('name')->required()->rules(['string', 'max:255']);
ImportColumn::make('notes')->rules(['string', 'max:2000']);
```

`validationRules()` is `required` or `nullable` followed by whatever `rules()` was given:

```php
ImportColumn::make('name')->required()->rules(['string'])->validationRules();
// ['required', 'string']
```

`required()` does one more thing: a required column that ends up **unmapped** stops the import before a single row is read. See the mapping section below.

### `castUsing()`

```php
ImportColumn::make('is_admin')
    ->castUsing(static fn (string $value): bool => in_array(
        mb_strtolower($value),
        ['1', 'yes', 'y', 'true', 'admin'],
        true,
    ));

ImportColumn::make('starts_at')
    ->castUsing(static fn (string $value): ?string => $value === ''
        ? null
        : Carbon::parse($value)->toDateTimeString());
```

A spreadsheet has no types: every cell is a string, and `1`, `yes` and `TRUE` all mean the same thing to a person and nothing to a boolean column. The callback receives the cell already trimmed.

Without one, `cast()` trims the value and turns an empty string into `null`:

```php
ImportColumn::make('name')->cast('  Grace  ');   // 'Grace'
ImportColumn::make('name')->cast('   ');         // null
```

Casting runs **before** validation, so the rules see the value the column will actually hold.

### `relationship()` and `createRelated()`

```php
ImportColumn::make('company')->relationship('company', 'name');
ImportColumn::make('company')->relationship('company', 'name')->createRelated();
```

A file says "Acme Ltd", the column says "that is a `company` matched by `name`", and the row gets a foreign key. Declaring it here rather than in the importer means the lookup is written once and the same failure — no such company — is reported per row rather than throwing halfway through the file.

| Method | Signature | Default |
| --- | --- | --- |
| `relationship` | `relationship(string $relationship, string $column = 'name'): self` | none; `$column` defaults to `name` |
| `createRelated` | `createRelated(bool $create = true): self` | `false` |

What happens per row:

```php
ImportColumn::make('company')->relationship('company')->resolveRelated($blankUser, 'Acme Ltd');
// the company's key as an int, or null
```

- The relation must exist on the model **and** be a `BelongsTo`. Anything else — a `hasMany`, a method that is not a relation, a name with no method at all — resolves to `null` and the column falls back to writing its own name as an attribute. Those are the relations whose value is a column of the row being imported; a `hasMany` cannot be set from one cell.
- A `null` or empty value resolves to `null` without a query.
- The lookup is `$related->newQuery()->where($column, $value)->first()`.
- With `createRelated()`, a miss creates the related record with `[$column => $value]`. It is off by default: silently creating rows in another table because a cell was misspelled is how an import turns one mistake into two.
- A key that is not numeric resolves to `null`. Relation columns therefore assume an integer key.

When the lookup finds nothing and nothing was created, the value is `null` and the row fails validation on the key it did not get — which is a better answer than a record quietly attached to nothing. Make that a real message by marking the column required:

```php
ImportColumn::make('company')->relationship('company')->required();
// "The company field is required." on the rows whose company does not exist
```

`attribute()` is what the resolved value is written to: the relation's foreign key name when there is a `BelongsTo`, otherwise the column's own name. The importer never has to know that `company` means `company_id`.

## The mapping step

An import needs to know which position in each row feeds which column. That is a `array<string, int>` — column name to **zero-based position** — and it is built from two sources.

### Guessing from the headings

```php
use PandaPanel\Actions\Imports\ImportRun;

ImportRun::headings(string $path): array;                            // the first row only
ImportRun::guessMapping(string $importer, array $headings): array;   // name => position
```

```php
$headings = ImportRun::headings($path);              // ['Full Name', 'E-Mail Address', 'unused']
$mapping = ImportRun::guessMapping(UserImporter::class, $headings);
// ['name' => 0, 'email' => 1]
```

Each heading is lowercased and trimmed, and each column takes the first of its own `headings()` that matches. A column with **no** match is absent from the result rather than pointing at position zero — the dialog then shows it unmapped instead of importing the first column into it:

```php
ImportRun::guessMapping(UserImporter::class, ['nothing', 'like it']);   // []
```

Only the first row of the file is read, which is what makes asking "what is in this file" cheap enough to do while the user waits.

### Correcting it by hand

The import dialog renders one select per column, named `map_{column}`:

```php
Select::make('map_email')->label('Email')->options(/* positions */)->searchable();
```

The options are spreadsheet positions rather than the file's headings, because the form is built before any file has been uploaded:

| Option key | Label |
| --- | --- |
| `col0` | `A` |
| `col25` | `Z` |
| `col26` | `AA` |
| `col51` | `AZ` |
| `col52` | `BA` |
| `col199` | `GR` — the last one offered |

Two hundred positions are offered. That is past the width of every spreadsheet anybody imports by hand and cheap to render behind a searchable select. The labels are bijective base-26, so the sequence runs A–Z then AA — the same thing a spreadsheet shows in its own column headers, because "C" is findable in the file and "2" is not.

The submitted value is parsed off the key: `col29` becomes position `29`, whatever its label says.

### How the two combine

```php
$mapping = ImportRun::guessMapping($importer, $headings);

foreach ($importer::columns() as $column) {
    $chosen = $data['map_'.$column->getName()] ?? null;

    if (is_string($chosen) && str_starts_with($chosen, 'col')) {
        $mapping[$column->getName()] = (int) mb_substr($chosen, 3);
    }
}
```

The guess fills in everything, and an explicit choice then replaces it. An explicit choice is never overridden by a heading that happens to look right, and a blank select is never treated as "position zero".

### Required columns with nowhere to come from

```php
ImportRun::unmappedRequiredColumns(string $importer, array $mapping): array;
ImportRun::missingColumnsMessage(array $missing, array $headings): string;
```

Before a single row is read, the action checks that every required column found a position. If one did not, the upload is deleted and a `ValidationException` is thrown on the `file` field:

```text
This file has no column for [email], and it is required. Its headings are: Full Name, Address.
Rename the column in the file, or map it by hand before importing.
```

A required column with no heading would otherwise fail every row identically, which is ten thousand true statements about the wrong thing — the file does not have that column at all.

### What an unmapped optional column does

Nothing dramatic. The reader takes `''` for it, `cast()` turns that into `null`, and the column's rules decide the rest. It is exactly what a file missing that column would mean.

## Notes

- **Positions are positions, not headings.** A file whose columns move between exports needs the mapping checked again; a file whose headings change is handled by `guess()`.
- **A column beyond position 200 cannot be mapped by hand**, but is still mapped automatically as long as its heading is recognisable — heading matching has no bound at all.
- **Blank cells do not shift a row.** The XLSX reader fills gaps back in from each cell's `r` reference, so `A1` and `C1` stay columns 0 and 2. The CSV reader skips a blank *line* but keeps blank cells.
- **A byte-order mark is stripped from the first heading**, or the first column would be named `\u{FEFF}id` and match nothing.
- **The export dialog and the import mapping are both plain form fields**, so anything they accept is validated by the same rules any other choice field uses.

## See also

- [Exporter classes](exporters.md) and [Importer classes](importers.md)
- [ExportAction](export-action.md) and [ImportAction](import-action.md)
- [CSV and XLSX](csv-xlsx.md) — how a row is read in the first place
- [Failure reports](failure-reports.md)
- [Table columns](../tables/columns.md) — the other kind of column, and why it is not this one
- [Form validation](../forms/validation.md)
