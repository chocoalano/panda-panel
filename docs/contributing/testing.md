# Running the Tests

This package's own suite: how to run it, how it is built, and what a change is expected to add to it. It is a different question from [Testing](../testing/setup.md), which is about testing a panel in *your* application — that page documents the helpers this package ships, and this one documents the 1,260 tests that keep those helpers honest.

## A minimal working example

```bash
composer install
vendor/bin/pest
```

```text
Tests:    1260 passed (3648 assertions)
Duration: 66.99s
```

No database server, no `.env`, no `npm` step. `phpunit.xml` sets `DB_CONNECTION=testing` and `tests/TestCase.php` binds that to sqlite `:memory:`, so a clone runs the whole suite with nothing configured.

## The harness

Three files decide what a test runs against.

`phpunit.xml` — one suite over the whole `tests` directory, with `src` as the coverage source:

```xml
<testsuites>
    <testsuite name="PandaPanel Test Suite">
        <directory>tests</directory>
    </testsuite>
</testsuites>
<source>
    <include><directory>src</directory></include>
</source>
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_CONNECTION" value="testing"/>
</php>
```

`tests/Pest.php` — the base class, the database trait, and one constant:

```php
TestCase::prepareWritableDirectories();

define('SETTINGS_PAGES', [
    ProfileSettings::class,
    SecuritySettings::class,
    AppearanceSettings::class,
]);

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

`prepareWritableDirectories()` runs before the first application is built, because Laravel writes its package manifest while one is still being constructed and nothing can move the directory afterwards.

`SETTINGS_PAGES` is restated here rather than read from `Panel::SETTINGS_PAGES`, and that is the point: a discovery test that read the constant back would pass whatever the constant said. Tests asserting a panel's exact page list subtract this list rather than hardcoding the built-ins.

`tests/TestCase.php` — extends `Orchestra\Testbench\TestCase` and does five things:

| Method | What it decides |
| --- | --- |
| `getPackageProviders($app)` | Inertia, Fortify, `PandaPanelServiceProvider`, and the example application's own Fortify provider |
| `applicationBasePath()` | `base_path()` is the package root, so the icon registry, the generator stubs and the published TypeScript are the real files |
| `resolveApplicationConfiguration($app)` | `useAppPath(examples/app)`, `useDatabasePath(examples/database)`, `useBootstrapPath(testbench-core/laravel/bootstrap)`, `useStoragePath(build/testbench/storage)` |
| `defineEnvironment($app)` | sqlite `:memory:`, array session, array cache, sync queue, array mail, a `FakeVite` binding, a private local disk, and the two example panels in `panda-panel.panels` |
| `defineRoutes($router)` / `defineDatabaseMigrations()` | the example application's routes and migrations, plus Fortify's, Passkeys' and the package's |

The application half is `examples/`. Using it as the test application means the examples are exercised rather than left to rot: `App\Models\User`, `App\Panels\Admin`, `App\Panels\App`, `App\Policies\UserPolicy`.

One deliberate omission is worth knowing about. `defineEnvironment()` does **not** register the guest redirect:

```php
// Nothing here registers the guest redirect. It used to, standing in
// for the `bootstrap/app.php` an application would have written it
// in; the package now registers it itself, and leaving this bare is
// what makes every auth test in this suite a real test of that.
```

A harness that sets up the thing under test proves the harness works.

## Where a test goes

```text
tests/
├── Pest.php
├── TestCase.php
├── Feature/Panel/            91 files — one per behaviour
│   └── Negative/             11 files — one per class of thing that must not happen
└── Fixtures/Panel/           the classes those tests register
```

There is no `tests/Unit`. Every behaviour this package has crosses a request, a schema build, or a registry, and a unit test of a fluent setter proves that PHP assigns properties.

Name a file after the behaviour rather than the class: `ResourceQueryTest`, `TableSearchAndSortTest`, `PanelIsolationTest`. Several of them cover more than one class, because the behaviour does.

Fixtures live in `tests/Fixtures/Panel/` and are real implementations of the contracts — resources, pages, widgets, panels, policies, plugins. A fixture that exists to prove something never runs should throw from the method that must not be reached, so a regression fails loudly rather than passing silently. `Tests\Fixtures\Panel\ForbiddenFixtureResource` is the shape to copy:

```php
public static function canViewAny(): bool
{
    return false;
}

