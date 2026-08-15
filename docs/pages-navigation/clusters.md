# Clusters

A cluster is a set of resources and pages that belong together, under one URL prefix and one piece of navigation. Everything in it lives under the cluster's slug — `/admin/ops/tasks` — and every page in it renders the cluster's own sub-navigation, so moving between siblings never means going back to the sidebar. You reach for one when a panel has grown a group of screens that are really one area: settings, operations, a reporting suite.

Route *names* are untouched by the prefix. A resource stays `panel.admin.resources.roles.index`, so every `Resource::url()` already written keeps working and only the path it produces moves. That is what makes adopting a cluster a non-breaking change.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Clusters;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Enums\ClusterPosition;

final class OperationsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'settings';

    protected static ClusterPosition $position = ClusterPosition::Header;
}
```

Membership is declared by the member, never listed on the cluster:

```php
use App\Panels\Admin\Clusters\OperationsCluster;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Resources\Resource;

final class TaskResource extends Resource
{
    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = OperationsCluster::class;
}
```

```php
use App\Panels\Admin\Clusters\OperationsCluster;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Pages\Page;

final class Throughput extends Page
{
    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = OperationsCluster::class;
}
```

The sidebar now shows one `Operations` entry that expands to `Tasks` and `Throughput`. Both live under `/admin/operations/…`, and both render a bar under the page header linking to each other.

There is no generator for clusters and no registration call: a cluster is found through the members that name it.

## Why membership points upward

A class carries its own place in the panel. A cluster that kept a list of its members would be a second list that can disagree with the members' own declarations, and the two would drift the first time somebody moved a class. `ClusterNavigation` builds every cluster's contents by asking the panel's registries which classes named it.

## The `Cluster` class

All properties are `protected static`; all accessors are `public static`.

| Property | Type | Default | Accessor |
| --- | --- | --- | --- |
| `$title` | `?string` | `Str::headline(class basename minus "Cluster")` | `title()` |
| `$slug` | `?string` | `Str::kebab(class basename minus "Cluster")` | `slug()` |
| `$navigationIcon` | `?string` | `null` | `navigationIcon()` |
| `$activeNavigationIcon` | `?string` | `$navigationIcon` | `activeNavigationIcon()` |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | `navigationGroup()` |
| `$navigationSort` | `int` | `0` | `navigationSort()` |
| `$shouldRegisterNavigation` | `bool` | `true` | `shouldRegisterNavigation()` |
| `$position` | `ClusterPosition` | `ClusterPosition::Header` | `position()` |

```php
use PandaPanel\Clusters\Cluster;
use PandaPanel\Enums\ClusterPosition;

final class ReportsCluster extends Cluster {}

ReportsCluster::title();                 // 'Reports'
ReportsCluster::slug();                  // 'reports'
ReportsCluster::position();              // ClusterPosition::Header
ReportsCluster::navigationIcon();        // null
ReportsCluster::activeNavigationIcon();  // null
ReportsCluster::shouldRegisterNavigation(); // true
```

The `Cluster` suffix is stripped from both defaults, so `OperationsCluster` is titled `Operations` and slugged `operations`. Declare `$slug` for a shorter path:

```php
final class OperationsCluster extends Cluster
{
    protected static ?string $title = 'Operations';

    protected static ?string $slug = 'ops';

    protected static ?string $navigationIcon = 'settings';

    protected static ?string $activeNavigationIcon = 'shield';

    protected static string|BackedEnum|null $navigationGroup = 'System';

    protected static int $navigationSort = 90;
}
```

### `canAccess()`

```php
public static function canAccess(): bool;   // default true
```

Independent of its members: a cluster the user may not enter hides the whole set and produces no sub-navigation bar, and every member still authorizes for itself. Hiding is never the control.

```php
public static function canAccess(): bool
{
    return auth()->user()?->can('viewOperations') === true;
}
```

### `navigationItem()`

```php
public static function navigationItem(Panel $panel): ?NavigationItem;   // default null
```

Null by default, and that is the useful case. A cluster is a container, so clicking it should land on its first *visible* member — which only the navigation builder knows, because it is the one filtering by authorization. Returning `null` lets it decide.

Override it when the cluster has a landing page of its own:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Support\NavigationItem;

public static function navigationItem(Panel $panel): ?NavigationItem
{
    return NavigationItem::make(
        label: static::title(),
        href: OperationsOverview::url($panel),
        icon: static::navigationIcon(),
        sort: static::navigationSort(),
        group: static::navigationGroup(),
        activeIcon: static::activeNavigationIcon(),
    );
}
```

An override is responsible for its own children: the builder uses the returned item as-is, so an item built without `children:` expands to nothing.

## `ClusterPosition`

```php
namespace PandaPanel\Enums;

enum ClusterPosition: string
{
    case Header = 'header';      // a bar under the header, above the page content
    case RightBar = 'right-bar'; // a column beside the content, on the right
    case Sidebar = 'sidebar';    // only in the sidebar, under the cluster's own item
}
```

Closed, because each case maps to a place in the shell the build already knows about. Where a set of pages is listed is a layout decision the panel makes once, not something each page has an opinion about.

`Sidebar` renders no bar on the page at all — the cluster's members are still expanded under its sidebar entry, which for a two-item cluster is often enough.

## Routing

```php
public static function routePath(): string;   // on Page
```

A page's `routePath()` prefixes its slug with the cluster's:

```php
$cluster === null ? static::slug() : $cluster::slug().'/'.static::slug();
```

Resources are prefixed by the route registrar in the same way — `'prefix' => $cluster::slug().'/'.$slug` — while the route name stays `resources.{slug}.`.

