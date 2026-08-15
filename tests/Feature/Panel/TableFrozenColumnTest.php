<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\ColumnPin;
use PandaPanel\Tables\TableSchema;

/*
 * Freezing is half server and half browser: the server says which columns are
 * pinned and to which edge, and the browser works out the offsets from the
 * widths the columns actually take. These cover the half that can be asserted
 * here, plus the invariants the other half depends on.
 */

it('is not frozen unless a column says so', function (): void {
    $definition = TextColumn::make('name')->toArray();

    expect($definition['frozen'])->toBeNull();
});

it('pins to the leading edge by default', function (): void {
    expect(TextColumn::make('name')->frozen()->toArray()['frozen'])->toBe('start')
        ->and(TextColumn::make('name')->frozen()->getFrozen())->toBe(ColumnPin::Start);
});

it('pins to the edge it was given', function (): void {
    expect(TextColumn::make('total')->frozen(ColumnPin::End)->toArray()['frozen'])
        ->toBe('end');
});

it('unfreezes a column', function (): void {
    // The setter has to be able to say no, for a column frozen by a shared
    // base schema that one table does not want pinned.
    expect(TextColumn::make('name')->frozen()->frozen(false)->toArray()['frozen'])
        ->toBeNull();
});

it('is available on every column type', function (): void {
    // "Every column supports freeze" is the requirement, and the only thing
    // that makes it true is that `frozen()` lives on the base class rather
    // than on the handful of types somebody remembered.
    $types = array_map(
        static fn (SplFileInfo $file): string => 'PandaPanel\\Tables\\Columns\\'
            .$file->getFilenameWithoutExtension(),
        array_filter(
            File::files(base_path('src/Tables/Columns')),
            static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        ),
    );

    $concrete = array_filter(
        $types,
        static fn (string $type): bool => class_exists($type)
            && ! (new ReflectionClass($type))->isAbstract(),
    );

    expect($concrete)->not->toBeEmpty();

    foreach ($concrete as $type) {
        expect(method_exists($type, 'frozen'))->toBeTrue();
    }
});

/*
 * What the table tells the frontend
 */

it('says nothing is pinned for an ordinary table', function (): void {
    $table = TableSchema::make()->columns([TextColumn::make('name')])->toArray();

    expect($table['frozen'])->toBe(['start' => false, 'actions' => false]);
});

it('reports a leading pin so the structural cells can join it', function (): void {
    // The reorder handle and the checkbox sit to the left of every data
    // column. A frozen column beside a scrolling checkbox would be two things
    // disagreeing about where the row begins.
    $table = TableSchema::make()
        ->columns([TextColumn::make('name')->frozen(), TextColumn::make('email')])
        ->toArray();

    expect($table['frozen']['start'])->toBeTrue();
});

it('does not treat a trailing pin as a leading one', function (): void {
    $table = TableSchema::make()
        ->columns([TextColumn::make('name'), TextColumn::make('total')->frozen(ColumnPin::End)])
        ->toArray();

    expect($table['frozen']['start'])->toBeFalse();
});

it('pins the row actions only when asked', function (): void {
    $plain = TableSchema::make()->columns([TextColumn::make('name')])->toArray();

    $pinned = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->frozenActions()
        ->toArray();

    // Off by default: it costs horizontal room, and a table narrow enough not
    // to scroll gains nothing from it.
    expect($plain['frozen']['actions'])->toBeFalse()
        ->and($pinned['frozen']['actions'])->toBeTrue()
        ->and(TableSchema::make()->frozenActions(false)->hasFrozenActions())->toBeFalse();
});

/*
 * The invariants the browser half depends on
 */

it('draws pinned columns at the edge they are pinned to', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/DataTable.vue'));

    // A sticky cell is offset by the width of the frozen columns before it, so
    // a pinned column left sitting in the middle of the table would be offset
    // over the top of the ones it was declared after. The renderer sorts them
    // to the edges; this is the assertion that it still does.
    expect($source)
        ->toContain("filter((column) => column.frozen === 'start')")
        ->toContain('filter((column) => column.frozen === null)')
        ->toContain("filter((column) => column.frozen === 'end')");
});

it('keeps a frozen cell opaque and inheriting the row background', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/DataTable.vue'));

    // Transparent, and the scrolling content passes under it. Painted its own
    // colour, and it is the one cell in the row that never highlights on hover
    // or selection.
    expect($source)->toContain("'bg-inherit'");
});

it('drops the pinning when it would take most of the screen', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/useFrozenColumns.ts'));

    // On a phone, pinned columns can leave a strip too narrow to read the rest
    // through — and the user cannot scroll out of it, because the pinned
    // columns are the ones in the way.
    expect($source)->toContain('MAX_FROZEN_SHARE')
        ->toContain('ResizeObserver');
});
