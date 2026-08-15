<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Support\ColumnCount;
use PandaPanel\Support\Installer\FrontendRequirements;

/*
 * What the published frontend promises the application around it.
 *
 * These assertions are about files rather than behaviour, which is unusual
 * and is the point: the failures they cover are all silent in an application
 * and invisible to a PHP test that only exercises the server. A panel page
 * with no layout renders inside the host's shell and answers 200; a
 * composable that reads `usePage().props.panel` directly type-checks here and
 * fails in every real project. Neither is reachable any other way from this
 * suite, so the file is the thing asserted about.
 */

/**
 * @return list<string>
 */
function panelPageFiles(): array
{
    return array_map(
        static fn (SplFileInfo $file): string => $file->getPathname(),
        File::allFiles(base_path('resources/js/pages/panel')),
    );
}

it('declares a layout on every published panel page', function (): void {
    $without = [];

    foreach (panelPageFiles() as $path) {
        if (! str_contains(File::get($path), 'defineOptions({ layout:')) {
            $without[] = str_replace(base_path().'/', '', $path);
        }
    }

    // A page that names no layout takes whatever the application's resolver
    // gives a page it has no case for, which on a starter kit is the signed-in
    // application shell. The panel then renders with the host's sidebar and
    // its own navigation nowhere, at HTTP 200, with nothing logged.
    expect($without)->toBe([]);
});

it('keeps the panel auth pages out of the panel shell', function (): void {
    foreach (panelPageFiles() as $path) {
        if (! str_contains($path, '/auth/')) {
            continue;
        }

        // They draw their own frame with `PanelAuthLayout` — a guest has no
        // navigation, no notifications and no user menu — so the layout they
        // declare is the one that adds nothing.
        expect(File::get($path))
            ->toContain('defineOptions({ layout: PanelBlankLayout })')
            ->not->toContain('defineOptions({ layout: PanelLayout })');
    }
});

