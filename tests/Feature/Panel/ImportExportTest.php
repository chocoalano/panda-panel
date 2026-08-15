<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\ExportRun;
use PandaPanel\Actions\ImportAction;
use PandaPanel\Actions\Imports\ImportRun;
use PandaPanel\Support\Spreadsheet\Csv;
use PandaPanel\Support\Spreadsheet\Xlsx;

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/panel-io-*') ?: [] as $file) {
        @unlink($file);
    }
});

function ioFile(string $extension): string
{
    return tempnam(sys_get_temp_dir(), 'panel-io-').'.'.$extension;
}

/*
 * Export
 */

it('writes the chosen columns in the order the exporter declared them', function (): void {
    Storage::fake('local');

    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);

    // Requested backwards on purpose: a file whose columns move with the
    // order of a checkbox list cannot be diffed against last week's.
    $result = ExportRun::write(
        UserExporter::class,
        User::query(),
        ['email', 'name'],
        SpreadsheetFormat::Csv,
        7,
    );

    $local = ioFile('csv');

    file_put_contents($local, Storage::disk('local')->get($result['path']));

    // Read back rather than string-matched: `fputcsv` quotes a field with a
    // space in it, and asserting on the raw line would be asserting on that
    // rather than on the export.
    $rows = iterator_to_array(Csv::read($local), false);

    expect($result['records'])->toBe(1)
        ->and($rows[0])->toBe(['Name', 'Email'])
        ->and($rows[1])->toBe(['Grace Hopper', 'grace@example.test']);
});

it('files an export under the user it belongs to', function (): void {
    Storage::fake('local');

    User::factory()->create();

    $result = ExportRun::write(
        UserExporter::class,
        User::query(),
        [],
        SpreadsheetFormat::Csv,
        42,
    );

    // The download endpoint builds that segment from whoever is asking, so
    // one user cannot name another's export.
    expect($result['path'])->toStartWith('panel-exports/42/');
});

it('writes an XLSX that reads back as the same table', function (): void {
    Storage::fake('local');

    User::factory()->create(['name' => 'Grace Hopper']);

    $result = ExportRun::write(
        UserExporter::class,
        User::query(),
        ['name'],
        SpreadsheetFormat::Xlsx,
        1,
    );

    $local = ioFile('xlsx');

    file_put_contents($local, Storage::disk('local')->get($result['path']));

    $rows = iterator_to_array(Xlsx::read($local), false);

    expect($rows[0])->toBe(['Name'])
        ->and($rows[1])->toBe(['Grace Hopper']);
});

it('never offers a password as a column to export', function (): void {
    $names = array_map(
        static fn (ExportColumn $column): string => $column->getName(),
        UserExporter::columns(),
    );

    expect($names)->not->toContain('password');
});

it('exports only what the query was narrowed to', function (): void {
    Storage::fake('local');

    User::factory()->create(['name' => 'Kept']);
    User::factory()->create(['name' => 'Dropped']);

    $result = ExportRun::write(
        UserExporter::class,
        User::query()->where('name', 'Kept'),
        ['name'],
        SpreadsheetFormat::Csv,
        1,
    );

    $contents = (string) Storage::disk('local')->get($result['path']);

    expect($result['records'])->toBe(1)
        ->and($contents)->toContain('Kept')
        ->and($contents)->not->toContain('Dropped');
});

/*
 * Import
 */

