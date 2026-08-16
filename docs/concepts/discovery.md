# Discovery

Discovery finds the resource, page, and widget classes a panel owns by
scanning the directories the panel declared, resolving each file to a class
name through Composer's PSR-4 map, and keeping the ones that implement the
expected contract. It is why a panel provider names three directories instead
of listing every class. Reach for this page when a class you wrote is not
showing up.

## Turning it on

```php
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

Anything concrete under those paths that implements the matching contract is
now registered. Check what was found:

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::resources('admin')->all();   // list<class-string>
PandaPanel::pages('admin')->all();
PandaPanel::widgets('admin')->all();
```

```bash
php artisan panel:cache   # prints the counts it found
```

## The three methods

Each is variadic and each **accumulates** — calling it twice adds a path, it
does not replace one. That is what lets a module contribute to a panel without
a core change.

| Method | Signature | Contract required |
| --- | --- | --- |
| `discoverResources` | `discoverResources(string ...$paths): self` | `PandaPanel\Contracts\ResourceContract` |
| `discoverPages` | `discoverPages(string ...$paths): self` | `PandaPanel\Contracts\PageContract` |
| `discoverWidgets` | `discoverWidgets(string ...$paths): self` | `PandaPanel\Contracts\WidgetContract` |

```php
$panel
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverResources(base_path('modules/billing/src/Resources'));

$panel->getResourceDiscoveryPaths();   // both, in order, deduplicated
```

Readers: `getResourceDiscoveryPaths()`, `getPageDiscoveryPaths()`,
`getWidgetDiscoveryPaths()`, each `list<string>`.

Paths are absolute directories. They are scanned recursively, so a resource in
`Resources/Users/UserResource.php` is found without `Resources/Users` being
named.

## What qualifies

`PandaPanel\Discovery\PanelDiscoverer` applies three rules, in this order:

1. The file's extension is `.php`.
2. `ClassResolver::forPath()` produces a class name, and `class_exists()`
   confirms it.
3. Reflection says the class is not abstract, not an interface, and
   `implementsInterface($contract)`.

Anything else is skipped silently rather than failing the boot, because a
directory can legitimately hold a base class, a form object, a table object,
or an enum. In the example application the `Resources/Users` directory holds a
resource, four pages, a form, a table, an infolist, an exporter, and an
importer, and discovery returns one class:

```php
use PandaPanel\Discovery\PanelDiscoverer;

app(PanelDiscoverer::class)->resources(panel('admin'));
// [App\Panels\Admin\Resources\Users\UserResource::class]
```

| Method | Signature |
| --- | --- |
| `resources` | `resources(Panel $panel): list<class-string<ResourceContract>>` |
| `pages` | `pages(Panel $panel): list<class-string<PageContract>>` |
| `widgets` | `widgets(Panel $panel): list<class-string<WidgetContract>>` |

Results are deduplicated and sorted by class name, so two machines with
different filesystem orderings produce the same list — which is what makes the
cached manifest byte-identical across runs.

A path that is not a directory contributes nothing. That is not an error: a
panel may declare a directory a module has not created yet.

## Resolving a file to a class

`PandaPanel\Discovery\ClassResolver` turns a path into the class it declares
using Composer's registered PSR-4 prefixes:

```php
use PandaPanel\Discovery\ClassResolver;

ClassResolver::forPath(app_path('Panels/Admin/Resources/Users/UserResource.php'));
// 'App\Panels\Admin\Resources\Users\UserResource'

ClassResolver::forPath('/tmp/outside-every-psr4-root/Thing.php');
// null
```

```php
public static function forPath(string $path): ?string
```

Reading the file to find out what class it declares would mean executing or
tokenizing arbitrary source during discovery. The autoloader already knows the
answer, so it is asked instead. Two details follow from that:

- Prefixes are sorted longest-namespace-first, so a nested PSR-4 root wins
  over its parent.
- `null` means the path is outside every registered PSR-4 root — which means
  nothing could autoload the class anyway.

Prefixes are read once per process and memoized.

