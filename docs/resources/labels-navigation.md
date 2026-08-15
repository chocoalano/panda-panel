# Labels and Navigation

Every resource derives a singular label, a plural label, and a sidebar entry from its model name. This page covers the declarations that change any of them, and how the sidebar entry is built — reach for it when a resource is called the wrong thing, sits in the wrong group, or should not be in the sidebar at all.

## Nothing declared

```php
use App\Models\BlogPost;
use PandaPanel\Resources\Resource;

final class BlogPostResource extends Resource
{
    protected static string $model = BlogPost::class;

    // ...
}
```

| Question | Answer | Derived from |
| --- | --- | --- |
| `BlogPostResource::label()` | `Blog Post` | `Str::headline(class_basename($model))` |
| `BlogPostResource::pluralLabel()` | `Blog Posts` | `Str::plural()` of the label |
| `BlogPostResource::slug()` | `blog-posts` | plural, kebab-cased, of the model basename |
| Sidebar entry label | `Blog Posts` | the plural label |
| Sidebar entry icon | none | — |
| Sidebar group | ungrouped | — |

## Labels

```php
protected static ?string $label = 'Article';

protected static ?string $pluralLabel = 'Articles';
```

The label appears in the create page title (`New Article`), the create button, the view page subheading, and the success notifications (`Article created.`). The plural label is the list page heading, the breadcrumb, and the sidebar entry when no navigation label is declared.

Four static methods read them:

```php
public static function defaultLabel(): string;        // the class's own, before any panel configures it
public static function defaultPluralLabel(): string;
public static function label(): string;               // as the current panel configured it
public static function pluralLabel(): string;
```

The `default*` pair is what the class says; the pair without the prefix asks the current panel first. That matters when the same class is registered in two panels — see [Per-panel configuration](per-panel-configuration.md).

`$pluralLabel` is worth declaring whenever `Str::plural()` gets it wrong, which is most irregular nouns and every acronym.

## The sidebar entry

```php
use BackedEnum;

protected static ?string $navigationLabel = 'Articles';

protected static ?string $navigationIcon = 'newspaper';

protected static ?string $activeNavigationIcon = 'newspaper-solid';

protected static string|BackedEnum|null $navigationGroup = 'Content';

protected static int $navigationSort = 20;

protected static bool $shouldRegisterNavigation = true;
```

| Property | Type | Default | Effect |
| --- | --- | --- | --- |
| `$navigationLabel` | `?string` | the plural label | The text of the entry |
| `$navigationIcon` | `?string` | `null` | An icon registry key |
| `$activeNavigationIcon` | `?string` | `$navigationIcon` | A second icon worn only while the entry is active |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | Which sidebar group it sits in |
| `$navigationSort` | `int` | `0` | Order within the group; lower first |
| `$shouldRegisterNavigation` | `bool` | `true` | Whether the entry exists at all |

Icons are **registry keys, never component paths**. The registry is a build-time allowlist: an unknown name resolves to nothing rather than to an error. After adding an icon name, rebuild the registry so the bundle contains it:

```bash
php artisan panel:icons          # rewrite the registry from the source
php artisan panel:icons --check  # fail if it is out of date, for CI
```

Two accessors are public, because the navigation builder and global search both ask:

```php
public static function navigationIcon(): ?string;
public static function activeNavigationIcon(): ?string;   // falls back to navigationIcon()
```

## Groups

A group is named by a string or by a backed enum. An enum is worth reaching for the moment more than one class names the same group: a mistyped string is a second group that looks like the first and silently splits the sidebar in two, while a mistyped enum case does not compile.

```php
use App\Enums\NavigationGroup;

protected static string|BackedEnum|null $navigationGroup = NavigationGroup::Content;
```

Order is declared on the panel; groups the panel does not name are appended alphabetically:

```php
$panel->navigationGroups([
    'Content',
    NavigationGroup::System,
    'Access' => 'System',    // nests Access under System
]);
```

See [Navigation groups](../panels/navigation-groups.md).

## Opting out of the sidebar

```php
protected static bool $shouldRegisterNavigation = false;
```

The resource keeps its routes and its URLs; only the entry disappears. That is the right declaration for a resource reached exclusively from another page.

Three other cases produce no entry, with no declaration involved:

