# Frontend Contract Tests

`tests/Feature/Panel/FrontendContractTest.php` asserts about **files** rather than about behaviour, which is unusual and is the point. The failures it covers are all silent in an application and invisible to a PHP test that only exercises the server: a panel page with no layout renders inside the host's shell and answers 200; a composable that reads `usePage().props.panel` directly type-checks in this repository and fails in every real project. Neither is reachable any other way from a PHP suite, so the file is the thing asserted about.

## A minimal working example

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('declares a layout on every published panel page', function (): void {
    $without = [];

    foreach (File::allFiles(base_path('resources/js/pages/panel')) as $file) {
        if (! str_contains(File::get($file->getPathname()), 'defineOptions({ layout:')) {
            $without[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    // A page that names no layout takes whatever the application's resolver
    // gives a page it has no case for, which on a starter kit is the signed-in
    // application shell. The panel then renders with the host's sidebar and
    // its own navigation nowhere, at HTTP 200, with nothing logged.
    expect($without)->toBe([]);
});
```

Collect the offenders and assert the list is empty, rather than asserting inside the loop. A failure then names every file at once instead of stopping at the first.

## Why these are PHP tests

The package ships no JavaScript test runner. `package.json` has `lint`, `format:check`, `typecheck` and `build`, and those four catch what a type system can catch. What they cannot catch is a *convention*: a file that compiles, type-checks and renders, and is wrong anyway because of something it did not do. Those are string assertions over source files, and PHP is where the suite already is.

`base_path()` during a test run points at the package root — `TestCase::applicationBasePath()` returns `realpath(__DIR__.'/..')` — so `base_path('resources/js/panel')` is the real published frontend rather than Testbench's empty skeleton.

## The seven assertions

| Test | Reads | Fails when |
| --- | --- | --- |
| every page declares a layout | `resources/js/pages/panel/**` | a page has no `defineOptions({ layout:` |
| auth pages stay out of the panel shell | `resources/js/pages/panel/**/auth/**` | an auth page declares `PanelLayout` instead of `PanelBlankLayout` |
| shared props are read through one accessor | `resources/js/panel/**` | a file reads `usePage()` and `props.<shared key>` |
| host modules are all declared | `resources/js/panel/**`, `resources/js/pages/**` | an `@/…` import is neither shipped nor in `FrontendRequirements::HOST_MODULES` |
| an entry that overwrites a layout is spotted | `resources/js/app.ts` | `FrontendRequirements::layoutOverrides()` misses an unconditional assignment |
| an entry that defers is accepted | `resources/js/app.ts` | `??=`, `||=` or a falling-back assignment is reported |
| the grid tables agree | `resources/js/panel/lib/grid.ts` | PHP's clamp and the renderer's classes disagree |

### Layouts

Two tests. The first is above. The second keeps the panel's own auth pages out of the panel shell:

```php
it('keeps the panel auth pages out of the panel shell', function (): void {
    foreach (panelPageFiles() as $path) {
        if (! str_contains($path, '/auth/')) {
            continue;
        }

        // They draw their own frame with `PanelAuthLayout` — a guest has no
        // navigation, no notifications and no user menu.
        expect(File::get($path))
            ->toContain('defineOptions({ layout: PanelBlankLayout })')
            ->not->toContain('defineOptions({ layout: PanelLayout })');
    }
});
```

### The shared-props accessor

`SharePanelData` puts seven keys on the page. Reading any of them through `usePage()` anywhere except the one accessor means depending on an Inertia module augmentation reaching the application, being picked up by its tsconfig, and merging with what its starter kit already declares. When any of that fails the prop is `{}` and the *application's* build breaks inside files nobody there wrote.

```php
it('reads the panel shared props through one accessor', function (): void {
    $shared = ['panel', 'navigation', 'panels', 'broadcasting', 'search', 'notifications', 'tenancy'];

    $offenders = [];

    foreach (File::allFiles(base_path('resources/js/panel')) as $file) {
        $path = $file->getPathname();

        if (str_ends_with($path, 'types/shared.ts')) {
            continue;
        }

        $contents = File::get($path);

        foreach ($shared as $prop) {
            if (str_contains($contents, 'props.'.$prop) && str_contains($contents, 'usePage()')) {
                $offenders[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    expect($offenders)->toBe([]);
});
```

The exemption list is one file, named explicitly. Other props are fair game: `usePanelPage` reads `props.page` straight from `usePage()` and hands it to a narrower, which is the repository's "validate, do not assert" rule and is safe against a `{}` that never got augmented.

### Host modules

The published components import a set of `@/…` modules the package does not ship — Wayfinder route modules, starter-kit components, the shared types — listed in `FrontendRequirements::HOST_MODULES`. `panel:install` reports the missing ones, and it can only report the ones it knows about. A module that reaches an import and not `HOST_MODULES` is one the installer calls fine and the host's build then fails on.

The test derives the list from the source rather than restating it:

```php
it('lists every host module the published components import', function (): void {
    $imported = [];

    $shipped = array_merge(
        File::allFiles(base_path('resources/js/panel')),
        File::allFiles(base_path('resources/js/pages')),
    );

    foreach ($shipped as $file) {
        preg_match_all('/from \'@\/([^\']+)\'/', File::get($file->getPathname()), $matches);

        foreach ($matches[1] as $specifier) {
            $imported[$specifier] = true;
        }
    }

    $missing = [];

    foreach (array_keys($imported) as $specifier) {
        // Anything the package itself publishes is not a host module. Tried as
        // written and with each extension, because a specifier may or may not
        // carry one.
        foreach (['', '.vue', '.ts', '/index.ts', '.d.ts'] as $extension) {
            if (File::exists(base_path('resources/js/'.$specifier.$extension))) {
                continue 2;
            }
        }

        $missing[] = $specifier;
    }

    sort($missing);

    $declared = array_map(
        static fn (string $module): string => ltrim($module, '@/'),
        FrontendRequirements::missingHostModules(),
    );

    expect(array_diff($missing, $declared))->toBe([]);
});
```

`FrontendRequirements::missingHostModules(): array` returns the declared list as `@/…` specifiers, checked against the application's `resources/js` with the extensions `.ts`, `.vue`, `.d.ts`, `/index.ts`, `/index.vue`, `/index.d.ts`. A bare `''` is deliberately not among them: `File::exists()` answers true for a directory, so allowing it made every directory-shaped entry vacuous.

The other public methods on the same class, for a test of your own:

| Method | Signature | Returns |
| --- | --- | --- |
| `npmPackages` | `static npmPackages(): array` | `list<string>` of `name@range` pairs |
| `missingNpmPackages` | `static missingNpmPackages(): array` | the ones the application has not installed |
| `missingHostModules` | `static missingHostModules(): array` | `@/…` specifiers with nothing behind them |
| `hasVite` | `static hasVite(): bool` | whether a `vite.config.ts`/`.js` exists |
| `missingInertia` | `static missingInertia(): array` | what is missing, in words |
| `layoutOverrides` | `static layoutOverrides(): array` | `list<array{file: string, line: int, code: string}>` |

### The application entry

The one thing about this seam that cannot be fixed from inside the package. An entry that writes `page.default.layout = AppLayout` replaces the panel shell with the application's own *after* the page has already asked for one — no error, HTTP 200, and navigation that silently belongs to somebody else.

The test writes an entry file, checks the report, and restores whatever was there:

```php
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
```

The `finally` is not optional. A test that writes into `resources/js` and throws leaves the repository dirty for every test after it.

And the negative, which is the half that keeps the check from being a nuisance — every deferring form is correct and must pass:

```php
foreach ([
    'page.default.layout ??= AppLayout;',
    'page.default.layout ||= AppLayout;',
    'page.default.layout = page.default.layout || AppLayout;',
] as $line) {
    File::put($entry, $line);

    expect(FrontendRequirements::layoutOverrides())->toBe([]);
}
```

`layoutOverrides()` scans `js/app.ts`, `js/app.js`, `js/ssr.ts` and `js/ssr.js`, in that order.

### The grid, whose two halves live on opposite sides of the wire

PHP clamps a column count to `PandaPanel\Support\ColumnCount::MAX`; the renderer falls back to one column for anything it has no literal class for. If those two disagree, a column count passes the clamp and lands on the fallback — the silent one-column form this pair exists to prevent.

The test parses the TypeScript rather than restating it:

```php
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
        'effective' => array_combine($effective[1], array_map(
            static fn (string $md, string $lg): array => ['md' => (int) $md, 'lg' => (int) $lg],
            $effective[2],
            $effective[3],
        )),
    ];
}

it('clamps columns to the counts the renderer has classes for', function (): void {
    $counts = array_keys(gridTables()['grid']);

    expect(max($counts))->toBe(ColumnCount::MAX)
        ->and($counts)->toBe(range(1, ColumnCount::MAX));
});
```

The second grid test reads the breakpoint each class row actually declares and compares it against the span table, because a span claiming more columns than the grid has at that width creates implicit tracks and the row overflows sideways:

```php
it('never lets a span outgrow the columns at its breakpoint', function (): void {
    $tables = gridTables();

    foreach ($tables['grid'] as $columns => $classes) {
        preg_match('/(?:^|\s)md:grid-cols-(\d+)/', $classes, $md);
        preg_match('/(?:^|\s)lg:grid-cols-(\d+)/', $classes, $lg);

        $actualMd = (int) ($md[1] ?? 1);
        $actualLg = (int) ($lg[1] ?? $actualMd);

        expect($tables['effective'][$columns])->toBe(['md' => $actualMd, 'lg' => $actualLg]);
    }
});
```

Reading the breakpoints out of the classes rather than restating them is what makes this a test: a version that hard-coded `['md' => 2, 'lg' => 4]` would pass whatever `grid.ts` said.

## The generated registries

Three more file assertions live in the negative suite, and they belong to the same family. The build-time registries are generated, and the failure mode when one is out of date is a component that renders nothing — indistinguishable from a component that renders nothing on purpose. So each registry has to contain its own development-time warning, and a test says so:

```php
it('tells a developer when a widget component is not in the registry', function (): void {
    expect(File::get(base_path('resources/js/panel/widgets/registry.ts')))
        ->toContain('is not in the build-time registry')
        ->toContain('resources/js/pages/Panels/{Panel}/Widgets/')
        // Development only. A console message on a live panel helps nobody.
        ->toContain('import.meta.env.DEV')
        // Once per name, so one typo is one warning rather than one per row.
        ->toContain('missing.has(name)');
});
```

The same for `resources/js/panel/forms/registry.ts` and `resources/js/panel/icons/registry.ts`, the latter also asserting it names `php artisan panel:icons`.

## Writing one for an application

The pattern transfers. If your project has a convention a compiler cannot enforce — every custom column component exports a `state` prop, every panel page under a directory names a layout — the test is a glob, a `str_contains`, and an empty-array assertion:

```php
use Illuminate\Support\Facades\File;

it('gives every custom panel component a display name', function (): void {
    $offenders = [];

    foreach (File::allFiles(resource_path('js/pages/Panels')) as $file) {
        if (! str_contains(File::get($file->getPathname()), 'defineOptions(')) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
```

Keep them few and keep them about things that fail silently. A file assertion is a blunt instrument, and one that encodes a preference rather than a failure mode is a test that has to be edited every time somebody writes valid code a different way.

## Gotchas

- **`base_path()` is the package root in this suite.** In an application it is the application root, so the same test moves without changing — but a test written against `vendor/` paths does not.
- **Restore anything you write.** The entry-file tests write into `resources/js` and put back what was there in a `finally`, including deleting a file that did not exist.
- **String assertions are exact.** `defineOptions({ layout:` matches the repository's formatting. Prettier is enforced by `npm run format:check`, which is what makes that safe; without a formatter these tests would be a source of false failures.
- **Collect, then assert.** An `expect()` inside the loop stops at the first offender and hides the rest.
- **These tests do not run the frontend.** They prove the source says what it should. `npm run typecheck` and `npm run build` prove it compiles, and CI runs both on three Node versions — see [CI matrix](ci-matrix.md).

## See also

- [Test setup](setup.md) and [CI matrix](ci-matrix.md)
- [Host modules](../frontend/host-modules.md) and [frontend requirements](../getting-started/frontend-requirements.md)
- [Component registries](../concepts/component-registries.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Frontend assets](../concepts/frontend-assets.md)
- [Negative security tests](negative-security-tests.md)