## Merging with explicit registration

Explicit registration still works and merges with discovery. A class named by
both appears once, because the registries are keyed by slug and id.

```php
use App\Panels\Admin\Resources\Users\UserResource;

$panel
    ->resources([UserResource::class])
    ->discoverResources(app_path('Panels/Admin/Resources'));

PandaPanel::resources($panel)->all();
// [App\Panels\Admin\Resources\Users\UserResource::class] — once
```

This is how a panel pulls in a class that lives outside its own tree: a
resource shipped by a package, or one shared between two panels.

`PandaPanel\Core\PanelManager::buildRegistries()` does the merge, and it
registers resource *configurations* first, so a class configured for this
panel is never also registered bare and claiming its default slug.

## What discovery does not do

- **It does not find panels.** Panel providers are listed in
  `config/panda-panel.php`, because the list is where every panel in the
  application is visible at once and adding a panel should be a deliberate edit
  rather than a filesystem side effect. The order in it decides nothing: panels
  are walked by id.
- **It does not find relation managers.** A resource names those in
  `relationManagers()`, and a manager not named there cannot be addressed by a
  request that names it.
- **It does not find Vue components.** Those resolve through build-time
  `import.meta.glob` registries — see
  [Component Registries](component-registries.md).
- **It does not register routes.** `PanelRouteRegistrar` reads the registries
  afterwards.

## When it runs

Once per panel, during `PandaPanel::register()`, which happens in the service
provider's `boot()`. `PandaPanel\Cache\PanelManifest::for()` is the seam:

```php
public function for(Panel $panel): array
{
    $cached = $this->load()[$panel->getId()] ?? null;

    if ($cached !== null) {
        return $cached;
    }

    return [
        'resources' => $this->discoverer->resources($panel),
        'pages' => $this->discoverer->pages($panel),
        'widgets' => $this->discoverer->widgets($panel),
    ];
}
```

With `bootstrap/cache/panels.php` present, discovery does not run at all: no
filesystem scan, no reflection, nothing per request. See
[Caching](caching.md).

## Gotchas

- **A cached manifest freezes the list.** A class added after
  `php artisan panel:cache` is not in the panel at all — no route, no sidebar
  entry, no error. In development the framework logs a warning when the
  fingerprint no longer matches; the fix is `php artisan panel:clear`.
- **A class outside every PSR-4 root is invisible.** `ClassResolver` returns
  null and the file is skipped without a message. If a resource in a package
  is not found, check that its namespace is registered in `composer.json` and
  that `composer dump-autoload` has run.
- **Abstract base classes are skipped, and so is the framework's own.**
  `PandaPanel\Resources\Resource` implements `ResourceContract` and would be
  found if the concrete check were missing; it is not.
- **A trait or interface in the same directory is skipped**, but a *concrete*
  class that happens to implement the contract is not. If you keep a shared
  concrete base in the resources directory, make it `abstract`.
- **Discovery is per panel, from that panel's own paths only.** Two panels
  pointed at the same directory both get the classes; a panel pointed at
  another panel's directory silently adopts them, which is occasionally what
  you want and usually a typo.
- **Slug collisions fail the boot.** Two resources resolving to one slug throw
  `PanelRegistrationException::duplicateResourceSlug()`, and a page whose slug
  a resource already claimed throws `slugCollidesWithResource()`. Discovery
  makes those easier to cause, so they are refused loudly.
- **A resource missing from the sidebar is more often a policy than
  discovery.** `Gate::allows()` denies when no policy exists, which is
  indistinguishable from one that said no. In development
  `PandaPanel\Support\MissingPolicyNotice` logs the reason once per model; in
  any environment, `strictAuthorization()` turns it into an exception.

## See also

- [Caching](caching.md)
- [Panel Providers](panel-providers.md)
- [Panels](panels.md)
- [Routing](routing.md)
- [Authorization](authorization.md)
- [Directory Convention](../resources/directory-convention.md)
- [Page Discovery](../pages-navigation/discovery.md)
- [panel:cache](../cli/panel-cache.md)
