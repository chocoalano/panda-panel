<?php

declare(strict_types=1);

namespace PandaPanel\Support\Spreadsheet;

use Generator;

/**
 * CSV, written and read a row at a time.
 *
 * A stream rather than a string on both sides. An export of fifty thousand
 * records assembled in memory is a memory limit waiting to happen, and an
 * import read with `file()` is the same failure from the other direction —
 * so nothing here ever holds more than one row.
 */
final class Csv
{
    /**
     * Opens a file for writing and returns the handle, with a BOM already
     * written.
     *
     * The BOM is what makes Excel read UTF-8 as UTF-8 instead of as the
     * host's code page, which is the difference between a name and mojibake.
     *
     * @return resource
     */
    public static function open(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new SpreadsheetException(sprintf('Cannot write to %s.', $path));
        }

        fwrite($handle, "\u{FEFF}");

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     */
    public static function write($handle, array $row): void
    {
        fputcsv($handle, $row, escape: '');
    }

    /**
     * Reads a file row by row.
     *
     * A generator, so a caller decides how much of the file it wants and the
     * rest is never read — which is what makes a header-only peek cheap.
     *
     * @return Generator<int, list<string>>
     */
    public static function read(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new SpreadsheetException(sprintf('Cannot read %s.', $path));
        }

        try {
            $first = true;

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                // A row of one empty cell is what a blank line reads as, and
                // it is not a record.
                if ($row === [null] || $row === ['']) {
                    continue;
                }

                $cells = array_map(
                    static fn (mixed $cell): string => $cell === null ? '' : (string) $cell,
                    $row,
                );

                if ($first) {
                    // Strip the BOM a spreadsheet wrote, or the first header
                    // is named "\u{FEFF}id" and matches nothing.
                    $cells[0] = preg_replace('/^\x{FEFF}/u', '', $cells[0]) ?? $cells[0];
                    $first = false;
                }

                yield $cells;
            }
        } finally {
            fclose($handle);
        }
    }
}