public static function navigationItem(PanelContract $panel): ?NavigationItem
{
    return NavigationItem::make(
        label: 'Secrets',
        href: '/'.$panel->getPath().'/secrets',
        badge: static fn (): int => throw new RuntimeException('Badge evaluated for an unauthorized item.'),
        sort: 30,
        group: 'User Management',
    );
}
```

The badge closure throwing is what proves the authorization filter runs *before* badge evaluation. A fixture that returned quietly would let the item leak into serialization and the test would still pass.

## Running part of it

```bash
vendor/bin/pest                                       # everything
vendor/bin/pest tests/Feature/Panel/Negative          # one directory
vendor/bin/pest tests/Feature/Panel/TableFilterTest.php
vendor/bin/pest --filter=ResourceUrl                  # by file or test name
vendor/bin/pest --filter='refuses a member the index'
vendor/bin/pest --compact                             # one character per test
vendor/bin/pest --bail                                # stop at the first failure
vendor/bin/pest --dirty                               # only files with uncommitted changes
vendor/bin/pest --coverage                            # needs Xdebug or PCOV
```

`composer test` and `composer test-coverage` are the same two commands with the flags already attached.

## What a change is expected to add

A new feature needs a test that would fail without it. That is the floor rather than the standard — the standard is the list the suite already holds itself to, restated here because it is what review asks about:

- **Unauthorized access is 403 on every route**, including the write verbs and the action endpoint. Not only the index.
- **Search matches only whitelisted columns**, and an unknown or non-sortable sort column is ignored rather than escaped.
- **`perPage` clamps**, and an invalid filter value is rejected.
- **Records resolve through `Resource::query()`**, so an out-of-scope key 404s everywhere that takes a key.
- **Create validates and persists only declared fields**; update leaves an untouched password alone.
- **A bulk selection containing one forbidden record changes nothing.**
- **The serialized table and form contain no closures and no class names.** They cross to the browser.
- **The list route issues no query per row.**

`assertOk()` on a list page passes for a page showing every tenant's records. Assert the thing, not the status code:

```php
use Illuminate\Support\Facades\DB;

it('issues no query per row', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    User::factory()->count(20)->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('/admin/users')->assertOk();

    expect($queries)->toBeLessThan(10);
});
```

### Use the shipped helpers

The package's own suite uses the same helpers it ships, and for the same reason an application should: they go through the real `TableSchema`, `TableQuery`, `FormSchema` and `Action`, so a helper that passed while the table was broken would be impossible.

```php
panelTable(UserResource::class)->assertCanSeeRecord($user)->assertCount(2);
panelForm(UserResource::class)->assertFieldIsRequired('name');
panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
```

They are autoloaded through `composer.json`'s `autoload.files`, so no import and no base class. Every signature is in [Testing helpers](../testing/helpers.md).

The hard constraint on any helper added to `src/Testing/` is that it must not compute the answer a second way. A helper with its own idea of what a table shows passes while the table is broken.

### Three facts that otherwise cost an hour

```php
// Inertia puts `flash` beside `props` on the page object, not inside it.
$toast = $this->get('/admin/users')->viewData('page')['flash']['toast'] ?? null;
```

```php
// A partial reload must send the asset version, or the response is 409.
$version = $this->get('/admin')->viewData('page')['version'];

$this->get('/admin', [
    'X-Inertia' => 'true',
    'X-Inertia-Version' => $version,
    'X-Inertia-Partial-Component' => 'panel/Dashboard',
    'X-Inertia-Partial-Data' => 'widgetData',
])->assertOk();
```

```php
use PandaPanel\Core\PanelManager;

