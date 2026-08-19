# Architecture At A Glance

How a request becomes a panel screen: which middleware runs, where the current panel is held, which
registries answer, what is serialized, and what renders it. Read this when a screen behaves in a way
the guides do not explain, or before writing anything that hooks into the request path.

## The whole path in one example

```php
// config/panda-panel.php
'panels' => [App\Panels\Admin\AdminPanelProvider::class],
```

```php
namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class Reports extends Page
{
    protected static ?string $title = 'Reports';

    protected static ?string $navigationIcon = 'layout-grid';
}
```

The class declares; it never routes itself. `Page::slug()` kebab-cases the class basename, so this
page is `reports`, and `Page::render()` — which takes no arguments — is called by
`PanelPageController` after the route has already bound the class.

```bash
php artisan route:list --name=panel.admin.pages
# GET|HEAD  admin/reports  panel.admin.pages.reports › PandaPanel\Http\Controllers\PanelPageController
```

Opening `/admin/reports` runs this:

```text
request
  ↓  web middleware        ResetPanelContext clears any previous panel
  ↓  panel route group     panel middleware, then ResolvePanel:admin
  ↓  PanelContext          the current panel, request-scoped
  ↓  page or resource page authorize → build metadata → serialize
  ↓  Inertia               shared props (panel, navigation) + page props
  ↓  Vue                   PanelLayout → page component → renderers
```

## Two backend namespaces

| Namespace | Role |
| --- | --- |
| `PandaPanel\*` | reusable framework internals |
| `App\Panels\*` | this application's panels |

## Three frontend locations

The split is not optional, because `@inertiajs/vite` only globs `resources/js/pages/**`:

| Location | Role | Inertia-resolvable |
| --- | --- | --- |
| `resources/js/panel/**` | layouts, components, renderers, composables, registries, types | no |
| `resources/js/pages/panel/**` | framework-generic pages | yes |
| `resources/js/pages/Panels/{Panel}/**` | application-specific pages and custom components | yes |

## What the service provider does

`PandaPanel\PandaPanelServiceProvider` registers the container bindings, then does eight things in
`boot()`, in this order:

| Step | What it does |
| --- | --- |
| `registerPanels()` | builds every provider named in `panda-panel.panels`, then warns if the manifest is stale |
| `registerMiddleware()` | aliases the panel middleware, and appends the four `web` middleware unless `register_web_middleware` is false |
| `registerGuestRedirect()` | points `Authenticate::redirectUsing()` at `PanelLoginRedirect` unless `register_guest_redirect` is false |
| `registerRoutes()` | one route group per panel, unless `register_routes` is false |
| `registerIntegrations()` | wires model events for every resource that enabled integrations |
| `registerMigrations()` | loads the package migrations unless `load_migrations` is false |
| `registerPublishing()` | the four publish tags, in console only |
| `registerCommands()` | the thirteen commands, plus the `optimize` hooks for `panel:cache` / `panel:clear` |

Container bindings:

| Binding | Lifetime | Why |
| --- | --- | --- |
| `PandaPanel\Core\PanelRegistry` | singleton | registration happens once |
| `PandaPanel\Support\PanelContext` | **scoped** | one request's panel must not leak into the next |
| `PandaPanel\Discovery\PanelDiscoverer` | singleton | stateless |
| `PandaPanel\Cache\PanelManifest` | singleton | reads one file |
| `PandaPanel\Core\PanelManager` | singleton | holds the per-panel registries |
| `PandaPanel\Support\NavigationBuilder` | singleton | builds navigation per call, holds nothing |
| `PandaPanel\Routing\PanelRouteRegistrar` | singleton | runs once at boot |

A panel provider listed in config that no longer resolves is skipped rather than fataling, so a
renamed class leaves the application reachable.

## Web middleware

Four pieces, appended to the whole `web` group in this order:

| Middleware | Why it is on `web` and not on the panel group |
| --- | --- |
| `ResetPanelContext` | it has to run for requests that never reach a panel, or a non-panel route keeps whatever the previous request left behind |
| `RedirectPanelHome` | a request it answers never reaches a panel screen, so there is nothing to share props for |
| `ShareFlashToast` | it runs on redirects back *out* of a panel |
| `SharePanelData` | a prop added in a new version would otherwise break every application that did not copy it into its own `HandleInertiaRequests` |

They are appended through the HTTP kernel, in an `afterResolving` hook, rather than pushed onto the
router. `bootstrap/app.php` configures the group in its own `afterResolving` hook and then
overwrites whatever the router was holding, so a later hook is the only ordering that survives.

Set `register_web_middleware` to `false` to register them yourself at a position you choose.

## Panel route group middleware

Every panel route group carries, in order:

1. the panel's own `getMiddleware()` — `['web']` unless replaced by `->middleware([...])`
2. `ResolvePanel:{id}`
3. `RequireTwoFactor:{id}`
4. `RequireEmailCode:{id}`
5. `ResolveTenant:{id}`, only when the panel declared tenancy

