# Local Development

Getting a checkout of `chocoalano/panel` to the point where the verification loop runs. The package is two halves that are checked by two different toolchains — PHP under `src/`, and Vue and TypeScript under `resources/js/` — and neither one can say anything about the other, so a working setup means both. This page is the setup, the commands, and the parts of a checkout that are not obvious from looking at it.

## A minimal working example

```bash
git clone https://github.com/chocoalano/panda-panel.git
cd panda-panel

composer install
npm ci

composer ci     # pint --test, then phpstan, then pest
npm run ci      # prettier --check, then eslint, then vue-tsc, then vite build
```

Both scripts are declared in the repository rather than assembled here, and both are what CI runs. A change that passes them locally fails CI only for a version-specific reason — which is what the [CI matrix](../testing/ci-matrix.md) is for.

There is no `.env` to write, no database server to start, and no `php artisan` step. The suite runs on sqlite `:memory:` and builds its own application; the next two sections are why.

## What is in a checkout

```text
src/            the framework — PandaPanel\*, PSR-4 from composer.json
config/         config/panda-panel.php
database/       the package's own migrations
stubs/          generator scaffolding for make:panel*
resources/      css, js — the Vue frontend an application publishes
examples/       the application the test suite runs against
tests/          the suite
frontend/       host stand-ins and the compile-check entry
docs/           this documentation
build/          scratch output — gitignored
```

The first five directories are the shipped package. Everything else is how the repository is developed and is `export-ignore`d in `.gitattributes`, so `composer require chocoalano/panel` never brings it. See [Releases](releases.md) for what that list is, and for the one file that looks like a development file and is deliberately kept off it.

## The two toolchains

### PHP

`composer.json` declares six scripts. They are the only PHP entry points worth memorising:

| Script | Runs |
| --- | --- |
| `composer test` | `vendor/bin/pest` |
| `composer test-coverage` | `vendor/bin/pest --coverage` |
| `composer format` | `vendor/bin/pint` |
| `composer format-check` | `vendor/bin/pint --test` |
| `composer analyse` | `vendor/bin/phpstan analyse --memory-limit=1G` |
| `composer ci` | `@format-check`, then `@analyse`, then `@test` |

```bash
composer format          # fix style
composer analyse         # larastan level 4 over src and database
composer test            # the whole suite
composer ci              # all three, in the order a failure is most useful
```

`composer ci` is ordered deliberately: a style failure is a one-command fix, a static-analysis failure names a line, and a test failure is the one that takes reading. Running them the other way round means finding out about a missing `declare(strict_types=1)` after a minute of tests.

### Frontend

`package.json` declares seven scripts:

| Script | Runs |
| --- | --- |
| `npm run lint` | `eslint resources/js frontend --max-warnings=0` |
| `npm run lint:fix` | `eslint resources/js frontend --fix` |
| `npm run format` | `prettier --write resources/js frontend resources/css` |
| `npm run format:check` | `prettier --check resources/js frontend resources/css` |
| `npm run typecheck` | `vue-tsc --noEmit -p tsconfig.json` |
| `npm run build` | `vite build` |
| `npm run ci` | `format:check`, `lint`, `typecheck`, `build` |

```bash
npm ci                   # install from the committed lockfile
npm run format           # fix formatting
npm run lint:fix         # fix what eslint can fix
npm run typecheck        # vue-tsc over every component
npm run build            # does all of it compile together
npm run ci               # all four, as CI runs them
```

`engines` declares `"node": ">=20.19"`. CI runs Node 20, 22 and 24; anything older is untested and the `@tailwindcss/vite` and Vite 7 versions in `dependencies` will not resolve.

`npm run build` produces nothing anybody ships. It exists to answer the one question type-checking cannot: whether every file in the tree resolves and compiles together. [Frontend toolchain](frontend-toolchain.md) is the whole of that story.

## The test application

The package has no `bootstrap/app.php` of its own, so the suite builds an application with `orchestra/testbench` and points it at `examples/`:

```php
// tests/TestCase.php

protected function resolveApplicationConfiguration($app): void
{
    $app->useAppPath((string) realpath(__DIR__.'/../examples/app'));
    $app->useDatabasePath((string) realpath(__DIR__.'/../examples/database'));
    $app->useBootstrapPath((string) realpath(__DIR__.'/../vendor/orchestra/testbench-core/laravel/bootstrap'));
    $app->useStoragePath(dirname(__DIR__).'/build/testbench/storage');

    parent::resolveApplicationConfiguration($app);
}
```

