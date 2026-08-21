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

it('gives every row a frozen cell can inherit an opaque background', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/DataTable.vue'));

    // `bg-inherit` only inherits what the row has. A row with no background of
    // its own hands the pinned cell transparency, and the columns scrolling
    // under it show straight through — the bug that reads as "pinning does
    // not work" rather than as a colour problem.
    expect($source)->toContain("'bg-background hover:bg-muted'")
        ->not->toContain('class="bg-transparent"');
});

it('drops one frozen side without dropping the other', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/useFrozenColumns.ts'));

    // Summing both sides and comparing the total is what unpinned the two
    // identity columns at the left of a phone-width table because the row's
    // actions were pinned at the right.
    expect($source)->toContain('MAX_FROZEN_SHARE')
        ->toContain('ResizeObserver')
        ->toContain('const activeStart = ref(true)')
        ->toContain('const activeEnd = ref(true)');
});

it('measures without looping back into the render that measured', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/useFrozenColumns.ts'));

    // Three things stop "Maximum recursive updates exceeded": a ref callback
    // that keeps its identity per key, a callback that does nothing when
    // handed the element it already holds, and a measurement that only writes
    // when a width actually moved.
    expect($source)->toContain('refCallbacks')
        ->toContain('WIDTH_EPSILON')
        ->toContain('requestAnimationFrame')
        ->toContain('cancelAnimationFrame');
});

it('measures the scrolling lane rather than the shell around it', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/useFrozenColumns.ts'));

    // The share a frozen side may take is a share of the box that scrolls,
    // which is the wrapper `Table` renders and not the bordered container
    // around it.
    expect($source)->toContain('data-slot="table-container"');
});

it('ships the frozen-column rules in the published stylesheet', function (): void {
    // A rule in a CSS file no entrypoint imports is a rule that does not
    // exist. `panda-panel.css` is the file that is published, imported by
    // `frontend/entry.ts`, and compiled by the application's Tailwind.
    $stylesheet = File::get(base_path('resources/css/panda-panel.css'));

    expect($stylesheet)->toContain('.panel-table-frozen-cell::before')
        ->toContain('.panel-table-frozen-edge::after')
        ->toContain('.panel-table-frozen-edge-start::after')
        ->toContain('.panel-table-frozen-edge-end::after');

    // And the renderer writes the classes those rules are keyed off, rather
    // than leaving the stylesheet to infer a side from an inline offset.
    expect(File::get(base_path('resources/js/panel/tables/DataTable.vue')))
        ->toContain("'panel-table-frozen-cell'")
        ->toContain('panel-table-frozen-edge-${side}');
});

it('resolves the scroll container from whatever ref holds it', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/useFrozenColumns.ts'));

    // A ref on a component holds the instance, and `clientWidth` read off an
    // instance is undefined — which reads as "no lane to run out of", so the
    // threshold could never trigger on any screen.
    expect($source)->toContain('function elementOf(target: RefTarget | undefined): Element | null')
        ->toContain('const root = elementOf(container.value);');
});

it('gives a frozen cell a row background to inherit by default', function (): void {
    $row = File::get(base_path('resources/js/components/ui/table/TableRow.vue'));

    // In the primitive rather than only in `DataTable`, so any table the
    // package or an application draws with these components starts opaque.
    // A `bg-*` class passed in still wins through `cn()`, which is what keeps
    // a group band tinted.
    expect($row)->toContain('bg-background');
});

it('imports nothing that could import it back', function (): void {
    // `panda-panel.css` is a complete entrypoint: an application either
    // builds it directly or imports it once from `app.css`. Importing the
    // application's stylesheet from here would close that circle, and a
    // circular `@import` is a build error at best and a stylesheet that
    // silently loses half its rules at worst.
    preg_match_all(
        "/@import\s+'([^']+)'/",
        File::get(base_path('resources/css/panda-panel.css')),
        $imports,
    );

    expect($imports[1])->toBe(['tailwindcss', 'tw-animate-css']);
});

it('keeps the narrow-screen threshold clear of two identity columns', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/useFrozenColumns.ts'));

    preg_match('/const MAX_FROZEN_SHARE = ([0-9.]+);/', $source, $matches);

    // Per side. A number and a name column take about half a 360px lane, and
    // 0.6 — the old value, applied to both sides added together — dropped
    // them on a phone for the sake of the actions column at the other edge.
    expect((float) $matches[1])->toBeGreaterThan(0.8)
        ->and((float) $matches[1])->toBeLessThan(1.0);
});

it('reads the frozen set only after the values it depends on exist', function (): void {
    $source = File::get(base_path('resources/js/panel/tables/DataTable.vue'));

    // `useFrozenColumns` watches the frozen set, and Vue evaluates a watch
    // source once to discover its dependencies. A `const` read during that
    // first evaluation but declared further down the file throws in the
    // temporal dead zone, which took the whole table down at setup.
    expect(mb_strpos($source, 'const hasActionsColumn = computed('))
        ->toBeLessThan(mb_strpos($source, 'const frozenColumns = computed<FrozenColumn[]>('));
});
