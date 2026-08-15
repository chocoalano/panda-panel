# Routing

`PandaPanel\Routing\PanelRouteRegistrar` registers one route group per panel
during boot: the panel's path as a prefix, `panel.{id}.` as a name prefix, and
the panel's middleware stack. Every route points at a controller method, never
a closure, so `php artisan route:cache` keeps working. Reach for this page
when you need a route name, or when a URL is 404 and you need to know what
should have registered it.

## Seeing what a panel registered

```bash
php artisan route:list --path=admin
```

```php
use Illuminate\Support\Facades\Route;

Route::has('panel.admin.dashboard');                        // true
route('panel.admin.dashboard', absolute: false);            // '/admin'
route('panel.admin.resources.users.index', absolute: false);// '/admin/users'
```

Build a name from the panel rather than by hand:

```php
panel('admin')->getRouteNamePrefix();          // 'panel.admin.'
panel('admin')->routeName('dashboard');        // 'panel.admin.dashboard'
```

## The group

```php
$attributes = [
    'prefix' => $panel->getPath(),
    'as' => $panel->getRouteNamePrefix(),
    'middleware' => [
        ...$panel->getMiddleware(),
        ResolvePanel::class.':'.$panel->getId(),
        RequireTwoFactor::class.':'.$panel->getId(),
        RequireEmailCode::class.':'.$panel->getId(),
        ...($panel->hasTenancy() ? [ResolveTenant::class.':'.$panel->getId()] : []),
    ],
];

if ($panel->getDomain() !== null) {
    $attributes['domain'] = $panel->getDomain();
}
```

The panel id is passed to `ResolvePanel` as a parameter rather than matched
from the path. Panel resolution then never depends on path matching, which
keeps two panels sharing a prefix unambiguous. See
[Request Lifecycle](request-lifecycle.md).

`registerAll(): void` loops every registered panel; `register(Panel $panel): void`
does one. The service provider calls `registerAll()` unless
`panda-panel.register_routes` is `false`.

## Panel-level routes

Every panel gets these, under its own prefix.

| Name | Verb | Path | Controller |
| --- | --- | --- | --- |
| `panel.{id}.dashboard` | GET | `/` | `PanelDashboardController` |
| `panel.{id}.search` | GET | `search` | `PanelSearchController` |
| `panel.{id}.options` | GET | `options` | `PanelFormOptionsController` |
| `panel.{id}.uploads` | POST | `uploads` | `PanelUploadController` |
| `panel.{id}.form-state` | POST | `form-state` | `PanelFormStateController` |
| `panel.{id}.export-file` | GET | `exports/{file}` | `PanelExportController` |
| `panel.{id}.import-file` | GET | `imports/{file}` | `PanelImportController` |
| `panel.{id}.notifications.index` | GET | `notifications` | `PanelNotificationController@index` |
| `panel.{id}.notifications.read` | POST | `notifications/read` | `PanelNotificationController@read` |
| `panel.{id}.notifications.clear` | POST | `notifications/clear` | `PanelNotificationController@clear` |

`export-file` is named that rather than `exports` because the route name
becomes an identifier in the generated Wayfinder module, and `exports` is not
a name a TypeScript module can bind.

### Two-factor

Inside the panel's middleware — these are for somebody already signed in — but
exempt from the emailed-code check itself, or answering it would be refused by
the thing being answered.

| Name | Verb | Path | Extra middleware |
| --- | --- | --- | --- |
| `panel.{id}.auth.two-factor.challenge` | GET | `two-factor/challenge` | — |
| `panel.{id}.auth.two-factor.send` | POST | `two-factor/send` | — |
| `panel.{id}.auth.two-factor.verify` | POST | `two-factor/verify` | — |
| `panel.{id}.auth.two-factor.enable` | POST | `two-factor/enable` | `RequirePassword` |
| `panel.{id}.auth.two-factor.disable` | POST | `two-factor/disable` | `RequirePassword` |

### Actions

One action endpoint set per panel rather than per resource. The resource
travels in the payload and is resolved against *this* panel's registry, so a
resource from another panel cannot be addressed here.

| Name | Verb | Path | Method |
| --- | --- | --- | --- |
| `panel.{id}.actions.record` | POST | `actions/record` | `PanelActionController@record` |
| `panel.{id}.actions.bulk` | POST | `actions/bulk` | `PanelActionController@bulk` |
| `panel.{id}.actions.reorder` | POST | `actions/reorder` | `PanelActionController@reorder` |
| `panel.{id}.actions.cell` | POST | `actions/cell` | `PanelActionController@cell` |
| `panel.{id}.actions.table` | POST | `actions/table` | `PanelActionController@table` |
| `panel.{id}.actions.infolist` | POST | `actions/infolist` | `PanelActionController@infolist` |
| `panel.{id}.actions.form` | GET | `actions/form` | `PanelActionFormController@show` |
| `panel.{id}.actions.submit` | POST | `actions/form` | `PanelActionFormController@submit` |

