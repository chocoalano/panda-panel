# Test Setup

Everything a panel does is an ordinary Laravel request, so most of a panel test suite is Laravel testing you already know: `actingAs()`, a factory, a `get()`, an assertion about the database. What the package adds is a set of autoloaded helpers for the questions HTTP cannot answer cleanly — "would this user see this row", "is this field required", "can this action run" — and a rule about what is worth asserting. This page is how to get a suite to the point where those helpers work.

## A minimal working example

Nothing to install, nothing to import:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;

it('lists users to an administrator and refuses everybody else', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create();

    $this->actingAs($admin)->get('/admin/users')->assertOk();
    $this->actingAs($member)->get('/admin/users')->assertForbidden();

    $this->actingAs($admin);

    panelTable(UserResource::class)
        ->assertCanSeeRecord($admin)
        ->assertCanSeeRecord($member);
});
```

`panelTable()` needs no `use` statement. It is a free function registered through Composer's `autoload.files`, which means it is loaded by the autoloader in every process that requires this package — including your test process — without a base class, a trait, or a service provider.

## What ships, and where it comes from

`composer.json` declares two autoloaded files:

```json
"autoload": {
    "psr-4": { "PandaPanel\\": "src/" },
    "files": [
        "src/Support/helpers.php",
        "src/Testing/helpers.php"
    ]
}
```

`src/Testing/helpers.php` defines twelve functions, each guarded by `function_exists`, delegating to four public classes:

| Class | Free functions |
| --- | --- |
| `PandaPanel\Testing\TestsTables` | `panelTable()` |
| `PandaPanel\Testing\TestsSchemas` | `panelForm()`, `panelInfolistLabels()` |
| `PandaPanel\Testing\TestsActions` | `panelRecordActions()`, `panelTableActions()`, `panelBulkActions()`, `panelInfolistActions()` |
| `PandaPanel\Testing\TestsNotifications` | `fakePanelNotifications()`, `assertPanelNotificationSentTo()`, `assertNoPanelNotifications()`, `assertPanelNotificationStoredFor()`, `assertNoPanelNotificationsStoredFor()` |

They are shipped rather than kept in this repository's own `tests/` because the question they answer is the same question in an application's suite as in this one. See [Helpers](helpers.md) for every signature.

## The application suite

A Laravel application already has a test suite. A panel needs nothing added to it beyond the two things every feature test needs — a database and a signed-in user:

```php
// tests/Pest.php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

The panel reads `auth()->user()` for every authorization question — the panel's own gate, the resource policy, an action's `authorize()` closure, an editable column's `disabledUsing()` — so a test that forgets `actingAs()` is asking those questions of a guest, and the answers will be uniformly "no".

```php
use App\Models\User;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($this->admin);
});
```

## Panel context outside a request

`ResolvePanel` middleware binds the current panel at the front of every panel route. A helper called outside a request has no middleware behind it, so anything that depends on which panel is current — per-panel resource configuration, tenancy scoping, strict authorization — reads as "no panel" unless the test says otherwise.

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->setCurrentPanel(panel('admin'));
```

| Call | Signature | Returns |
| --- | --- | --- |
| `panel()` | `panel(?string $id = null): ?Panel` | the current panel, or null outside one |
| `panel('admin')` | — | that panel; throws `PanelRegistrationException` when the id is not registered |
| `PanelManager::setCurrentPanel()` | `setCurrentPanel(?Panel $panel): void` | — |
| `PanelManager::currentPanel()` | `currentPanel(): ?Panel` | the bound panel |
| `PanelManager::has()` | `has(string $id): bool` | whether a panel is registered |
| `PanelManager::get()` | `get(string $id): Panel` | the panel, or throws |

Do this in `beforeEach` for any test file that calls the helpers directly rather than through a URL:

```php
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->actingAs(User::factory()->create(['is_admin' => true]));
});
```

Setting it back to `null` is how you assert the *absence* of panel-dependent behaviour:

```php
app(PanelManager::class)->setCurrentPanel(null);

// A tenant-scoped resource is unscoped here, because tenancy is a property
// of the panel rather than of the resource.
expect(DocumentResource::query()->count())->toBe(3);
```

## Reading an Inertia response

Panel pages are Inertia responses. Two ways in, and they see different things:

```php
use Inertia\Testing\AssertableInertia;

$this->get('/admin/users')
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->component('panel/resources/Index')
        ->where('state.sort', null)
        ->has('rows', 10));
```

```php
// The raw page object, which is where flash data lives — beside `props`,
// not inside it, so the Inertia assertions above cannot reach it.
$page = $this->get('/admin/users')->viewData('page');

$rows = $page['props']['rows'];
$toast = $page['flash']['toast'] ?? null;
```

Pulling a column out of `rows` is the most common shape in this repository's suite:

```php
/**
 * @return list<string>
 */
function namesOn(string $url): array
{
    return collect(test()->get($url)->viewData('page')['props']['rows'])
        ->pluck('cells.name.value')
        ->all();
}
```

`cells.name.value` rather than `cells.name` because `name` is a `TextInputColumn` in the example application: an editable cell is `['value' => …, 'disabled' => …]`. See [Testing tables](tables.md).

A **partial reload** — how a lazy widget's data arrives — needs the asset version, or Inertia answers 409 and asks the browser to do a full visit instead:

```php
$version = $this->get('/admin')->viewData('page')['version'];