// Calling a page controller or a helper directly needs the panel context
// `ResolvePanel` would have set.
app(PanelManager::class)->setCurrentPanel(panel('admin'));
```

## The negative suite

`tests/Feature/Panel/Negative/` states as things that **must not happen** the properties the rest of the suite states positively. A change to authorization, to a query parameter, to a file download, or to what a schema accepts belongs there as well as in its own file.

| File | States that |
| --- | --- |
| `HostileTableInputTest` | a sort, filter, group, search or page parameter the schema never declared is ignored |
| `PrivilegeEscalationTest` | no guessed URL, hand-written POST or swapped id gets past a policy |
| `ScopeBypassTest` | a record `Resource::query()` excludes is unreachable through every endpoint that takes a key |
| `SchemaEscapeTest` | a create or edit writes only what the form declared |
| `MalformedInputTest` | input of the wrong shape is answered, never crashed into |
| `FileAndDataAccessTest` | an export, import report or notification belongs to exactly one user |
| `SpreadsheetFormulaTest` | a CSV cell cannot become a formula in the reader's spreadsheet |
| `DistributionTest` | the Composer archive carries `package.json` and not the lockfile, and every command the documentation tells somebody to run exists |
| `SchemaMistakeTest` | a schema that cannot mean what it says refuses at build time, and says which name is wrong |
| `UnreachableDeclarationTest` | a declaration pointing at something that cannot answer fails with a message naming it |
| `SilentAbsenceTest` | "my resource is missing" has a cause somebody can read |

The standard for one of these: **delete the guard and confirm the test fails.** A security test that passes with the guard removed is decorative. See [Security](security.md) and [Negative security tests](../testing/negative-security-tests.md).

## Tests about files rather than behaviour

`FrontendContractTest` asserts on file contents, which is unusual and deliberate. The failures it covers are silent in an application and invisible to a test that only exercises the server: a panel page with no declared layout renders inside the host's shell and answers 200.

```php
it('declares a layout on every published panel page', function (): void {
    $without = [];

    foreach (panelPageFiles() as $path) {
        if (! str_contains(File::get($path), 'defineOptions({ layout:')) {
            $without[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($without)->toBe([]);
});
```

`IconRegistryTest` is the other one of this kind: it walks panel icons, navigation items, resource navigation icons and every record and bulk action, and asserts each name exists in `resources/js/panel/icons/registry.ts`. An unregistered icon renders nothing at all, with no error. Use `Action::getIcon()` there rather than `toArray()` — `toArray(null)` returns null whenever the action is hidden or unauthorized for the absent record, so collecting icons through it gathers nothing and passes for the wrong reason.

## Gotchas

- **`Event::fake()` with no arguments breaks the panel.** It silences the model events resources rely on. `fakePanelNotifications()` fakes exactly one event class for this reason.
- **A fixture panel must call `->settings(false)`.** A panel built inside a test registers no routes, so its three built-in account pages have no route to link to and navigation building throws "Route not defined".
- **Registering a panel twice throws.** The registry survives between tests in one process; guard with `PanelManager::has()`.
- **Registering routes in a test needs `Route::getRoutes()->refreshNameLookups()`** before `Route::has()` or `route()` will see them.
- **Transaction levels are relative.** The suite already holds one for its own rollback, so a wrapped call is `DB::transactionLevel()` baseline plus one, not one.
- **`Gate::define()` will not override a registered policy.** To test that an unauthorized page is dropped, swap the policy with `Gate::policy(Model::class, FixturePolicy::class)`.
- **Never assert on rendered `@vite` output.** The tag is a dev-server URL when a hot file exists and a hashed filename after a build, so the assertion depends on whether `npm run dev` happens to be running. Bind `Tests\Fixtures\Panel\RecordingVite` and assert on the entrypoints it captured.
- **`defaultPerPage(2)` without declaring 2 in `perPageOptions()` silently falls back** to the first option. It is a trap in fixtures more than in applications.
- **PHPStan does not analyse `tests/`.** A trait used only by fixtures is reported as unused from `src/`; put shared behaviour where source code uses it.

## See also

- [Local development](local-development.md) — the setup these commands assume
- [Coding standards](coding-standards.md) — what the other two thirds of `composer ci` enforce
- [Security](security.md) — the invariants the negative suite protects, and how to report a hole in one
- [Pull requests](pull-requests.md) — what a change is expected to carry besides a test
- [Test setup](../testing/setup.md) — the same helpers, from an application's side
- [Testing helpers](../testing/helpers.md), [tables](../testing/tables.md), [forms](../testing/forms.md), [actions](../testing/actions.md)
- [Negative security tests](../testing/negative-security-tests.md)
- [Frontend contract tests](../testing/frontend-contract-tests.md)
- [CI matrix](../testing/ci-matrix.md)