it('imports the rows it can and reports the ones it cannot', function (): void {
    Storage::fake('local');

    $path = ioFile('csv');
    $handle = Csv::open($path);

    Csv::write($handle, ['name', 'email', 'is_admin']);
    Csv::write($handle, ['Grace Hopper', 'grace@example.test', 'yes']);
    Csv::write($handle, ['Broken', 'not-an-email', 'no']);
    Csv::write($handle, ['Alan Turing', 'alan@example.test', '']);

    fclose($handle);

    $result = ImportRun::run(
        UserImporter::class,
        $path,
        ImportRun::guessMapping(UserImporter::class, ImportRun::headings($path)),
        5,
    );

    // One bad address must not cost the other two rows.
    expect($result['imported'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and(User::query()->where('email', 'grace@example.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'not-an-email')->exists())->toBeFalse();

    $report = (string) Storage::disk('local')->get('panel-imports/5/'.$result['report']);

    // The row as it was, plus why — so it can be corrected and re-uploaded
    // as it stands.
    expect($report)->toContain('Broken')
        ->and($report)->toContain('not-an-email')
        ->and($report)->toContain('Error');
});

it('casts a spreadsheet\'s text into the value a column holds', function (): void {
    Storage::fake('local');

    $path = ioFile('csv');
    $handle = Csv::open($path);

    Csv::write($handle, ['name', 'email', 'is_admin']);
    Csv::write($handle, ['Grace Hopper', 'GRACE@Example.test', 'Yes']);

    fclose($handle);

    ImportRun::run(
        UserImporter::class,
        $path,
        ImportRun::guessMapping(UserImporter::class, ImportRun::headings($path)),
        1,
    );

    $user = User::query()->where('email', 'grace@example.test')->first();

    // A spreadsheet has no types: "Yes" is a boolean to a person and a string
    // to a column.
    expect($user)->not->toBeNull()
        ->and($user?->is_admin)->toBeTrue();
});

it('updates the account a re-uploaded row describes rather than duplicating it', function (): void {
    Storage::fake('local');

    User::factory()->create(['name' => 'Old name', 'email' => 'grace@example.test']);

    $path = ioFile('csv');
    $handle = Csv::open($path);

    Csv::write($handle, ['name', 'email']);
    Csv::write($handle, ['Grace Hopper', 'grace@example.test']);

    fclose($handle);

    ImportRun::run(
        UserImporter::class,
        $path,
        ImportRun::guessMapping(UserImporter::class, ImportRun::headings($path)),
        1,
    );

    expect(User::query()->where('email', 'grace@example.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'grace@example.test')->value('name'))
        ->toBe('Grace Hopper');
});

it('recognises a heading a file from somewhere else used', function (): void {
    $mapping = ImportRun::guessMapping(
        UserImporter::class,
        ['Full Name', 'E-Mail Address', 'unused'],
    );

    // Asking a person to rename a column before importing is asking them to
    // do the computer's job.
    expect($mapping['name'])->toBe(0)
        ->and($mapping['email'])->toBe(1);
});

it('leaves a column unmapped rather than pointing it at the first one', function (): void {
    $mapping = ImportRun::guessMapping(UserImporter::class, ['nothing', 'like it']);

    expect($mapping)->toBe([]);
});

it('counts the rows a file holds, not its lines', function (): void {
    $path = ioFile('csv');
    $handle = Csv::open($path);

    Csv::write($handle, ['name', 'email']);
    Csv::write($handle, ['One', 'one@example.test']);
    Csv::write($handle, ['Two', 'two@example.test']);

    fclose($handle);

    // The header is not a record.
    expect(ImportRun::countRows($path))->toBe(2);
});

it('reads an XLSX import exactly as it reads a CSV one', function (): void {
    Storage::fake('local');

    $path = ioFile('xlsx');

    Xlsx::write($path, [
        ['name', 'email'],
        ['Grace Hopper', 'grace@example.test'],
    ]);

    $result = ImportRun::run(
        UserImporter::class,
        $path,
        ImportRun::guessMapping(UserImporter::class, ImportRun::headings($path)),
        1,
    );

    expect($result['imported'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and(User::query()->where('email', 'grace@example.test')->exists())->toBeTrue();
});

it('never takes a password from a spreadsheet', function (): void {
    $names = array_map(
        static fn ($column): string => $column->getName(),
        UserImporter::columns(),
    );

    // A password that arrived in a spreadsheet is a password that was in a
    // spreadsheet.
    expect($names)->not->toContain('password');
});

/*
 * Column mapping
 *
 * The select that says "which column of the file is this field" used to be
 * built with `chr(65 + $index)` over `range(0, 25)`. That is right for exactly
 * twenty-six columns and unfixable by hand after that: a spreadsheet with
 * thirty columns had its last four unmappable, and index 26 would have
 * rendered as `[`.
 */

it('spells column positions the way a spreadsheet does, past Z', function (): void {
    $label = new ReflectionMethod(ImportAction::class, 'columnLabel');
    $label->setAccessible(true);

    expect($label->invoke(null, 0))->toBe('A')
        ->and($label->invoke(null, 25))->toBe('Z')
        // The boundary the old implementation got wrong.
        ->and($label->invoke(null, 26))->toBe('AA')
        ->and($label->invoke(null, 27))->toBe('AB')
        ->and($label->invoke(null, 51))->toBe('AZ')
        ->and($label->invoke(null, 52))->toBe('BA')
        ->and($label->invoke(null, 701))->toBe('ZZ')
        ->and($label->invoke(null, 702))->toBe('AAA');
});

it('offers far more than twenty-six columns to map', function (): void {
    $positions = new ReflectionMethod(ImportAction::class, 'positions');
    $positions->setAccessible(true);

    $options = $positions->invoke(null);

    expect($options)->toHaveCount(200)
        ->and($options['col0'])->toBe('A')
        ->and($options['col25'])->toBe('Z')
        ->and($options['col26'])->toBe('AA')
        // Keys stay `col{index}`, which is what `mapping()` parses back into
        // the integer position the reader uses.
        ->and(array_key_last($options))->toBe('col199');
});

it('maps a column beyond Z onto the right position', function (): void {
    $mapping = new ReflectionMethod(ImportAction::class, 'mapping');
    $mapping->setAccessible(true);

    // `col29` is column AD — the thirtieth. Parsed off the key rather than
    // decoded from the label, so a wider file needs no new parsing.
    $result = $mapping->invoke(null, UserImporter::class, ['map_name' => 'col29'], []);

    expect($result['name'])->toBe(29);
});
