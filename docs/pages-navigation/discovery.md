# Page Discovery

A panel finds its pages by scanning a directory rather than by keeping a list. You point `discoverPages()` at a folder, drop a class in it, and the page is routed, listed, and authorized on the next request. Explicit registration still works and merges with what was found, so nothing forces you to choose.

This page is about pages specifically. The same machinery discovers resources and widgets — see [Discovery](../concepts/discovery.md) for the shared rules.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

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

Any concrete class under `app/Panels/Admin/Pages` implementing `PandaPanel\Contracts\PageContract` is now a page of the Admin panel:

```php
namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class Settings extends Page {}
```

```bash
php artisan route:list --name=panel.admin.pages
```

## The method

```php
public function discoverPages(string ...$paths): self;

/** @return list<string> */
public function getPageDiscoveryPaths(): array;
```

Variadic, and calls accumulate rather than replace — so a plugin can add a directory without erasing the panel's:

```php
$panel
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverPages(base_path('modules/Billing/Pages'));
```

Paths are absolute filesystem paths. Nothing is created for you: a path that is not a directory is skipped silently, because a panel whose optional module is not installed should still boot.

## What qualifies

`PandaPanel\Discovery\PanelDiscoverer::pages()` walks every `.php` file under each path, recursively, and keeps a class only when all of these hold:

| Test | Why |
| --- | --- |
| The file resolves to a class through Composer's PSR-4 prefixes | Reading the file to find out what it declares would mean tokenizing arbitrary source |
| `class_exists()` | A file whose namespace does not match its location cannot autoload |
| Not abstract, not an interface | `PandaPanel\Pages\Page` itself lives in the package, and your own base page lives in the same tree |
| `implementsInterface(PageContract::class)` | The contract is what the registrar and the navigation builder call |

```php
use PandaPanel\Core\Panel;
use PandaPanel\Discovery\PanelDiscoverer;

$panel = Panel::make('admin')->discoverPages(app_path('Panels/Admin/Pages'));

app(PanelDiscoverer::class)->pages($panel);
// ['App\Panels\Admin\Pages\AccountsDashboard', 'App\Panels\Admin\Pages\Settings']
```

Results are `sort()`ed by class name, so two machines with different filesystem orderings produce the same list. Duplicates across paths collapse.

Extending `Page` is the ordinary way to satisfy the contract, but it is not required — `PageContract` declares four static methods, and a class implementing them directly is discovered just the same:

```php
namespace PandaPanel\Contracts;

interface PageContract
{
    public static function slug(): string;
    public static function routePath(): string;
    public static function canAccess(): bool;
    public static function navigationItem(PanelContract $panel): ?NavigationItem;
    public static function cluster(): ?string;
}
```

## Resolving a file to a class

`PandaPanel\Discovery\ClassResolver::forPath()` maps a path onto a class name using the PSR-4 prefixes Composer has already registered, longest namespace first so a nested prefix wins over its parent. A path outside every registered root returns `null` — nothing could autoload it anyway.

The practical consequence: **a page in a directory Composer does not map is never found**. If a page does not appear, check `composer.json`'s `autoload.psr-4` and re-run `composer dump-autoload` before suspecting the panel.

## Merging with explicit registration

```php
public function pages(array $pages): self;      // list<class-string>

/** @return list<class-string> */
public function getPages(): array;
```

`PanelManager` builds each panel's `PageRegistry` from the explicit list *and* the discovered list. A class in both appears once, because the registry is keyed by slug.

```php
$panel
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->pages([\App\Support\Reporting\ThroughputPage::class]);
```

Explicit registration is the answer for a page that lives outside the panel's own tree — in a shared package, in a module, in a test fixture.

`getPages()` also folds in the three built-in account pages unless the panel turned them off:

```php
$panel->settings(false);   // drops ProfileSettings, SecuritySettings, AppearanceSettings
```

They join in `getPages()` rather than in the manager, so discovery, caching and route registration treat them exactly like any other page. See [Settings pages](../panels/settings-pages.md).

## Extra dashboards

Dashboards named with `dashboards()` are pages in every sense but discovery — they are declared on the panel rather than found in a directory:

```php
use App\Panels\Admin\Pages\AccountsDashboard;
use PandaPanel\Pages\Dashboard;

$panel->dashboards([Dashboard::class, AccountsDashboard::class]);
```

The first is the panel root; the rest are registered as pages alongside the discovered ones, deduplicated by class — one that also lives under a discovered path arrives twice and is still one page. See [Dashboards](../panels/dashboards.md).

## The registry

```php
use PandaPanel\Core\PanelManager;

$pages = app(PanelManager::class)->pages('admin');

$pages->all();                 // list<class-string<PageContract>>, sorted
$pages->has('settings');       // bool
$pages->bySlug('settings');    // class-string|null
$pages->count();               // int
```

`PandaPanel\Core\PageRegistry` refuses two things at registration rather than at request time:

| Situation | Exception factory |
| --- | --- |
| Two pages claiming one slug | `PanelRegistrationException::duplicatePageSlug()` |
| A page whose slug a resource already holds | `PanelRegistrationException::slugCollidesWithResource()` |

Registering the same class twice is a no-op, not an error.

## Caching

```bash
php artisan panel:cache
php artisan panel:clear
```

`php artisan panel:cache` writes `bootstrap/cache/panels.php` through `PandaPanel\Cache\PanelManifest`, holding class names only:

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

With a manifest present, discovery does not run: no filesystem scan, no reflection, nothing per request. The file is written atomically, so a half-written manifest can never be loaded, and it lives beside the config and route caches under `bootstrapPath('cache')` so `optimize:clear` finds it.

Nothing user-dependent is ever cached — authorization results, navigation active state, badge values, page props. Those depend on the current user and URL, so caching them would serve one person's answers to everybody. See [Caching](../concepts/caching.md).

## Gotchas

- **A new page after `panel:cache` does not appear.** The manifest is the list; run `php artisan panel:clear` (or `optimize:clear`) in development.
- **Discovery does not require the page to be in the panel's namespace.** It requires the file to be under a declared path *and* resolvable through PSR-4. A page in `app/Panels/Admin/Pages` whose namespace says `App\Pages` will not resolve.
- **Two panels discovering the same directory both get the page.** Each panel keeps its own registry, so a shared directory is a legitimate way to publish one page into several panels — and each panel authorizes it separately.
- **An abstract base page in the same folder is skipped, quietly.** So is an enum, a trait, or a value object. That is what lets a page tree hold its own helpers.
- **Discovery order is not registration order.** Sorted class names decide the manifest; sidebar order comes from `$navigationSort` and the group order the panel declared. See [Navigation groups](../panels/navigation-groups.md).

## See also

- [Custom pages](custom-pages.md)
- [Page authorization](authorization.md)
- [Clusters](clusters.md)
- [Discovery](../concepts/discovery.md), [Caching](../concepts/caching.md)
- [Dashboards](../panels/dashboards.md), [Settings pages](../panels/settings-pages.md)
- [Defining a panel](../panels/defining-panels.md)
- [panel:cache](../cli/panel-cache.md), [panel:clear](../cli/panel-clear.md)
- [make:panel-page](../cli/make-panel-page.md)