`examples/` holds `App\Models\User`, `App\Panels\Admin`, `App\Panels\App`, the policies, the factories, the routes and the Inertia root view. It is autoloaded under `App\` through `autoload-dev`, so it exists for development and never ships. Using the examples as the test application means they are exercised by the suite rather than left to rot beside it: every snippet in these docs that names `UserResource` names a class the suite actually runs.

`applicationBasePath()` points `base_path()` and `resource_path()` at the package root, because three things the panel reads through those helpers are real files in this repository — the icon registry, the generator stubs, and the TypeScript the serialized schemas are checked against.

## Directories a run creates

Git cannot commit an empty directory, and a `.gitkeep` in each would be seven files whose only job is to exist. `TestCase::prepareWritableDirectories()` makes them on first use, called from `tests/Pest.php` before the first application is built:

```text
bootstrap/cache                          Laravel's package manifest
resources/views                          the view finder globs it
build/testbench/storage/app/private      the disk exports land on
build/testbench/storage/framework/views
build/testbench/storage/framework/cache/data
build/testbench/storage/framework/sessions
build/testbench/storage/logs
```

Two of them have to sit under the base path rather than under `build/`: Laravel writes its package manifest while the application is still being constructed, and `route:cache` rebuilds the application in a process that never sees `TestCase`. Neither is shipped — the package has no Blade views of its own — and both are gitignored.

A clean is one command:

```bash
rm -rf build bootstrap resources/views
```

`build/` also holds `build/phpstan` (the analyser's `tmpDir`) and `build/frontend` (the Vite output). Deleting it costs a slower next run and nothing else.

## Running one thing at a time

Pest takes a path, a filter, or both:

```bash
vendor/bin/pest tests/Feature/Panel/ResourceQueryTest.php
vendor/bin/pest --filter=ResourceUrl
vendor/bin/pest --filter='refuses a member the index'
vendor/bin/pest --compact                 # one character per test
vendor/bin/pest --bail                    # stop at the first failure
vendor/bin/pest --dirty                   # only files with uncommitted changes
vendor/bin/pest --coverage                # needs Xdebug or PCOV
```

Pint takes paths and has three modes:

```bash
vendor/bin/pint                    # fix everything not excluded
vendor/bin/pint src tests          # fix these paths only
vendor/bin/pint --test             # report, change nothing — what CI runs
vendor/bin/pint --dirty            # only files with uncommitted changes
vendor/bin/pint --diff=main        # only files changed since branching off main
vendor/bin/pint -v                 # name every rule that fired
```

PHPStan reads `phpstan.neon` from the repository root and needs no arguments:

```bash
vendor/bin/phpstan analyse
vendor/bin/phpstan analyse --memory-limit=1G      # what composer analyse runs
vendor/bin/phpstan analyse --no-progress          # what CI runs
rm -rf build/phpstan                              # discard the result cache
```

The frontend scripts take no arguments, but the underlying tools do:

```bash
npx eslint resources/js/panel/tables --max-warnings=0
npx prettier --check resources/js/panel/forms
npx vue-tsc --noEmit -p tsconfig.json
```

## Exercising an artisan command

There is no `artisan` binary in this repository, because there is no application here to run one against. Commands are exercised the way the suite exercises them, inside the Testbench application:

```php
it('creates a panel provider and the directories discovery scans', function (): void {
    $this->artisan('make:panel', ['name' => 'Testing'])->assertSuccessful();

    expect(File::exists(app_path('Panels/Testing/TestingPanelProvider.php')))->toBeTrue();
});
```

`app_path()` there is `examples/app`, so a generator run writes into the example application and `GeneratorTest` deletes it afterwards. `examples/app/Panels/Testing` is gitignored for exactly that reason.

`GeneratorTest` then runs Pint over what was generated, so a stub that stops complying with this repository's own style fails here rather than in the next person's project:

```php
$pint = Process::run([base_path('vendor/bin/pint'), '--test', app_path('Panels/Testing')]);

expect($pint->successful())->toBeTrue($pint->output());
```

That test skips itself when `vendor/bin/pint` is absent, so a `--no-dev` install does not fail it.

## What git tracks

Three things in `.gitignore` surprise people:

```text
composer.lock
.github
.ai
.claude
.codex
```

- **`composer.lock` is not committed.** A library's job is to work against the ranges in `composer.json` rather than against one resolution of them, and CI resolves fresh on every run — with `--prefer-lowest` as well as `--prefer-stable`. `composer install` in a fresh clone therefore resolves rather than installing from a lock, which is correct and is also why two checkouts can hold different patch versions.
- **`package-lock.json` *is* committed**, and CI runs `npm ci` against it. That is the opposite decision for the opposite reason: this repository's own toolchain has to be reproducible, while an application installs from the ranges and never sees the lockfile.
- **`.github/` is ignored.** The workflow file exists in a working tree and is not tracked, so an edit to it needs `git add -f .github/workflows/tests.yml` to reach a commit.

## Notes

- **`composer install` on a fresh clone prints "No lock file found"** and resolves instead. That is the intended state, not a broken checkout.
- **The suite needs `pdo_sqlite` and `zip`.** sqlite because the harness runs on `:memory:`, zip because the xlsx writer is real rather than mocked. Both are in the CI `setup-php` step for the same reason.
- **No PHP test depends on `npm run build`.** `Illuminate\Foundation\Vite` is replaced with `Tests\Fixtures\Panel\FakeVite` in `defineEnvironment()`, so a checkout with no `node_modules` still runs the whole PHP suite.
- **A test that registers a fixture panel must guard with `PanelManager::has()`.** The registry survives between tests in one process, and registering twice throws.
- **`vendor/bin/pest --coverage` needs a coverage driver.** CI sets `coverage: none` on every PHP job, so coverage is a local-only tool here.
- **Deleting `build/` deletes the Testbench storage.** The suite recreates it; nothing in there is worth keeping.

## See also

- [Running the tests](testing.md) — the harness, the fixtures, and where a new test goes
- [Frontend toolchain](frontend-toolchain.md) — what each config file decides
- [Coding standards](coding-standards.md) — what Pint and PHPStan enforce, and the two traps
- [Pull requests](pull-requests.md) — the checklist before opening one
- [Releases](releases.md) — versioning, the changelog, and the export list
- [CI matrix](../testing/ci-matrix.md) — how these commands are combined in GitHub Actions
- [Directory structure](../getting-started/directory-structure.md) — the same tree, from an application's side
- [Test setup](../testing/setup.md) — the helpers, and testing a panel in your own project