`ResolvePanel` runs after `auth`, so `$request->user()` is populated before `canAccess` is
evaluated. A signed-in user who fails it gets **403**, never a redirect: hiding navigation is not
access control. Boot callbacks run *after* that check, so a user refused the panel never triggers
its boot work.

Aliases exist for applications that want to name these in their own route definitions. The registrar
names the classes directly, so the aliases are never the framework's own way of reaching them:

| Alias | Class |
| --- | --- |
| `panel` | `PandaPanel\Http\Middleware\ResolvePanel` |
| `panel.two-factor` | `PandaPanel\Http\Middleware\RequireTwoFactor` |
| `panel.email-code` | `PandaPanel\Http\Middleware\RequireEmailCode` |
| `panel.parent` | `PandaPanel\Http\Middleware\ResolveParentRecord` |

## The current panel

`PandaPanel\Support\PanelContext` is a **scoped** container binding — reset at the start of every
request by `ResetPanelContext`. Nothing about the current panel is static, which is what makes the
invariant "no current panel outside a panel" true under Octane and inside a test issuing several
requests.

```php
namespace PandaPanel\Support;

final class PanelContext
{
    public function setPanel(?Panel $panel): void;
    public function panel(): ?Panel;
    public function hasPanel(): bool;
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function forget(): void;
}
```

Read it through the helper rather than the class:

```php
panel();          // Panel|null
panel('admin');   // Panel, throws PanelRegistrationException when unknown
```

A page controller called directly in a test needs the context `ResolvePanel` would have set:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->setCurrentPanel(panel('admin'));
```

## Registries

`PandaPanel\Core\PanelManager` holds four registries per panel, built once during registration:

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::resources($panel);    // ResourceRegistry — keyed by effective slug
PandaPanel::pages($panel);        // PageRegistry — validated against resource slugs
PandaPanel::widgets($panel);      // WidgetRegistry — keyed by widget id
PandaPanel::navigation($panel);   // NavigationRegistry — group order and collapsibility
```

Explicit registration and discovery are merged. A class named in both appears once, because the
registries are keyed by slug and id. A `ResourceConfiguration` is registered first, so the same class
cannot also be registered bare and claim its default slug.

`ResourceRegistry::slugFor($resource)` is what route registration asks, because during boot there is
no current panel to ask the class itself. `Resource::slug()` answers for the current panel and falls
back to `defaultSlug()` outside one.

`PanelRegistry` rejects two ambiguities at registration, both by throwing
`PandaPanel\Exceptions\PanelRegistrationException`: a duplicate panel id, and a duplicate
path/domain pair. Both would otherwise surface as one route silently shadowing another.

## Routes

One group per panel, prefixed with its path, named `panel.{id}.`. Every route points at a controller
method, never a closure, so `php artisan route:cache` keeps working.

| Route name | Verb and path | Controller |
| --- | --- | --- |
| `dashboard` | `GET /` | `PanelDashboardController` |
| `search` | `GET search` | `PanelSearchController` |
| `options` | `GET options` | `PanelFormOptionsController` |
| `uploads` | `POST uploads` | `PanelUploadController` |
| `form-state` | `POST form-state` | `PanelFormStateController` |
| `export-file` | `GET exports/{file}` | `PanelExportController` |
| `import-file` | `GET imports/{file}` | `PanelImportController` |
| `notifications.index` / `.read` / `.clear` | `GET`, `POST read`, `POST clear` | `PanelNotificationController` |
| `auth.two-factor.challenge` / `.send` / `.verify` / `.enable` / `.disable` | under `two-factor` | `PanelTwoFactorController` |
| `actions.record` / `.bulk` / `.reorder` / `.cell` / `.table` / `.infolist` | `POST actions/*` | `PanelActionController` |
| `actions.form` / `.submit` | `GET`/`POST actions/form` | `PanelActionFormController` |
| `relations.form` / `.save` / `.action` / `.bulk` | under `relations` | `PanelRelationController` |
| `pages.{slug}` | `GET` at the page's own path | `PanelPageController` |
| `resources.{slug}.index` / `.create` / `.store` / `.view` / `.edit` / `.update` | under the resource prefix | the page class |
| `resources.{slug}.validateCreateStep` / `.validateEditStep` | `POST .../step` | the page class |
| `resources.{slug}.integrations` (+ `.store`, `.update`, `.destroy`, `.send`, `.rotate`) | under `integrations` | `PanelIntegrationController` |

A panel with `->login()` also registers guest routes — `auth.login`, and `auth.register`,
`auth.password.request`, `auth.password.reset`, `auth.verification.notice` when the matching feature
is on — outside the panel's auth stack but inside its base middleware. Sending somebody who cannot
sign in to the page that tells them to sign in is a loop.

