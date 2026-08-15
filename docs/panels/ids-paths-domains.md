# Panel IDs, Paths, and Domains

Three values decide where a panel lives and how everything else addresses it: the **id** names it in code, the **path** is its URL prefix, and the **domain** narrows it to one host. Route names, the manifest, the panel switcher and every `Resource::url()` are built from them, so it is worth knowing exactly what each one defaults to.

## Setting all three

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class BackOfficePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('back-office')             // usually unnecessary: derived from the class name
            ->path('back-office')           // /back-office/...
            ->domain('admin.example.com')   // this host only
            ->name('Back Office');          // the human label
    }
}
```

## The id

```php
public function id(string $id): self
public function getId(): string          // throws PanelRegistrationException when never set
```

The id is seeded from the provider before `panel()` runs, so you rarely set it. `PanelProvider::panelId()` takes the class basename, drops the `PanelProvider` suffix and kebab-cases the rest:

| Provider class | Id |
| --- | --- |
| `AdminPanelProvider` | `admin` |
| `AppPanelProvider` | `app` |
| `BackOfficePanelProvider` | `back-office` |

Override the derivation on the provider when the class name is not the id you want:

```php
final class AdminPanelProvider extends PanelProvider
{
    public function panelId(): string
    {
        return 'backend';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel->path('backend');
    }
}
```

`Panel::make()` without an argument leaves the id unset, and `getId()` then throws — a panel with no id cannot name its routes.

```php
Panel::make('admin');   // id set
Panel::make();          // getId() throws PanelRegistrationException
```

The id is the key everywhere: `panel('admin')`, `PanelManager::get('admin')`, the manifest section in `bootstrap/cache/panels.php`, the route name prefix, and the frontend's `panel.id`. Changing it changes all of them at once.

## The path

```php
public function path(string $path): self   // leading and trailing slashes are trimmed
public function getPath(): string          // falls back to the id
```

```php
$panel->path('admin');    // /admin
$panel->path('/admin/');  // /admin — the same thing
Panel::make('reports')->getPath();   // 'reports' — the id, because no path was set
```

The path becomes the route group prefix, so every page in the panel sits under it, and the panel's landing page is the prefix itself:

```php
route('panel.admin.dashboard', absolute: false);   // '/admin'
```

A path may be nested (`->path('admin/reports')`). Resolution takes the longest path first, so a panel at `admin/reports` wins over one at `admin` for a request to `/admin/reports/x`.

## The domain

```php
public function domain(?string $domain): self
public function getDomain(): ?string       // null by default
```

With a domain set, the panel's route group carries Laravel's `domain` attribute and the panel only matches requests to that host:

```php
$panel->domain('admin.example.com')->path('/');
```

Two panels may share a path when their domains differ. They may not share a path on the same domain:

```php
use PandaPanel\Core\PanelRegistry;

$registry = new PanelRegistry;
$registry->register(Panel::make('first')->path('admin')->domain('a.test'));
$registry->register(Panel::make('second')->path('admin')->domain('b.test'));   // fine

$registry->register(Panel::make('third')->path('admin'));
$registry->register(Panel::make('fourth')->path('admin'));                     // throws
```

A domain string is passed to the router unchanged, so route parameters in it (`{tenant}.example.com`) work exactly as they do in `Route::domain()`.

## The name

```php
public function name(string $name): self
public function getName(): string          // falls back to Str::headline($id)
```

The name is what the shell and the panel switcher show beside the brand. It is not used for routing.

```php
Panel::make('back-office')->getName();                     // 'Back Office'
Panel::make('back-office')->name('Back Office (EU)')->getName();   // 'Back Office (EU)'
```

## Route names

Every route a panel registers is prefixed with `panel.{id}.`.

```php
public function getRouteNamePrefix(): string   // "panel.admin."
public function routeName(string $name): string
```

```php
panel('admin')->routeName('dashboard');    // 'panel.admin.dashboard'
route(panel('admin')->routeName('dashboard'), absolute: false);   // '/admin'
```

The names a panel registers, with `panel.{id}.` omitted:

| Name | Path | Purpose |
| --- | --- | --- |
| `dashboard` | `/` | The panel's landing page. |
| `search` | `search` | Global search JSON endpoint. |
| `options` | `options` | Searchable select options. |
| `uploads` | `uploads` | Pre-submit file uploads. |
| `form-state` | `form-state` | Live-field re-render. |
| `export-file` | `exports/{file}` | Download a finished export. |
| `import-file` | `imports/{file}` | Download an import failure report. |
| `notifications.index`, `notifications.read`, `notifications.clear` | `notifications/*` | Notification centre. |
| `auth.two-factor.challenge`, `.send`, `.verify`, `.enable`, `.disable` | `two-factor/*` | The emailed-code second factor. |
| `actions.record`, `.bulk`, `.reorder`, `.cell`, `.table`, `.infolist`, `.form`, `.submit` | `actions/*` | The action endpoints. |
| `relations.form`, `.save`, `.action`, `.bulk` | `relations/*` | Relation manager endpoints. |
| `pages.{page-slug}` | the page's `routePath()` | One standalone page. |
| `resources.{slug}.index`, `.create`, `.store`, `.view`, `.edit`, `.update` | under the resource slug | Resource pages. |
| `auth.login`, `auth.register`, `auth.password.request`, `auth.password.reset`, `auth.verification.notice` | `login`, `register`, … | Only for a panel that called `login()` and friends. |

Route *names* never carry a cluster prefix, only paths do — so adopting a cluster moves URLs without breaking any `Resource::url()` already written.

## How a request finds its panel

Two mechanisms, and they answer different questions.

Inside a panel route group the id is passed to the middleware explicitly (`ResolvePanel:admin`), so panel resolution never depends on matching the URL. Outside one — the guest redirect, the home redirect — `PanelManager::resolveFromRequest()` matches by host and path prefix:

```php
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->resolveFromRequest(request());   // ?Panel
```

It sorts candidates by path length descending, skips any panel whose domain does not match the request host, and returns the first whose path is the request path or a prefix of it. That ordering is why `/admin/reports/x` resolves to a panel at `admin/reports` rather than to one at `admin`.

## Notes

- `getPath()` returning the id is a fallback, not an alias. Setting the id after the path does not change the path.
- A panel mounted at `/dashboard` collides with the starter kit's own dashboard route. The home redirect ignores any path a panel is itself mounted on, so this does not loop — see [Home Redirect](../configuration/home-redirect.md).
- Renaming a path changes every URL the panel generates but no route names, so bookmarks break and code does not. Renaming an id changes the route names and therefore breaks code that referenced them by string.
- `panel('unknown')` throws rather than returning null. An unknown id is a developer error, and a null here would surface much later as a missing route.

## See also

- [Defining a Panel](defining-panels.md)
- [Multi-Panel Applications](multi-panel.md)
- [Middleware and Guards](middleware.md)
- [Panel API Reference](api.md)
- [Routing](../concepts/routing.md)
- [URLs and Route Names](../resources/urls-routes.md)
- [Panel routes return 404](../troubleshooting/panel-routes-404.md)