A view page's actions are resolved through `infolist` rather than `record`,
because the two are different whitelists: one lookup for both would let either
page run the other's actions.

### Relations

| Name | Verb | Path | Method |
| --- | --- | --- | --- |
| `panel.{id}.relations.form` | GET | `relations/form` | `PanelRelationController@form` |
| `panel.{id}.relations.save` | POST | `relations/form` | `PanelRelationController@save` |
| `panel.{id}.relations.action` | POST | `relations/action` | `PanelRelationController@action` |
| `panel.{id}.relations.bulk` | POST | `relations/bulk` | `PanelRelationController@bulk` |

The form and save routes take their context from the query string, which is
why they need no path parameters.

## Guest routes

Registered only when the panel called `login()`, and registered *outside* its
auth middleware — putting them behind `auth` would send somebody who cannot
sign in to the page that tells them to sign in. They keep the panel's base
middleware, so they still have a session, a CSRF token, and Inertia.

```php
$panel
    ->login()
    ->registration()
    ->passwordReset()
    ->emailVerification();
```

| Name | Verb | Path | Requires |
| --- | --- | --- | --- |
| `panel.{id}.auth.login` | GET | `login` | `login()` |
| `panel.{id}.auth.register` | GET | `register` | `registration()` |
| `panel.{id}.auth.password.request` | GET | `forgot-password` | `passwordReset()` |
| `panel.{id}.auth.password.reset` | GET | `reset-password/{token}` | `passwordReset()` |
| `panel.{id}.auth.verification.notice` | GET | `verify-email` | `emailVerification()` |

Only the pages. The forms post to Fortify's own endpoints, because duplicating
the login POST per panel would mean duplicating rate limiting, two-factor,
passkeys, and session handling — four things that must never disagree between
two doors into one application.

## Standalone pages

One GET per registered page, at `Page::routePath()`, with the page class bound
into the route defaults so the controller never resolves a class name from a
request.

```php
$this->router
    ->get($page::routePath(), PanelPageController::class)
    ->defaults('page', $page)
    ->name('pages.'.$page::slug());
```

| Page API | Signature | Default |
| --- | --- | --- |
| `slug` | `static slug(): string` | `Str::kebab(class_basename(static::class))` |
| `routePath` | `static routePath(): string` | the slug, prefixed by the cluster's slug when there is one |
| `middleware` | `static middleware(): list<string>` | `[]`, appended to this route only |
| `routeName` | `static routeName(Panel\|string\|null $panel = null): string` | `panel.{id}.pages.{slug}` |
| `url` | `static url(Panel\|string\|null $panel = null): string` | relative URL |

```php
use App\Panels\Admin\Pages\Settings;

Settings::slug();                 // 'settings'
Settings::routeName('admin');     // 'panel.admin.pages.settings'
Settings::url('admin');           // '/admin/settings'
```

A panel's extra dashboards are registered here too, deduplicated by class: one
that also lives under a discovered path arrives twice and is still one page.

## Resource routes

Registered from `Resource::pages()`, keyed by page name, so the route name and
the page class stay in one place.

```php
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
        'create' => CreateUser::class,
        'view' => ViewUser::class,
        'edit' => EditUser::class,
    ];
}
```

The four standard keys have fixed shapes:

| Key | Verb | Path | Method | Route name suffix |
| --- | --- | --- | --- | --- |
| `index` | GET | `/` | `render` | `index` |
| `create` | GET | `create` | `render` | `create` |
| `create` | POST | `create` | `handle` | `store` |
| `create` | POST | `create/step` | `validateStep` | `validateCreateStep` |
| `view` | GET | `{record}` | `render` | `view` |
| `edit` | GET | `{record}/edit` | `render` | `edit` |
| `edit` | PUT | `{record}/edit` | `handle` | `update` |
| `edit` | POST | `{record}/edit/step` | `validateStep` | `validateEditStep` |

Static segments are registered before the `{record}` wildcard, otherwise
`/create` would be matched as a record key. A write verb gets its own route
name because Laravel requires names to be unique.

Anything else in `pages()` is a custom page: one GET, at
`ResourcePage::routePath($key)`, which is the page's `$routePath` when it
declares one and the key otherwise.

```php
final class UserActivity extends ResourcePage
{
    protected static ?string $routePath = '{record}/activity';
}
```

```php
// 'activity' => UserActivity::class  →  GET /admin/users/{record}/activity
//                                       panel.admin.resources.users.activity
```

`ManageRelatedRecords` declares `'{record}/'.$key` by default, so a
`'posts' => ManageUserPosts::class` entry routes at
`/admin/users/{record}/posts`.

### Group attributes

