<?php

declare(strict_types=1);

use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Support\Spreadsheet\Csv;
use PandaPanel\Support\Spreadsheet\SpreadsheetException;
use PandaPanel\Support\Spreadsheet\Xlsx;

/**
 * A temporary path, cleaned up after each test by the hook below.
 */
function scratchFile(string $extension): string
{
    return tempnam(sys_get_temp_dir(), 'panel-test-').'.'.$extension;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/panel-test-*') ?: [] as $file) {
        @unlink($file);
    }
});

/*
 * CSV
 */

it('writes a CSV a spreadsheet reads as UTF-8', function (): void {
    $path = scratchFile('csv');

    $handle = Csv::open($path);

    Csv::write($handle, ['Name', 'Email']);
    Csv::write($handle, ['Grace Hopper', 'grace@example.test']);

    fclose($handle);

    $contents = (string) file_get_contents($path);

    // The BOM is what makes Excel read UTF-8 as UTF-8 rather than as the
    // host's code page.
    expect($contents)->toStartWith("\u{FEFF}")
        ->and($contents)->toContain('Grace Hopper');
});

it('reads a CSV back without the byte-order mark in the first heading', function (): void {
    $path = scratchFile('csv');

    $handle = Csv::open($path);

    Csv::write($handle, ['id', 'name']);
    Csv::write($handle, ['1', 'Apollo']);

    fclose($handle);

    $rows = iterator_to_array(Csv::read($path), false);

    // Without stripping it the first heading is "\u{FEFF}id" and matches
    // nothing in the mapping step.
    expect($rows[0])->toBe(['id', 'name'])
        ->and($rows[1])->toBe(['1', 'Apollo']);
});

it('skips a blank line rather than reading it as a record', function (): void {
    $path = scratchFile('csv');

    file_put_contents($path, "id,name\n\n1,Apollo\n");

    expect(iterator_to_array(Csv::read($path), false))->toBe([
        ['id', 'name'],
        ['1', 'Apollo'],
    ]);
});

/*
 * XLSX
 */

it('writes a workbook it can read back', function (): void {
    $path = scratchFile('xlsx');

    Xlsx::write($path, [
        ['Name', 'Email'],
        ['Grace Hopper', 'grace@example.test'],
    ]);

    expect(iterator_to_array(Xlsx::read($path), false))->toBe([
        ['Name', 'Email'],
        ['Grace Hopper', 'grace@example.test'],
    ]);
});

it('keeps a value as typed rather than as a spreadsheet would guess', function (): void {
    $path = scratchFile('xlsx');

    Xlsx::write($path, [['code'], ['007']]);

    // A number written as a number loses its leading zeros, which is exactly
    // how a spreadsheet destroys an order reference.
    expect(iterator_to_array(Xlsx::read($path), false)[1])->toBe(['007']);
});

it('keeps a row aligned when a cell in the middle is empty', function (): void {
    $path = scratchFile('xlsx');

    Xlsx::write($path, [
        ['a', 'b', 'c'],
        ['1', '', '3'],
    ]);

    // A spreadsheet omits empty cells entirely; reading the reference back
    // into a position is what stops every later value shifting left.
    expect(iterator_to_array(Xlsx::read($path), false)[1])->toBe(['1', '', '3']);
});

it('survives a control character a column happened to hold', function (): void {
    $path = scratchFile('xlsx');

    Xlsx::write($path, [['note'], ["bad\x00value"]]);

    // Illegal in XML at all: one of these makes the whole file unopenable.
    expect(iterator_to_array(Xlsx::read($path), false)[1])->toBe(['badvalue']);
});

it('escapes markup rather than writing it as markup', function (): void {
    $path = scratchFile('xlsx');

    Xlsx::write($path, [['note'], ['<b>bold</b> & "quoted"']]);

    expect(iterator_to_array(Xlsx::read($path), false)[1])
        ->toBe(['<b>bold</b> & "quoted"']);
});

it('refuses a file that is not a workbook', function (): void {
    $path = scratchFile('xlsx');

    file_put_contents($path, 'not a zip');

    expect(static fn () => iterator_to_array(Xlsx::read($path), false))
        ->toThrow(SpreadsheetException::class);
});

/*
 * Formats
 */

it('reads a format from the extension and defaults to CSV', function (): void {
    expect(SpreadsheetFormat::fromPath('/tmp/a.xlsx'))->toBe(SpreadsheetFormat::Xlsx)
        ->and(SpreadsheetFormat::fromPath('/tmp/a.XLSX'))->toBe(SpreadsheetFormat::Xlsx)
        ->and(SpreadsheetFormat::fromPath('/tmp/a.csv'))->toBe(SpreadsheetFormat::Csv)
        // An extension is a claim, and anything unrecognised is read as the
        // format that copes with the widest range of files.
        ->and(SpreadsheetFormat::fromPath('/tmp/a.txt'))->toBe(SpreadsheetFormat::Csv);
});
