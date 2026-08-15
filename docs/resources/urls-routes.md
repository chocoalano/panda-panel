# URLs And Route Names

Every link into a resource is built from a route name, never from a string. That is what makes a panel's path movable, a slug configurable per panel, and a link into a panel that does not register the resource a loud failure instead of a 404 discovered later. This page covers `Resource::url()` and `Resource::routeName()`, the names a resource registers, and who owns the slug those names are built from.

## Building a URL

```php
use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;

$user = User::query()->firstOrFail();

UserResource::url();                        // '/admin/users'
UserResource::url('create');                // '/admin/users/create'
UserResource::url('view', $user);           // '/admin/users/3'
UserResource::url('edit', $user);           // '/admin/users/3/edit'
UserResource::url('edit', $user, 'admin');  // the same, panel named explicitly

UserResource::routeName();                  // 'panel.admin.resources.users.index'
UserResource::routeName('edit', 'admin');   // 'panel.admin.resources.users.edit'
```

Both are static, both work anywhere a panel is resolved, and `url()` returns a **relative** URL — it is built with `route(..., absolute: false)`.

## `url()`

```php
public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$page` | `string` | `'index'` | The page key, or a write suffix such as `store` or `update` |
| `$record` | `Model\|int\|string\|null` | `null` | The record, or its key. Ignored by a [singular resource](singular-resources.md) |
| `$panel` | `Panel\|string\|null` | `null`, meaning the current panel | Which panel's URL to build |
| `$parent` | `Model\|int\|string\|null` | `null`, meaning the request's bound parent | The owner, for a [nested resource](nested-resources.md) |

A `Model` in either record position is reduced to `$record->getKey()`. Panel URLs are id-based: a model overriding `getRouteKeyName()` does not change what ends up in the URL.

```php
UserResource::url('view', 3);             // a bare key works
UserResource::url('view', $user);         // so does the model
UserResource::url(panel: panel('admin')); // a Panel instance is accepted as well as an id
```

Three things happen inside, in this order: the panel is resolved, registration is asserted, and the parameters are assembled.

## `routeName()`

```php
public static function routeName(string $page = 'index', Panel|string|null $panel = null): string
```

```php
UserResource::routeName();                       // 'panel.admin.resources.users.index'
UserResource::routeName('store', 'admin');       // 'panel.admin.resources.users.store'

route(UserResource::routeName('edit'), ['record' => 3], absolute: false);
```

The shape is always the same:

```
panel.{panelId}.resources.{slug}.{page}
```

`routeName()` does *not* assert registration — it will happily build a name for a panel that never registered the resource, using the class's own default slug. `url()` is the one that checks. Use `url()` unless you specifically need the name.

## The names a resource registers

Keys in `Resource::pages()` become route name suffixes. The four standard keys register more than one route each, because a write verb needs a name of its own.

| Page key | Verb and path | Route name suffix |
| --- | --- | --- |
| `index` | `GET /` | `index` |
| `create` | `GET create` | `create` |
| `create` | `POST create` | `store` |
| `create` | `POST create/step` | `validateCreateStep` |
| `view` | `GET {record}` | `view` |
| `edit` | `GET {record}/edit` | `edit` |
| `edit` | `PUT {record}/edit` | `update` |
| `edit` | `POST {record}/edit/step` | `validateEditStep` |
| any other key | `GET ResourcePage::routePath($key)` | the key itself |

So the create form's own submit target is a URL like any other:

```php
UserResource::url('store');                 // '/admin/users/create'   (POST)
UserResource::url('update', $user);         // '/admin/users/3/edit'   (PUT)
UserResource::url('validateEditStep', $user);
```

`CreateRecord` and `EditRecord` build exactly those, which is why a panel can change its path without a single form breaking.

A resource that declares integrations registers six more names under `resources.{slug}.integrations*`. See [Routing](../concepts/routing.md).

## Who owns the slug

The class proposes; the panel decides.