Resource pages are routed as `[Page::class, 'render']` for the GET and `[Page::class, 'handle']` for
the write verb, so pages are real controllers. Two resources claiming one path shape throw at boot
rather than leaving one of them unreachable: parameter names are erased before comparing, because
`{record}` and `{parentRecord}` are the same wildcard as far as the router is concerned.

## Shared props

`SharePanelData` shares ten props through `Inertia::share()`, which merges — the application's own
`HandleInertiaRequests` is untouched. Every value is a closure, so a request that never reaches a
panel pays for none of them, and nothing here is ever cached.

| Prop | Shape |
| --- | --- |
| `panel` | the panel definition, or `null` outside a panel |
| `navigation` | the sidebar groups for this panel and this URL |
| `panels` | the panels this user may enter, for the switcher |
| `broadcasting` | `{enabled, channel}` |
| `search` | `{enabled, url, debounce, keyBindings}` |
| `notifications` | `{enabled, indexUrl, readUrl, clearUrl, unread}` |
| `tenancy` | `{current, available}`, or `null` for a panel with no tenancy |

`Panel::toSharedArray()` decides what a panel says about itself. Transactions, strict authorization,
discovery paths, middleware and boot callbacks stay on the server; only the settings the frontend
acts on cross.

## Discovery and the manifest

```php
->discoverResources(app_path('Panels/Admin/Resources'))
->discoverPages(app_path('Panels/Admin/Pages'))
->discoverWidgets(app_path('Panels/Admin/Widgets'))
```

`PandaPanel\Discovery\PanelDiscoverer` turns file paths into class names through Composer's
registered PSR-4 prefixes. It does not parse or evaluate source — the autoloader already knows what a
file declares. Only concrete classes implementing the expected contract
(`ResourceContract`, `PageContract`, `WidgetContract`) are included, and results are sorted so two
machines produce the same manifest.

```bash
php artisan panel:cache
php artisan panel:clear
```

`PandaPanel\Cache\PanelManifest::path()` is `bootstrapPath('cache/panels.php')` — beside the config,
route and event caches, so `optimize:clear` finds it. It holds **class names only**:

```php
return array (
  'admin' =>
  array (
    'resources' => array ( 0 => 'App\\Panels\\Admin\\Resources\\Users\\UserResource' ),
    'pages' => array ( 0 => 'App\\Panels\\Admin\\Pages\\Settings' ),
    'widgets' => array ( /* ... */ ),
  ),
);
```

With a manifest present, discovery does not run at all. Never cached: authorization results,
navigation active state, badge values, record data, widget data. All of those depend on the current
user or URL, so caching them would serve one person's answers to another.

## Boundaries that hold everywhere

- **Metadata only.** Schemas serialize to scalars and arrays. A closure is evaluated on the server
  and only its result is sent.
- **Authorization is server-side.** Hiding a button or a navigation item is a convenience. Routes,
  actions, pages and widgets each authorize independently.
- **One query.** `Resource::query()` is the single source for list, view, edit, update, delete, bulk
  and action lookups.
- **The URL is the table state.** Page, per-page, search, sort, direction and filters live in the
  query string, so back, forward, refresh and bookmark behave.
- **Nothing dynamic from a request.** Icons and custom components resolve through build-time
  registries; a name that is not registered renders nothing rather than being fetched.
- **Cache class names, never answers.**

## Gotchas

- `ResolvePanel` takes the panel id as a middleware parameter rather than matching the path. Panel
  resolution therefore never depends on path matching, which keeps two panels sharing a prefix
  unambiguous. `PanelManager::resolveFromRequest()` exists for code outside a panel route and
  matches longest-path-first.
- Turning `register_web_middleware` off removes `ResetPanelContext` too. Under Octane that is the
  one you cannot skip.
- Transactions resolve most-specific-first: an action's `->databaseTransaction(bool)`, then a page's
  `$hasDatabaseTransactions`, then the panel, then on. `null` at any level means "did not decide"
  rather than "off". Outside a panel the answer is on. `DeleteBulkAction` is transactional whatever
  the panel says.
- Render hooks are filtered in Vue rather than on the server. Shared props are built in middleware,
  before the request reaches a page, so the shell knows which page it is rendering and the
  middleware does not.
- Queued work is outside the request and therefore outside `PanelContext`. The package's own jobs
  carry a panel id and re-resolve it in `handle()`; tenant-scoped work has to enter a tenant
  explicitly with `Tenancy::for()`.

## See also

- [Inertia and Vue Approach](inertia-vue.md) — the other half of this path
- [Request Lifecycle](../concepts/request-lifecycle.md) and [Panel Context](../concepts/panel-context.md)
- [Routing](../concepts/routing.md), [Discovery](../concepts/discovery.md), [Caching](../concepts/caching.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
- [Middleware Configuration](../configuration/middleware.md)
- [Feature Overview](features.md) — what these registries hold
- [Package Limits and Tradeoffs](tradeoffs.md)