| Case | Prefix |
| --- | --- |
| Plain resource | `{slug}` |
| In a cluster | `{cluster-slug}/{slug}` |
| Nested resource | `{parent-slug}/{parentRecord}/{slug}` |

The route *name* is always `resources.{slug}.` — a cluster prefixes the path
and nothing else, so every `Resource::url()` already written keeps working and
only the URL it produces moves. That is what makes adopting a cluster a
non-breaking change.

A nested resource's group also carries
`ResolveParentRecord::class.':'.$resource`, attached to the group rather than
left to the pages: every route in it needs the scope, and a page that forgot
it would query unscoped. A parent that is not registered in the same panel
throws `PanelRegistrationException::unregisteredParentResource()` at boot,
rather than shipping dead links.

### Singular resources

A resource with `protected static bool $singular = true;` has nothing to
choose between, so `{record}` is stripped from every path:

| Key | Path |
| --- | --- |
| `index` | `/` |
| `create` | `create` |
| `view` | `/` |
| `edit` | `edit` |

`index` and `view` both reduce to `/`, so a singular resource declares one or
the other in `pages()` rather than both — `/settings` and `/settings/edit` is
the shape it is for.

## Building URLs

Always through route names. Building `/admin/users` by hand would break the
moment a panel changed its path, and would bypass the registration check.

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
use App\Panels\Admin\Resources\Users\UserResource;

UserResource::url();                              // '/admin/users'
UserResource::url('edit', $user);                 // '/admin/users/3/edit'
UserResource::url(panel: 'admin');                // '/admin/users'
UserResource::routeName('index', 'admin');        // 'panel.admin.resources.users.index'
```

Two failures are deliberate exceptions rather than a guess:

```php
UserResource::url();               // outside a panel: PanelRegistrationException, 'no current panel'
UserResource::url(panel: 'app');   // not registered there: 'is not registered in the panel [app]'
```

A nested resource's URL carries its parent automatically, from the request's
own bound parent, so links between a nested resource's pages need no extra
argument. Pass `parent:` to build one for a different owner.

## Integration routes

Registered only for a resource whose `integrationSettings()->enabled()` is
true. A resource that never opted in registers nothing, so the URL 404s rather
than answering 403 — there is no screen to be refused.

| Name | Verb | Path |
| --- | --- | --- |
| `panel.{id}.resources.{slug}.integrations` | GET | `integrations` |
| `panel.{id}.resources.{slug}.integrations.store` | POST | `integrations` |
| `panel.{id}.resources.{slug}.integrations.update` | PUT | `integrations/{integration}` |
| `panel.{id}.resources.{slug}.integrations.destroy` | DELETE | `integrations/{integration}` |
| `panel.{id}.resources.{slug}.integrations.send` | POST | `integrations/{integration}/send` |
| `panel.{id}.resources.{slug}.integrations.rotate` | POST | `integrations/{integration}/rotate` |

The slug travels as a route default rather than a path segment: the path is
already inside the resource's prefix, and a second copy of the slug in the URL
would be a second thing that could disagree with the first.

## Path collisions

Laravel matches the first route for a path and silently ignores the rest, so
two resources claiming one shape means one of them is unreachable. The
registrar normalizes every path it claims — parameter names are erased, so
`{record}` and `{parentRecord}` compare equal — and refuses a second claim:

```
PanelRegistrationException::collidingRoutePath()
```

A `ManageRelatedRecords` page at `projects/{record}/tasks` and a nested
resource at `projects/{parentRecord}/tasks` are the same path as far as
matching is concerned, and this catches that at boot rather than leaving it to
be discovered as a page that renders the wrong thing.

Claims are tracked per panel: two panels sit under different prefixes and
cannot shadow each other.

## Notes

- Panels are registered in id order, so route registration order is stable
  across runs and `route:cache` output is deterministic.
- `PanelManager::resolveFromRequest()` matches the longest path prefix first,
  so a panel at `/admin/reports` wins over one at `/admin` for a request to
  `/admin/reports/x`. It also honours `domain()`.
- Nothing here is a closure. If `route:cache` starts failing after you add a
  page, the cause is somewhere else — a closure in `routes/web.php`, or a
  route default holding an object.
- `panda-panel.register_routes => false` skips all of this. Registries are
  still built, so `PandaPanel::resources('admin')->all()` still answers.
- Route names are what Wayfinder generates from. Keep them stable; the path is
  the part that is safe to move.

## See also

- [Request Lifecycle](request-lifecycle.md)
- [Panels](panels.md)
- [Authorization](authorization.md)
- [Discovery](discovery.md)
- [URLs and Routes](../resources/urls-routes.md)
- [Nested Resources](../resources/nested-resources.md)
- [Clusters](../pages-navigation/clusters.md)
- [Route Cache](../deployment/route-cache.md)