```php
public static function defaultSlug(): string;              // the class's own
public static function slug(): string;                     // in the current panel
public static function slugIn(?Panel $panel): string;      // in a named panel
public static function configurationIn(?Panel $panel): ?ResourceConfiguration;
```

```php
public static function defaultSlug(): string
{
    return static::$slug ?? Str::of(class_basename(static::getModel()))->plural()->kebab()->toString();
}
```

| Model | `$slug` | `defaultSlug()` |
| --- | --- | --- |
| `App\Models\User` | not set | `users` |
| `App\Models\BlogPost` | not set | `blog-posts` |
| `App\Models\Category` | not set | `categories` |
| `App\Models\User` | `'people'` | `people` |

```php
final class UserResource extends Resource
{
    protected static ?string $slug = 'team-members';   // /admin/team-members
}
```

`slug()` answers for the *current* panel and falls back to `defaultSlug()` when there is none — during a console command, say. Route registration asks `ResourceRegistry::slugFor()` instead, because at boot there is no current panel to ask:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->resources('admin')->slugFor(UserResource::class);   // 'users'
app(PanelManager::class)->resources('admin')->bySlug('users');                // UserResource::class
```

A panel keys its resources by slug. Two classes claiming one slug throw `PanelRegistrationException::duplicateResourceSlug()` at registration, and so does one class registered twice under two slugs in the same panel — a second registration would make `Resource::url()` ambiguous, with no way to say which was meant.

## One class, two panels

The same class can sit in more than one panel under a different slug, and each panel's URL is built from its own:

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People'),
]);
```

```php
UserResource::url(panel: 'admin');   // '/admin/users'
UserResource::url(panel: 'staff');   // '/staff/people'
```

Asking for a URL in a panel that does not register the resource throws. That is what makes panel isolation provable rather than accidental. See [Per-panel configuration](per-panel-configuration.md).

## Clusters move the path, not the name

A resource in a cluster is registered under `{cluster-slug}/{slug}`, but its route name stays `resources.{slug}.`:

```php
protected static ?string $cluster = SettingsCluster::class;
```

```php
UserResource::routeName();   // 'panel.admin.resources.users.index'  — unchanged
UserResource::url();         // '/admin/settings/users'              — moved
```

Every `Resource::url()` already written in the application keeps working, which is what makes adopting a cluster a non-breaking change. See [Clusters](../pages-navigation/clusters.md).

## Nested resources carry their parent

Every route of a nested resource sits beneath `{parentRecord}`, so a URL built without one would silently drop the scope the resource exists for. The request's own bound parent is the default:

```php
TaskResource::url();                        // '/admin/projects/7/tasks'
TaskResource::url('edit', $task);           // '/admin/projects/7/tasks/12/edit'
TaskResource::url(parent: $otherProject);   // '/admin/projects/8/tasks'
```

Outside a request there is nothing bound to fall back on, and `ParentRecord::require()` throws rather than producing a URL missing its scope. Pass `parent:` explicitly from a console command or a queued job. See [Nested resources](nested-resources.md).

## Singular resources drop the record

```php
if ($record !== null && ! static::isSingular()) {
    $parameters['record'] = $record instanceof Model ? $record->getKey() : $record;
}
```

A singular resource's routes carry no `{record}`, so the argument is ignored rather than rejected — which is what lets shared page code build URLs the same way for both kinds. See [Singular resources](singular-resources.md).

## Where URLs cross to Vue

Nothing on the frontend builds a panel URL. Every resource page ships the URLs its component needs as props, and the component uses them verbatim.

| Prop | Built from | Used for |
| --- | --- | --- |
| `resource.indexUrl` | `Resource::url()` | Table navigation — every sort, filter, and page change rewrites the query string against it |
| `submitUrl` | `Resource::url('store')` / `Resource::url('update', $record)` | The form's POST or PUT |
| `validateStepUrl` | `Resource::url('validateCreateStep')` / `Resource::url('validateEditStep', $record)` | The wizard's per-step check, `null` when the form has no wizard |
| `optionsUrl`, `uploadUrl`, `formStateUrl` | `PandaPanel\Support\FormEndpoints` | Searchable selects, uploads, live fields |
| `actionEndpoints` | `Panel::routeName('actions.*')` | The seven action endpoints |
| `page.breadcrumbs[].href` | `Resource::url()` and `Resource::url('view', $record)` | The trail |