$this->get('/admin', [
    'X-Inertia' => 'true',
    'X-Inertia-Version' => $version,
    'X-Inertia-Partial-Component' => 'panel/Dashboard',
    'X-Inertia-Partial-Data' => 'widgetData',
])->assertOk();
```

## Registering a panel inside a test

A test that needs a panel the application does not have — a scoped resource, a tenant-scoped panel, a fixture with one deliberately broken thing — builds one and registers its routes:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

beforeEach(function (): void {
    $manager = app(PanelManager::class);

    if (! $manager->has('scope-host')) {
        $panel = $manager->register(
            Panel::make('scope-host')
                ->path('scope-host')
                ->settings(false)
                ->resources([ScopedUserResource::class]),
        );

        app(PanelRouteRegistrar::class)->register($panel);
    }

    $manager->setCurrentPanel($manager->get('scope-host'));
});
```

Three things matter here:

- **`if (! $manager->has(...))`.** The registry survives between tests in a single process; registering twice throws.
- **`->settings(false)`** keeps the three account pages every panel gets by default out of the way, so a discovery assertion is about what the fixture declared.
- **`PanelRouteRegistrar::register()`** is what makes the panel reachable by URL. `Panel::make()` alone gives you an object, not a route. When the test then resolves routes *by name*, call `Route::getRoutes()->refreshNameLookups()` afterwards.

## Running the suite

The package's own scripts, from `composer.json`:

| Command | What it runs |
| --- | --- |
| `composer test` | `vendor/bin/pest` |
| `composer test-coverage` | `vendor/bin/pest --coverage` |
| `composer analyse` | `vendor/bin/phpstan analyse --memory-limit=1G` |
| `composer format` | `vendor/bin/pint` |
| `composer format-check` | `vendor/bin/pint --test` |
| `composer ci` | `format-check`, then `analyse`, then `test` |

```bash
vendor/bin/pest tests/Feature/Panel
vendor/bin/pest --filter=ResourceQuery
vendor/bin/pest --compact
```

The frontend is checked by `package.json` scripts, which no PHP job can stand in for:

```bash
npm ci
npm run format:check
npm run lint
npm run typecheck
npm run build
npm run ci        # all four, in that order
```

See [CI matrix](ci-matrix.md) for how these are combined in GitHub Actions.

## How this package's own suite is built

Worth reading if you are contributing, or if you want a package of your own to test a panel the same way. `phpunit.xml`:

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

`tests/TestCase.php` extends `Orchestra\Testbench\TestCase` and does five things:

| Method | Why |
| --- | --- |
| `getPackageProviders($app)` | registers Inertia, Fortify, `PandaPanelServiceProvider`, and the example application's Fortify provider |
| `applicationBasePath()` | points `base_path()` at the package root, so the icon registry, the generator stubs and the published TypeScript are the real files rather than Testbench's empty skeleton |
| `resolveApplicationConfiguration($app)` | `useAppPath(examples/app)`, `useDatabasePath(examples/database)`, `useBootstrapPath(…/testbench-core/laravel/bootstrap)`, `useStoragePath(build/testbench/storage)` |
| `defineEnvironment($app)` | sqlite `:memory:`, array session and cache, sync queue, array mail, a `FakeVite` binding, and the two example panels in `panda-panel.panels` |
| `defineRoutes($router)` / `defineDatabaseMigrations()` | the example application's own routes and migrations, plus Fortify's, Passkeys', and the package's |

`tests/Pest.php` calls `TestCase::prepareWritableDirectories()` before the first application is built — Laravel writes its package manifest while an application is still being constructed, so the directory has to exist before anything can move it.

The application half is `examples/`, autoloaded under `App\` for development only. Using the examples as the test application means they are exercised by the suite rather than left to rot beside it: `App\Models\User`, `App\Panels\Admin`, `App\Panels\App`, `App\Policies\UserPolicy`. Every snippet in these testing pages that names `UserResource` is naming a resource the suite actually runs.

`FakeVite` is bound in place of `Illuminate\Foundation\Vite` so that no PHP test depends on `npm run build` having been run.

## Gotchas

- **`actingAs()` before the helpers.** Every helper asks authorization questions of the current user. A helper called before `actingAs()` is asking them of a guest.
- **Set the panel when you are not making a request.** Without `setCurrentPanel()`, per-panel configuration and tenancy behave as if there is no panel — which is a valid state and not the one you meant to test.
- **`panel:cache` is not involved.** The manifest is a production optimisation. Tests discover from disk, which is why a resource added mid-suite is found.
- **Flash is not a prop.** `assertInertia()` cannot see it. Read `viewData('page')['flash']`.
- **A registry throws on a duplicate.** Guard fixture panel registration with `has()`; two resources claiming one slug, or a page whose slug collides with a resource, is a `PanelRegistrationException` at registration time.
- **`Event::fake()` with no arguments breaks the panel.** It silences the model events resources rely on. `fakePanelNotifications()` fakes exactly one event class for this reason.

## See also

- [Testing helpers](helpers.md)
- [Testing tables](tables.md), [forms](forms.md), [actions](actions.md)
- [Testing authorization](authorization.md) and [tenancy](tenancy.md)
- [Negative security tests](negative-security-tests.md)
- [CI matrix](ci-matrix.md)
- [Panel context](../concepts/panel-context.md)
- [Request lifecycle](../concepts/request-lifecycle.md)