- **A nested resource.** Its pages only exist beneath a parent record, and the sidebar has no parent in hand — there is no "all posts" to link to.
- **A resource with no `index` page.** Building an entry for it would produce a link to a route that was never registered, which fails while rendering the sidebar and so takes down every page in the panel rather than just that one.
- **A resource the user may not view.** The builder filters by `canViewAny()` before anything else, so an unauthorized entry never even reaches badge evaluation.

Hiding is a convenience, never the security control: routes and actions authorize independently. See [Authorization](authorization.md).

## `navigationItem()`

```php
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

public static function navigationItem(PanelContract $panel): ?NavigationItem
```

The method the navigation builder calls. It returns `null` in the three cases above, and otherwise a `NavigationItem` whose every field falls back to the class's own — so a panel states only what it wants to differ.

A resource's stock entry carries **no badge**. There is no `$navigationBadge` property; override the method to add one:

```php
use App\Models\Article;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

public static function navigationItem(PanelContract $panel): ?NavigationItem
{
    $item = parent::navigationItem($panel);

    if ($item === null) {
        return null;
    }

    return NavigationItem::make(
        label: $item->label,
        href: $item->href,
        icon: $item->icon,
        badge: static fn (): int => Article::query()->whereNull('published_at')->count(),
        sort: $item->sort,
        group: $item->group,
        activeIcon: $item->activeIcon,
    );
}
```

A closure badge is evaluated on the server, for authorized items only, and only the scalar result crosses to Vue.

## Clusters

```php
use App\Panels\Admin\Clusters\ContentCluster;

protected static ?string $cluster = ContentCluster::class;
```

Membership is declared by the member rather than listed on the cluster, so a class carries its own place in the panel and nothing has to be kept in two lists that can disagree. A clustered resource is listed *under* its cluster rather than beside it: the cluster is one sidebar entry that expands to its members and points at the first member the user may actually see.

The path gains the cluster's prefix; the route name does not. `panel.admin.resources.articles.index` still names the same route, so every `Resource::url()` already written keeps working. See [Clusters](../pages-navigation/clusters.md).

## Naming a single record

Breadcrumbs, page headings, sub-navigation, and search results ask the resource what one record is called:

```php
protected static ?string $recordTitleAttribute = 'title';
```

```php
public static function recordTitle(Model $record): string
```

The attribute defaults to `name`, and a non-scalar value falls back to the primary key. See [Model binding](model-binding.md).

## Record sub-navigation

```php
use PandaPanel\Enums\SubNavigationPosition;

protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;
```

`Top` reads as tabs; `Start` and `End` are a rail beside the content. `null` — the default — takes the panel's own position, so a resource states one only when it differs from the rest.

```php
public static function subNavigationPosition(): ?SubNavigationPosition
```

The links themselves are built from the resource's `pages()` map: the view and edit pages when they are declared and authorized for this record, plus any `ManageRelatedRecords` page. One link is not navigation, so a record with only one reachable page gets no bar at all. See [Sub-navigation](../pages-navigation/sub-navigation.md).

## Per-panel overrides

Every navigation field can be restated for one panel without touching the class:

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(ArticleResource::class)
        ->label('Story')
        ->pluralLabel('Stories')
        ->navigationLabel('Newsroom')
        ->navigationIcon('newspaper')
        ->navigationGroup('Editorial')
        ->navigationSort(5)
        ->registerNavigation(false),
]);
```

See [Per-panel configuration](per-panel-configuration.md).

## Notes

- **The navigation label falls back to the plural label, not to the singular one.** Declaring `$label` alone leaves the sidebar reading the plural.
- **`$navigationSort` orders within a group, not across the sidebar.** Group order is the panel's declaration.
- **An unregistered icon name renders nothing and reports nothing.** The registry is an allowlist by design; run `panel:icons` after adding one.
- **`label()` and `pluralLabel()` consult the current panel.** Called from a console command, where there is no current panel, they answer the class's own defaults.
- **Navigation is rebuilt per request.** Authorization results, badges, and active state depend on the user and the URL, so none of it is cached alongside the panel manifest.

## See also

- [Creating resources](creating-resources.md)
- [URLs and route names](urls-routes.md)
- [Per-panel configuration](per-panel-configuration.md)
- [Nested resources](nested-resources.md)
- [Resource authorization](authorization.md)
- [Navigation groups](../panels/navigation-groups.md)
- [Clusters](../pages-navigation/clusters.md)
- [Sub-navigation](../pages-navigation/sub-navigation.md)
- [Icons](../troubleshooting/icons.md)