```ts
// resources/js/panel/composables/useResource.ts
router.get(
    query === '' ? resource().indexUrl : `${resource().indexUrl}?${query}`,
    {},
    { preserveState: true, preserveScroll: true, replace: true },
);
```

That is the whole reason `url()` exists as a server-side call: a panel that moves from `/admin` to `/backoffice` changes one line in its provider and nothing in Vue.

## Search result URLs

Global search asks the resource where a hit leads:

```php
public static function globalSearchResultUrl(Model $record): string
{
    $pages = static::pages();

    if (array_key_exists('view', $pages)) {
        return static::url('view', $record);
    }

    return array_key_exists('edit', $pages)
        ? static::url('edit', $record)
        : static::url();
}
```

The view page when there is one, the edit page otherwise, and the index as a last resort. Each authorizes independently when it is opened, so a link is never the security boundary. Override the method for a resource whose hits should land somewhere else. See [Global search](global-search.md).

## When it refuses

Two failures are deliberate exceptions rather than a guess. Both are `PandaPanel\Exceptions\PanelRegistrationException`.

| Call | Condition | Message |
| --- | --- | --- |
| `UserResource::url()` | no current panel and none passed | `There is no current panel for this request. Resolve one through panel middleware or pass an explicit panel.` |
| `UserResource::url(panel: 'app')` | the panel does not register the resource | `The resource [App\...\UserResource] is not registered in the panel [app], so it has no URL there.` |

```php
protected static function assertRegisteredIn(Panel $panel): void
{
    if (! app(PanelManager::class)->resources($panel)->contains(static::class)) {
        throw PanelRegistrationException::resourceNotInPanel(static::class, $panel->getId());
    }
}
```

Silently picking a panel would make cross-panel links look correct while pointing somewhere else, and returning an empty string would put a broken link on a page nobody tests.

A third failure comes from Laravel rather than from the panel: asking for a page key the resource never declared is `RouteNotFoundException`, naming the route it could not find.

```php
UserResource::url('audit');   // Route [panel.admin.resources.users.audit] not defined.
```

## Notes

- **`url()` is relative.** Prefix it yourself if you need an absolute URL — for a mail template, say — or call `route(UserResource::routeName('view'), ['record' => $key])`.
- **`routeName()` does not check registration.** It answers for any panel, including one where the route does not exist. `url()` is the checked form.
- **The record parameter is always the primary key.** Custom route keys are not supported.
- **`store` and `update` are page keys too.** They are named separately because Laravel requires unique route names, and they are the URLs forms post to.
- **A resource with no `index` page has no `url()` default.** `ResourcePage::baseBreadcrumbs()` calls `Resource::url()` on every record page, so the key has to exist or the page fails while rendering its trail. The sidebar is gentler: `navigationItem()` returns `null` rather than building a link to a route that was never registered.
- **Two resources cannot claim one path shape in a panel.** The registrar erases parameter names before comparing, so `{record}` and `{parentRecord}` collide, and it throws at boot rather than shipping an unreachable page.
- **Route names are what Wayfinder generates from.** Keep them stable; the path is the part that is safe to move. See [Wayfinder](../frontend/wayfinder.md).

## See also

- [Creating resources](creating-resources.md)
- [List, create, view and edit pages](crud-pages.md)
- [Resource pages](resource-pages.md)
- [Singular resources](singular-resources.md)
- [Nested resources](nested-resources.md)
- [Per-panel configuration](per-panel-configuration.md)
- [Labels and navigation](labels-navigation.md)
- [Global search](global-search.md)
- [Routing](../concepts/routing.md)
- [Clusters](../pages-navigation/clusters.md)
- [Wayfinder](../frontend/wayfinder.md)
