# Panel routes that 404

A panel URL answering 404 is almost always a route that was never registered, rather than a route
that refused. The panel's route table is built during boot from three inputs — the panel list in
config, each panel's registries, and whatever caches are on disk — and every one of them can be
missing or stale without a word in the log. This page walks the causes in the order they actually
occur, and gives the command that fixes each.

## Start here

```bash
php artisan route:list --name=panel.
```

That one command separates the two halves of the problem:

| Result | Meaning | Go to |
| --- | --- | --- |
| No output at all | No panel registered, or route registration is off | [1](#1-the-panel-is-not-in-configpanda-panelphp), [2](#2-the-provider-named-in-config-no-longer-resolves), [9](#9-register_routes-is-false) |
| The panel's routes are there, the resource's are not | The registry does not contain the resource | [3](#3-the-panel-manifest-is-stale), [5](#5-the-discovery-paths-do-not-match-where-the-classes-are) |
| The route is listed and the URL still 404s | The compiled table differs, or the record does not resolve | [4](#4-routecache-ran-before-the-panel-existed), [7](#7-the-route-matched-and-the-record-did-not) |
| The route is listed at a path you did not type | A cluster, a slug, or a singular resource | [8](#8-the-route-exists-at-a-different-path) |

Three more diagnostics, in the order they are worth running:

```bash
php artisan panel:cache      # discovery once, reported by count
php artisan panel:clear      # drop the manifest, so discovery runs again
php artisan optimize:clear   # config, routes, views, events — and the panel manifest
```

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::resources('admin')->all();   // list<class-string> — what the panel actually holds
PandaPanel::pages('admin')->all();
PandaPanel::widgets('admin')->all();
```

## 1. The panel is not in `config/panda-panel.php`

**Symptom.** `/admin` is a 404. The provider file exists. `make:panel` reported success.
`route:list --name=panel.` is empty.

**Cause.** Panels are listed, not discovered. Registration order decides which panel a user is sent
to when a request does not name one, and adding a panel should be a deliberate edit rather than a
filesystem side effect — so a provider that is not in the list is not registered, has no route
group, and therefore has no URL. This is the single most common "it did not work" after an install.

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

```bash
php artisan panel:install --no-panel --no-user --no-interaction   # re-runs every check
php artisan route:list --name=panel.
```

`panel:install` writes that line itself. When it cannot, it says which of the two reasons applied:

```text
  1. Add App\Panels\Admin\AdminPanelProvider::class to 'panels' in config/panda-panel.php.
     (The config has not been published)
```

```text
     (The config has been reshaped, so it was left alone)
```

The second is `PandaPanel\Support\Installer\PanelRegistrar` refusing to edit a `panels` key that is
built from a variable or a function call rather than written as a literal array. Nothing is
rewritten by guesswork; the line is printed for you to add.

`make:panel` on its own only prints the line, because a generator editing config silently is how a
project loses track of its panel order.

## 2. The provider named in config no longer resolves

**Symptom.** Identical to the above: an empty `route:list`, and a `panels` array that looks right.

**Cause.** A class name that no longer resolves is **skipped rather than fatal**. A boot-time fatal
would take down every route in the application, including the one that would have shown you the
error.

```php
// PandaPanelServiceProvider::configuredPanels()
foreach ($configured as $provider) {
    if (is_string($provider) && is_subclass_of($provider, PanelProvider::class)) {
        $panels[] = $provider;
    }
}
```

So two mistakes look exactly like a missing entry: a typo in the class name, and a class that does
not extend `PandaPanel\Core\PanelProvider`.

**Confirm it.**

```php
class_exists(App\Panels\Admin\AdminPanelProvider::class);                       // false — typo
is_subclass_of(App\Panels\Admin\AdminPanelProvider::class, PandaPanel\Core\PanelProvider::class);
```

```bash
composer dump-autoload -o
php artisan panel:cache      # prints the panels it actually found
```

```text
INFO  Panels cached: 2 panels, 1 resources, 5 pages, 4 widgets.
```

A count of `0 panels` is the same answer `route:list` gave, in a place where it is unambiguous.

## 3. The panel manifest is stale

**Symptom.** A resource, page or widget you just added is invisible. No route, no navigation entry,
no error. Everything that existed before still works.

**Cause.** `php artisan panel:cache` writes `bootstrap/cache/panels.php`, and **with a manifest
present, discovery does not run at all** — no filesystem scan, no reflection, nothing per request.
That is the point of it and it is the trap: a class added afterwards is simply not in the panel.

**Confirm it.** In development the framework says so at boot:

```text
[panel] The cached panel manifest is out of date: the classes under the discovery paths have
changed since `php artisan panel:cache` last ran. Until you run `php artisan panel:clear`,
anything added since then is invisible — no route, no navigation entry, and no error to say so.
```

```php
use PandaPanel\Cache\PanelManifest;

app(PanelManifest::class)->exists();        // true — a manifest is in charge
PanelManifest::path();                      // '/var/www/app/bootstrap/cache/panels.php'
```

**Fix.**

```bash
php artisan panel:clear      # development: go back to discovering on every boot
php artisan panel:cache      # deploy time: rebuild it, after the code is in place
```

### How the warning knows

`PandaPanel\Cache\DiscoveryFingerprint` summarises each discovery path as `count:newest-mtime` —
one `stat` per PHP file, against a failure whose only symptom is absence.

| Member | Signature | Notes |
| --- | --- | --- |
| `of` | `static of(array $panels): string` | `xxh128` over every panel's discovery paths, sorted |
| `isStale` | `static isStale(array $panels, ?string $recorded): bool` | false whenever the answer cannot be established |

```php
use PandaPanel\Cache\DiscoveryFingerprint;

DiscoveryFingerprint::of([panel('admin')]);   // '9f0c…'
```

It is computed **only in development** — `app()->hasDebugModeEnabled()`, or the `local` or
`testing` environment — and only when a manifest exists at all. In production the manifest is the
authority and nothing touches the filesystem, which is what the cache is for. A missing path
summarises as `missing`, so a renamed directory is itself a change worth noticing: a panel pointed
at a directory that is not there discovers nothing, quietly.

| `PanelManifest` member | Signature |
| --- | --- |
| `path` | `static path(): string` — through `bootstrapPath()`, so a moved cache directory is still found |
| `exists` | `exists(): bool` |
| `for` | `for(Panel $panel): array{resources: list<string>, pages: list<string>, widgets: list<string>}` |
| `write` | `write(PanelRegistry $registry): array` — atomic, and records a fingerprint |
| `clear` | `clear(): bool` — a missing manifest is success |
| `warnIfStale` | `warnIfStale(PanelRegistry $registry): void` |

## 4. `route:cache` ran before the panel existed

**Symptom.** The URL 404s on a server and works locally. `route:list` on that server does not show
the panel's routes, or shows an older set.

**Cause.** With a compiled route table present, Laravel uses it instead of whatever providers
registered during boot. `PanelRouteRegistrar::registerAll()` still runs — it is a provider `boot()`
method — but the routes it produced are not the ones serving the request.

**Fix.**

```bash
php artisan route:clear
php artisan route:cache
```

Or the whole set, which is what a deploy should run once the code is in place:

```bash
php artisan optimize          # config, routes, events, views — and panel:cache
php artisan optimize:clear    # and panel:clear
```

`panel:cache` and `panel:clear` are registered as `optimize` hooks under the key `panels`, so a
deploy that already runs `optimize` gets the manifest for free.

**Two caches, two distinct quiet failures.** Getting one and not the other:

| State | Symptom |
| --- | --- |
| Fresh route cache, stale panel manifest | the URL answers, and the resource has no sidebar entry — navigation is built from the registries |
| Stale route cache, fresh panel manifest | the sidebar shows a link that 404s — the route was never compiled |

Neither logs anything in production. Run both, in the same deploy, against the same tree.

**A cached route table outlives a code deploy.** Under a release-directory deploy, `bootstrap/cache`
must belong to the release rather than to a shared directory, or a rollback serves the route table
of a version that is no longer there.

`route:cache` itself never fails because of this package: every panel route points at a controller
method, an invokable controller class, or a `[class, method]` pair, and the only route default is a
class name as a string. If `route:cache` refuses, the closure is in your own route files.

## 5. The discovery paths do not match where the classes are

**Symptom.** `panel:cache` reports `0 resources`, or a number smaller than the number of resource
files on disk.

**Cause, in order of likelihood:**

```php
$panel
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

| Cause | How it presents |
| --- | --- |
| The directory does not exist | contributes nothing, silently — a panel may name a directory a module has not created yet |
| The class is outside every PSR-4 root | `ClassResolver::forPath()` answers `null` and the file is skipped |
| The autoloader has not been regenerated | same as above, after moving or adding a namespace |
| The class is abstract, or an interface | skipped — a directory may legitimately hold a base class |
| The class does not implement the contract | skipped — forms, tables, exporters and enums live in the same directory |
| The class is in *another panel's* discovery path | it is registered there instead |

```php
use PandaPanel\Discovery\ClassResolver;
use PandaPanel\Discovery\PanelDiscoverer;

panel('admin')->getResourceDiscoveryPaths();   // list<string> — absolute directories, accumulated

ClassResolver::forPath(app_path('Panels/Admin/Resources/Users/UserResource.php'));
// 'App\Panels\Admin\Resources\Users\UserResource'

ClassResolver::forPath('/tmp/Orphan.php');     // null — outside every PSR-4 root

app(PanelDiscoverer::class)->resources(panel('admin'));
// [App\Panels\Admin\Resources\Users\UserResource::class]
```

**Fix.**

```bash
composer dump-autoload -o
php artisan panel:clear
php artisan panel:cache
```

| Member | Signature | Contract required |
| --- | --- | --- |
| `Panel::discoverResources` | `discoverResources(string ...$paths): self` | `PandaPanel\Contracts\ResourceContract` |
| `Panel::discoverPages` | `discoverPages(string ...$paths): self` | `PandaPanel\Contracts\PageContract` |
| `Panel::discoverWidgets` | `discoverWidgets(string ...$paths): self` | `PandaPanel\Contracts\WidgetContract` |
| `Panel::getResourceDiscoveryPaths` | `getResourceDiscoveryPaths(): list<string>` | — |
| `PanelDiscoverer::resources` | `resources(Panel $panel): list<class-string<ResourceContract>>` | — |
| `ClassResolver::forPath` | `static forPath(string $path): ?class-string` | — |

Each `discover*` method accumulates rather than replaces, so a module can contribute a directory
without a core change. Paths are scanned recursively, so a resource at
`Resources/Users/UserResource.php` is found without `Resources/Users` being named.

Registering a class explicitly is the way to pull in one that lives outside the panel's own tree,
and it merges with discovery rather than competing with it:

```php
use App\Panels\Admin\Resources\Users\UserResource;

$panel
    ->resources([UserResource::class])
    ->discoverResources(app_path('Panels/Admin/Resources'));
// registered once — the registries are keyed by slug
```

## 6. A nested resource has no parent bound

**Symptom.** `/admin/posts` is a 404 while `/admin/users/3/posts` works. Or every URL of the nested
resource 404s.

**Cause.** A nested resource has no index of its own. Every one of its pages sits beneath a parent
record, and the registrar builds the whole group under the parent's segment:

```php
final class PostResource extends Resource
{
    protected static string $model = Post::class;

    /** @var class-string<PandaPanel\Resources\Resource>|null */
    protected static ?string $parentResource = UserResource::class;

    /** The relation on the parent holding these records. Defaults to the plural slug, camelised. */
    protected static ?string $parentRelationship = 'posts';
}
```

```text
prefix:     users/{parentRecord}/posts
middleware: ResolveParentRecord:App\Panels\Admin\Resources\Posts\PostResource
```

So `/admin/posts` does not exist and never did — that is the shape of the feature, not a fault.

**The four ways a nested URL 404s at request time**, all from
`PandaPanel\Http\Middleware\ResolveParentRecord`:

```php
$parentResource = $resource::parentResource();

abort_if($parentResource === null, 404);                 // 1. not actually nested

$key = $request->route(ParentRecord::routeParameter());

abort_unless(is_string($key), 404);                      // 2. no {parentRecord} segment matched

$parent = ParentRecord::resolve($parentResource, $key);

abort_if($parent === null, 404);                         // 3. no such parent, or not viewable

ParentRecord::bind($parent);
```

The third is the one worth understanding: the parent is resolved through the **parent resource's
own `query()`** and authorized with its `canView()`, so a parent the user could not have opened is
a 404 here too. Without that, `/users/9/posts` would be a way to read user 9's children while
`/users/9` itself was refused.

| `PandaPanel\Support\ParentRecord` member | Signature |
| --- | --- |
| `routeParameter` | `static routeParameter(): string` — `'parentRecord'` |
| `resolve` | `static resolve(string $parentResource, int\|string $key): ?Model` |
| `bind` | `static bind(Model $record): void` |
| `current` | `static current(): ?Model` |
| `require` | `static require(string $resource): Model` — throws rather than querying unscoped |
| `assertRegistered` | `static assertRegistered(string $resource, string $parent): void` |

**The boot-time failure is louder, and better.** A parent that is not registered in the same panel
would produce a path built from its default slug, pointing at a route that does not exist:

```text
PandaPanel\Exceptions\PanelRegistrationException

[App\Panels\Admin\Resources\Posts\PostResource] is nested under
[App\Panels\Admin\Resources\Users\UserResource], which is not registered in the same panel.
```

Register the parent in the same panel, or drop `$parentResource`.

**Build nested URLs through the resource, never by hand.** The parent comes from the request's own
bound record, so links between a nested resource's pages need no extra argument:

```php
PostResource::url('edit', $post);              // '/admin/users/3/posts/7/edit'
PostResource::url('edit', $post, parent: $otherUser);
```

And outside a request — a console command, a queued job — there is no bound parent, so
`ParentRecord::require()` throws:

```text
[PostResource] is a nested resource, so it can only be reached under a parent record.
None is bound to this request.
```

## 7. The route matched and the record did not

**Symptom.** `/admin/users/41/edit` is a 404 while `/admin/users` lists rows.

**Cause.** Record lookups go through `Resource::query()`, which is the single funnel every read
passes: the list, the record page, the actions, the bulk endpoints, global search and exports. A
key that is not in that query does not resolve, and a record the panel may not reach is a **404,
not a 403**.

Four narrowings can remove it:

| Narrowing | Where it comes from |
| --- | --- |
| The panel's per-panel query | `ResourceConfiguration::applyQuery()` |
| The tenant scope | `$tenantRelationship` — see [Tenant scope leaks](tenancy-scope-leaks.md) |
| The parent relation | a nested resource, section 6 |
| Soft deletes | a trashed record is out of scope until `$softDeletes` is on |

```php
use App\Panels\Admin\Resources\Users\UserResource;

UserResource::query()->whereKey(41)->exists();   // false — this is why the page 404s
```

An out-of-scope key answering 404 rather than 403 is deliberate: 403 would confirm the record
exists.

## 8. The route exists at a different path

`route:list` shows the route and the URL you typed is not it. Four things move a path without
moving the route *name*:

| Cause | Path | Route name |
| --- | --- | --- |
| `protected static ?string $slug = 'people';` | `/admin/people` | `panel.admin.resources.people.*` |
| A cluster | `/admin/{cluster-slug}/users` | `panel.admin.resources.users.*` — unchanged |
| A nested resource | `/admin/users/{parentRecord}/posts` | `panel.admin.resources.posts.*` |
| `protected static bool $singular = true;` | `{record}` stripped from every path | unchanged |

A cluster prefixes the path and nothing else, which is what makes adopting one a non-breaking
change: every `Resource::url()` in the application keeps working and only the URL it produces moves.

Always build URLs from route names rather than by hand:

```php
public static function routeName(string $page = 'index', Panel|string|null $panel = null): string

public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string
```

```php
UserResource::url();                          // '/admin/users'
UserResource::url('edit', $user);             // '/admin/users/3/edit'
UserResource::routeName('index', 'admin');    // 'panel.admin.resources.users.index'
```

Two failures here are exceptions rather than a guess, and both name the problem:

```php
UserResource::url();               // outside a panel: 'There is no current panel for this request…'
UserResource::url(panel: 'app');   // 'The resource […] is not registered in the panel [app]…'
```

**A page that is not in `pages()` has no route.** The four standard keys have fixed shapes and
anything else is a custom page — one GET at `ResourcePage::routePath($key)`:

```php
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
        'create' => CreateUser::class,
        'view' => ViewUser::class,
        'edit' => EditUser::class,
        'activity' => UserActivity::class,   // GET {record}/activity, named …resources.users.activity
    ];
}
```

Dropping `'view'` from that array is how a resource has no view page, and `/admin/users/3` 404s as
a result. A **singular** resource reduces both `index` and `view` to `/`, so it declares one or the
other rather than both.

## 9. `register_routes` is false

```php
// config/panda-panel.php
'register_routes' => true,
```

With it off, nothing is registered and nothing is cached. The registries are still built, so
`PandaPanel::resources('admin')->all()` still answers — which is exactly why this state is hard to
recognise from PHP. Register the groups yourself if you meant it:

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

app(PanelRouteRegistrar::class)->register(app(PanelManager::class)->get('admin'));
```

| Member | Signature |
| --- | --- |
| `registerAll` | `registerAll(): void` — every registered panel |
| `register` | `register(Panel $panel): void` — one panel |

This is for a harness that boots panels without HTTP, or an application that needs the groups at a
particular position in the route file. It is not something a normal deploy touches.

## 10. The panel is pinned to a domain

```php
$panel->path('admin')->domain('admin.example.test');
```

A panel with a domain registers its group with that domain, so the same path on any other host has
no route. `PanelManager::resolveFromRequest()` skips a panel whose `domain()` does not match the
request host, which is also why a guest on the wrong host is redirected to the application's login
rather than the panel's.

```bash
php artisan route:list --name=panel.admin --columns=domain,uri,name
```

## 11. The integrations screen 404s

**Cause.** Integration routes are registered only for a resource that opted in. A resource that
never did registers nothing, so its integrations URL **404s rather than answering 403** — there is
no screen to be refused.

```php
use PandaPanel\Integrations\Integrations;

public static function integrations(Integrations $integrations): Integrations
{
    return $integrations->isEnabled(true);
}
```

```php
UserResource::integrationSettings()->enabled();   // bool — what the registrar asks
```

## 12. Two routes claimed one path

This one is not a 404 — it is the failure a 404 would have been if the framework let it happen.
Laravel matches the first route for a path and silently ignores the rest, so the registrar
normalises every path shape it claims (parameter *names* are erased: `{record}` and
`{parentRecord}` compare equal) and refuses a second claim at boot:

```text
The path [projects/{record}/tasks] is registered by both
[App\Panels\Admin\Resources\Projects\ProjectResource] and
[App\Panels\Admin\Resources\Tasks\TaskResource]. Only the first would ever match.
Give one of them a different slug or route path.
```

A `ManageRelatedRecords` page at `projects/{record}/tasks` and a nested resource at
`projects/{parentRecord}/tasks` are the same path as far as matching is concerned, which is the
collision this catches most often.

Claims are tracked per panel, since two panels sit under different prefixes and cannot shadow each
other.

## The commands, in one place

| Command | What it does |
| --- | --- |
| `php artisan route:list --name=panel.` | every route this package registered |
| `php artisan route:clear` | drop a compiled route table |
| `php artisan route:cache` | compile one from the current boot |
| `php artisan panel:cache` | run discovery once and write `bootstrap/cache/panels.php` |
| `php artisan panel:clear` | remove the manifest; a missing one is success, not an error |
| `php artisan optimize` | config, routes, events, views — and `panel:cache` |
| `php artisan optimize:clear` | all of those, and `panel:clear` |
| `php artisan panel:install --no-panel --no-user --no-interaction` | re-run every install check, change nothing |
| `composer dump-autoload -o` | rebuild the PSR-4 map discovery resolves through |

## Notes

- **A 404 is a route problem; a 403 is a rule.** A guest is never given a 403 for a panel URL, and
  a signed-in user is never redirected for one. If the status is 403, this is not the page —
  see [403 responses](authorization-403.md).
- **A resource missing from the sidebar is more often a policy than a route.** `Gate::allows()`
  denies when no policy exists, which is indistinguishable from one that considered the question.
  Check the URL directly before concluding the route is absent.
- **`panel:cache` is not `route:cache`, and neither is a frontend cache.** Three separate things;
  `optimize` runs two of them and `npm run build` is the third.
- **A provider listed twice is registered once.** The registry keys by panel id and re-registering
  would run discovery again for no change in outcome.
- **Two panels with the same id, or the same path on the same domain, fail at boot** with
  `PanelRegistrationException` rather than shadowing each other silently.
- **Route names are what Wayfinder generates from.** Keep them stable; the path is the part that is
  safe to move. Renaming a panel changes every `panel.{id}.*` name at once.
- **`register_web_middleware => false` does not affect routing**, only the four `web` middleware.
  A panel whose routes exist but whose pages render without shared props is that key, not this page.

## See also

- [Routing](../concepts/routing.md) — the full route table, group by group
- [Discovery](../concepts/discovery.md), [caching](../concepts/caching.md),
  [request lifecycle](../concepts/request-lifecycle.md)
- [Route cache](../deployment/route-cache.md), [panel cache](../deployment/panel-cache.md),
  [composer and autoloading](../deployment/composer.md),
  [production checklist](../deployment/production-checklist.md)
- [`panel:cache`](../cli/panel-cache.md), [`panel:clear`](../cli/panel-clear.md),
  [`panel:install`](../cli/panel-install.md), [`make:panel`](../cli/make-panel.md)
- [Route registration config](../configuration/routes.md),
  [config/panda-panel.php](../configuration/panda-panel.md)
- [Nested resources](../resources/nested-resources.md),
  [URLs and routes](../resources/urls-routes.md), [resource queries](../resources/queries.md),
  [singular resources](../resources/singular-resources.md),
  [directory convention](../resources/directory-convention.md)
- [Clusters](../pages-navigation/clusters.md), [page discovery](../pages-navigation/discovery.md)
- [403 responses](authorization-403.md), [tenant scope leaks](tenancy-scope-leaks.md),
  [login redirects](login-redirects.md)
- [Common install problems](../getting-started/common-install-problems.md)