it('reads the panel shared props through one accessor', function (): void {
    // Exactly the keys `SharePanelData` puts on the page. Other props are
    // fair game: `usePanelPage` reads `props.page` straight from `usePage()`
    // and hands it to a narrower, which is the repo's "validate, do not
    // assert" rule and is safe against a `{}` that never got augmented.
    $shared = ['panel', 'navigation', 'panels', 'broadcasting', 'search', 'notifications', 'tenancy'];

    $offenders = [];

    foreach (File::allFiles(base_path('resources/js/panel')) as $file) {
        $path = $file->getPathname();

        if (str_ends_with($path, 'types/shared.ts')) {
            continue;
        }

        $contents = File::get($path);

        foreach ($shared as $prop) {
            if (str_contains($contents, 'props.'.$prop)
                && str_contains($contents, 'usePage()')) {
                $offenders[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    // Reading `usePage().props.panel` anywhere else means depending on an
    // Inertia module augmentation reaching the application, being picked up by
    // its tsconfig, and merging with what its starter kit already declares.
    // When any of that fails the prop is `{}` and the *application's* build
    // breaks inside files nobody there wrote.
    expect($offenders)->toBe([]);
});

it('lists every host module the published components import', function (): void {
    $imported = [];

    $shipped = array_merge(
        File::allFiles(base_path('resources/js/panel')),
        File::allFiles(base_path('resources/js/pages')),
    );

    foreach ($shipped as $file) {
        preg_match_all(
            '/from \'@\/([^\']+)\'/',
            File::get($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $specifier) {
            $imported[$specifier] = true;
        }
    }

    $missing = [];

    foreach (array_keys($imported) as $specifier) {
        // Anything the package itself publishes is not a host module. Tried
        // as written and with each extension, because a specifier may or may
        // not carry one.
        foreach (['', '.vue', '.ts', '/index.ts', '.d.ts'] as $extension) {
            if (File::exists(base_path('resources/js/'.$specifier.$extension))) {
                continue 2;
            }
        }

        $missing[] = $specifier;
    }

    sort($missing);

    // Every `@/…` a published file imports and the package does not ship is
    // the application's to provide, and `panel:install` can only name the ones
    // it knows about. A module that reached this list without reaching
    // `HOST_MODULES` is one the installer reports as fine and the host's build
    // then fails on.
    $declared = array_map(
        static fn (string $module): string => ltrim($module, '@/'),
        FrontendRequirements::missingHostModules(),
    );

    expect(array_diff($missing, $declared))->toBe([]);
});

/*
 * The application entry check
 */

it('spots an entry that overwrites the layout a page declared', function (): void {
    $entry = resource_path('js/app.ts');
    $existing = File::exists($entry) ? File::get($entry) : null;

    File::ensureDirectoryExists(dirname($entry));
    File::put($entry, <<<'TS'
        createInertiaApp({
            resolve: (name) => {
                const page = resolvePageComponent(name);
                page.default.layout = AppLayout;
                return page;
            },
        });
        TS);

    try {
        $overrides = FrontendRequirements::layoutOverrides();

        expect($overrides)->toHaveCount(1)
            ->and($overrides[0]['file'])->toBe('resources/js/app.ts')
            ->and($overrides[0]['line'])->toBe(4)
            ->and($overrides[0]['code'])->toBe('page.default.layout = AppLayout;');
    } finally {
        $existing === null ? File::delete($entry) : File::put($entry, $existing);
    }
});

it('accepts an entry that lets a page keep its own layout', function (): void {
    $entry = resource_path('js/app.ts');
    $existing = File::exists($entry) ? File::get($entry) : null;

    File::ensureDirectoryExists(dirname($entry));

    try {
        foreach ([
            'page.default.layout ??= AppLayout;',
            'page.default.layout ||= AppLayout;',
            'page.default.layout = page.default.layout || AppLayout;',
        ] as $line) {
            File::put($entry, $line);

            expect(FrontendRequirements::layoutOverrides())->toBe([]);
        }
    } finally {
        $existing === null ? File::delete($entry) : File::put($entry, $existing);
    }
});

/*
 * The grid, whose two halves live on opposite sides of the wire
 */

/**
 * @return array{grid: array<int, string>, effective: array<int, array{md: int, lg: int}>}
 */
function gridTables(): array
{
    $source = File::get(base_path('resources/js/panel/lib/grid.ts'));

    preg_match('/const GRID_CLASSES[^{]*\{(.*?)\n\};/s', $source, $gridBlock);
    preg_match('/const EFFECTIVE_COLUMNS[^{]*\{(.*?)\n\};/s', $source, $effectiveBlock);

    preg_match_all("/(\d+): '([^']+)'/", $gridBlock[1] ?? '', $grid);
    preg_match_all('/(\d+): \{ md: (\d+), lg: (\d+) \}/', $effectiveBlock[1] ?? '', $effective);

    return [
        'grid' => array_combine($grid[1], $grid[2]),
        'effective' => array_combine(
            $effective[1],
            array_map(
                static fn (string $md, string $lg): array => ['md' => (int) $md, 'lg' => (int) $lg],
                $effective[2],
                $effective[3],
            ),
        ),
    ];
}

it('clamps columns to the counts the renderer has classes for', function (): void {
    $counts = array_keys(gridTables()['grid']);

    // PHP clamps to `ColumnCount::MAX`; the renderer falls back to one column
    // for anything it has no literal class for. If those two disagree, a
    // column count passes the clamp and lands on the fallback — which is the
    // silent one-column form this pair exists to prevent.
    expect(max($counts))->toBe(ColumnCount::MAX)
        ->and($counts)->toBe(range(1, ColumnCount::MAX));
});

it('never lets a span outgrow the columns at its breakpoint', function (): void {
    $tables = gridTables();

    foreach ($tables['grid'] as $columns => $classes) {
        // What the grid is actually divided into at each breakpoint, read
        // from the classes themselves rather than restated.
        preg_match('/(?:^|\s)md:grid-cols-(\d+)/', $classes, $md);
        preg_match('/(?:^|\s)lg:grid-cols-(\d+)/', $classes, $lg);

        $actualMd = (int) ($md[1] ?? 1);
        $actualLg = (int) ($lg[1] ?? $actualMd);

        // A span is clamped against this table. If it claimed more columns
        // than the grid has at that width, `grid-column: span n` would create
        // implicit tracks and the row would overflow its container sideways —
        // which is what a four-column form did at `md`, where it is really
        // two columns wide.
        expect($tables['effective'][$columns])->toBe(['md' => $actualMd, 'lg' => $actualLg]);
    }
});