```php
ClusteredReportPage::routePath();   // 'ops/throughput'
ClusteredReportPage::url($panel);   // '/cluster-host/ops/throughput'
ClusteredTaskResource::url(panel: $panel);   // '/cluster-host/ops/clustered-tasks'

Route::has('panel.cluster-host.resources.clustered-tasks.index');   // true
```

Moving a class into a cluster therefore changes URLs and nothing else. Bookmarks break; code does not.

## `ClusterNavigation`

```php
namespace PandaPanel\Support;

/** @return array<class-string<Cluster>, list<NavigationItem>> */
public static function all(Panel $panel): array;

/**
 * @param  class-string<Cluster>  $cluster
 * @return array{label: string, icon: string|null, position: string, items: list<array<string, mixed>>}|null
 */
public static function for(Panel $panel, string $cluster, string $currentPath): ?array;
```

`all()` walks the panel's resource and page registries, keeps everything that named a cluster **and** passed its own authorization check (`canViewAny()` for a resource, `canAccess()` for a page), and sorts each cluster's items by `[sort, label]`.

```php
use PandaPanel\Support\ClusterNavigation;

$items = ClusterNavigation::all($panel)[OperationsCluster::class] ?? [];

$items[0]->href;   // '/cluster-host/ops/clustered-tasks'
```

`for()` is what a page ships. It returns `null` in two cases — the cluster refuses `canAccess()`, or nothing in it is visible to this user — so a bar with no links in it is never rendered.

```php
$cluster = ClusterNavigation::for($panel, OperationsCluster::class, 'cluster-host/ops/throughput');

$cluster['label'];      // 'Operations'
$cluster['position'];   // 'header'
array_column($cluster['items'], 'label');   // ['Tasks', 'Throughput']
collect($cluster['items'])->firstWhere('active', true)['label'];   // 'Throughput'
```

Active state is a prefix match, so `/admin/ops/tasks/3/edit` still marks `Tasks` — the same rule the sidebar follows.

## In the sidebar

`PandaPanel\Support\NavigationBuilder` builds clusters first, because what follows has to know which classes were already accounted for:

- a cluster is **one** item that expands to its members;
- its members are not also listed beside it;
- the item's `href` is `$children[0]->href` — the first member the user may see;
- a cluster whose `canAccess()` is false, or whose `shouldRegisterNavigation()` is false, produces no item;
- a cluster with no visible members produces nothing.

```php
it('lists a cluster once, with its members as children', function (): void {
    // One item that expands, not one item per member beside it.
    expect(array_column($cluster['children'], 'label'))->toBe(['Tasks', 'Throughput'])
        ->and($items->firstWhere('label', 'Tasks'))->toBeNull();
});
```

The cluster's own `$navigationGroup` places that single item, so a cluster can sit inside a sidebar group like anything else. See [Navigation groups](../panels/navigation-groups.md).

## On the page

Both a standalone page and a resource page put the bar in their metadata:

```php
// Page::metadata()
'cluster' => static::$cluster === null
    ? null
    : ClusterNavigation::for($this->panel(), static::$cluster, request()->path()),

// ResourcePage::clusterNavigation()
protected function clusterNavigation(): ?array;
```

```ts
export type ClusterPosition = 'header' | 'right-bar' | 'sidebar';

export interface ClusterNavigation {
    label: string;
    icon: string | null;
    position: ClusterPosition;
    items: NavigationItem[];
}
```

`SidebarPanelLayout` and `HeaderPanelLayout` read `page.cluster` and draw `PanelClusterBar.vue`:

| `position` | Rendering |
| --- | --- |
| `header` | `orientation="row"` above the page content, with a bottom border |
| `right-bar` | `orientation="column"` in a 14rem column to the right of the content |
| `sidebar` | nothing on the page |

`normalizePageMetadata()` narrows the value as it crosses: a cluster with no items becomes `null`, and an unknown position falls back to `header` rather than reaching a branch that does not exist.

## Gotchas

- **A nested resource loses the cluster prefix in its path.** The route registrar builds a nested resource's prefix as `{parentSlug}/{parent}/{slug}`, which replaces the cluster prefix. The resource is still listed under the cluster and still gets the cluster bar; only the URL sits under its parent.
- **The cluster bar on a page is not prefetched and is never marked `fullPage`.** `fullPageUrls()` is applied by `NavigationBuilder`, which builds the sidebar — the page's own cluster bar comes from `ClusterNavigation::for()` and always renders a client-side `<Link>`. See [Full page URLs](urls.md).
- **A cluster is not a route.** There is no `/admin/ops` unless a member claims that path. The sidebar entry points at the first visible member.
- **`Cluster::canAccess()` does not authorize members.** It governs the sidebar entry and the bar. A member that must be closed needs its own `canAccess()` or policy.
- **Two clusters may not share a slug**, because their members' paths would collide. Nothing checks this at boot — the collision surfaces as one route shadowing another.
- **Slug and title strip a trailing `Cluster` only.** `OpsCluster` is `ops`; `ClusterOps` is `cluster-ops`.
- **Members are sorted inside the cluster by `[sort, label]`**, which is the same rule the sidebar uses inside a group — `$navigationSort` on the member, not on the cluster.

## See also

- [Custom pages](custom-pages.md), [Page discovery](discovery.md), [Page authorization](authorization.md)
- [Sub navigation](sub-navigation.md), [Breadcrumbs](breadcrumbs.md)
- [Full page URLs](urls.md), [Prefetching](prefetching.md)
- [Navigation groups](../panels/navigation-groups.md), [Sidebar and header layouts](../panels/layouts.md)
- [Labels and navigation](../resources/labels-navigation.md)
- [Nested resources](../resources/nested-resources.md)
- [Routing](../concepts/routing.md)
