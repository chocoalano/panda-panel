# Navigation Groups

Sidebar headings, and the order they appear in. A resource or page names the group it belongs to; the panel declares which groups exist and in what order. Nothing is hardcoded: navigation is built per request from the panel's registries, filtered by what the current user may see.

## Declaring the order

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
            ->navigationGroups([
                'User Management',
                'System',
            ])
            ->discoverResources(app_path('Panels/Admin/Resources'));
    }
}
```

```php
use BackedEnum;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static string|BackedEnum|null $navigationGroup = 'User Management';

    protected static ?string $navigationIcon = 'users';

    protected static int $navigationSort = 10;
}
```

The sidebar now shows `User Management` before `System`, with Users inside the first.

## The method

```php
/** @param  array<array-key, string|BackedEnum|UnitEnum>  $groups */
public function navigationGroups(array $groups): self

/** @return list<string> */
public function getNavigationGroups(): array

/** @return array<string, string> child label => parent label */
public function getNavigationGroupParents(): array
```

Calls accumulate and duplicates collapse, so a plugin can contribute a group without displacing the panel's:

```php
$panel
    ->navigationGroups(['User Management'])
    ->navigationGroups(['System', 'User Management']);

$panel->getNavigationGroups();   // ['User Management', 'System']
```

## Naming a group with an enum

A group may be named by a string, a backed enum, or a pure enum:

```php
enum NavigationGroup: string
{
    case Content = 'Content';
    case System = 'System';
}
```

```php
$panel->navigationGroups([
    NavigationGroup::Content,
    NavigationGroup::System,
]);
```

```php
protected static string|BackedEnum|null $navigationGroup = NavigationGroup::Content;
```

`PandaPanel\Support\NavigationGroupName::resolve()` reduces all three to a label: a string stays as it is, a backed enum contributes its `value`, and a pure enum contributes its case `name`. Everything is a string by the time it reaches the registry, because groups are matched by label.

An enum is worth reaching for once more than one class names the same group. A mistyped string is a second group that looks like the first and silently splits the sidebar in two; a mistyped enum case does not compile.

## Nesting

A string key nests the group it names under the group it points at:

```php
$panel->navigationGroups([
    'Content',
    'System',
    'Access' => 'System',   // Access is drawn indented under System
]);
```

Read the pair as *child => parent*: the key is the group being placed, the value is where it goes. Both labels also go through `NavigationGroupName::resolve()`, so enums work on either side.

A nested group is still one group with one set of items — the sidebar draws it indented under its parent rather than as a second heading at the top level. A group whose declared parent is not on screen, because everything in it was refused, sits at the top level rather than disappearing with it.

## How the order is computed

`PandaPanel\Core\NavigationRegistry` assigns each group a sort weight:

| Bucket | Weight | Ordering |
| --- | --- | --- |
| Ungrouped items (no label) | `-1` | Always first. |
| Groups declared by the panel | `0 + declaration index` | The order they were declared in. |
| Groups nobody declared | `1000 + alphabetical index` | Alphabetically, after every declared group. |

Undeclared groups sort alphabetically rather than by discovery order so the sidebar cannot reshuffle itself because a file was renamed.

Inside a group, items sort by `[sort, label]` — the numeric `$navigationSort` first, the label as the tiebreaker. That is why two items sharing a sort of `0` appear alphabetically.

```php
use PandaPanel\Core\NavigationRegistry;

$registry = new NavigationRegistry(['User Management', 'System']);

$registry->declaredGroups();          // ['User Management', 'System']
$registry->isDeclared('System');      // true
$registry->sortFor(null);             // -1
$registry->sortFor('User Management');// 0
$registry->sortFor('Finance', ['Finance', 'Audit']);  // 1001
$registry->isCollapsible('System');   // true, unless the panel turned collapsing off
```

## What a class contributes

Resources and pages both expose the same statics:

| Property | Type | Default | Effect |
| --- | --- | --- | --- |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | The heading. `null` puts the item in the ungrouped bucket, drawn first. |
| `$navigationLabel` | `?string` | `null` | Falls back to the plural label (resources) or the title (pages). |
| `$navigationIcon` | `?string` | `null` | An icon registry key. |
| `$activeNavigationIcon` | `?string` | `$navigationIcon` | A second icon worn while the item is active. |
| `$navigationSort` | `int` | `0` | Order inside the group. |
| `$shouldRegisterNavigation` | `bool` | `true` | `false` keeps the class registered and its routes working, and drops the sidebar entry. |

```php
use PandaPanel\Pages\Page;

final class AuditLog extends Page
{
    protected static ?string $navigationLabel = 'Audit';

    protected static ?string $navigationIcon = 'shield';

    protected static ?string $activeNavigationIcon = 'shield-check';

    protected static string|BackedEnum|null $navigationGroup = 'System';

    protected static int $navigationSort = 20;

    protected static bool $shouldRegisterNavigation = true;
}
```

`activeNavigationIcon` is sent with every item whether or not it is currently active, so the swap happens on a client-side navigation without a round trip.

A panel can override a resource's placement without touching the class — see [Per-Panel Configuration](../resources/per-panel-configuration.md):

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->navigationLabel('Directory')
        ->navigationGroup('Company')
        ->navigationIcon('building-2')
        ->navigationSort(99),
]);
```

## Badges

There is no `navigationBadge()` static. A badge comes from building the item yourself:

```php
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

public static function navigationItem(PanelContract $panel): ?NavigationItem
{
    return NavigationItem::make(
        label: 'Users',
        href: static::url(panel: $panel instanceof Panel ? $panel : null),
        icon: 'users',
        badge: static fn (): int => User::query()->whereNull('verified_at')->count(),
        sort: 10,
        group: 'User Management',
    );
}
```

The closure is evaluated on the server, only for items that survived the authorization filter, and only the scalar result is serialized. A closure never crosses the wire.

## What the builder guarantees

`PandaPanel\Support\NavigationBuilder` produces the `navigation` shared prop:

```php
use PandaPanel\Support\NavigationBuilder;

app(NavigationBuilder::class)->for(panel('admin'), request()->path());   // list<array>
app(NavigationBuilder::class)->groupsFor(panel('admin'), request()->path());   // list<NavigationGroup>
```

- An item the user may not see is dropped **before** its badge is evaluated, so a badge query never runs for somebody who cannot see the item.
- A group left empty by authorization disappears entirely.
- Exactly one item is active: an exact path match wins, otherwise the longest matching prefix, so `/admin/users/3/edit` keeps Users active without also lighting up `/admin`.
- Active state is computed on the server and sent as a boolean.
- Items pointing at an absolute external URL never take part in active matching.
- A resource with no `index` page, and a nested resource, produce no item at all — there would be nothing to link to.

The serialized shape, per group:

```php
[
    'label' => 'User Management',   // null for the ungrouped bucket
    'sort' => 0,
    'collapsible' => true,
    'parent' => null,               // the group this one nests under
    'items' => [
        [
            'label' => 'Users',
            'href' => '/admin/users',
            'icon' => 'users',
            'activeIcon' => 'users',
            'badge' => 7,
            'active' => false,
            'sort' => 10,
            'fullPage' => false,
            'children' => [],
        ],
    ],
]
```

## Collapsing

Group headings are buttons when the group is collapsible, plain labels when it is not:

```php
$panel->sidebar(collapsible: false);   // also makes every group non-collapsible
```

`NavigationRegistry::isCollapsible()` answers false for the ungrouped bucket — a heading that is not there cannot be clicked — and otherwise follows the panel's sidebar flag.

Which groups a user has closed is the only client-owned piece of navigation state. `useNavigation()` persists it in local storage under `panel:{id}:collapsed-groups`, so it survives a reload and does not leak between panels.

```ts
import { useNavigation } from '@/panel/composables/useNavigation';

const { groups, items, activeItem, isCollapsed, toggle } = useNavigation();
```

## Notes

- Groups are matched by label, including against the labels the panel declared. Declaring `NavigationGroup::System` and writing `'System '` with a trailing space on a resource produces two groups.
- The built-in settings pages use the group `Account` and sorts 10, 20, 30. A panel that declares `Account` in `navigationGroups()` controls where that block sits; one that does not gets it appended alphabetically among the undeclared groups.
- The panel's root dashboard is reached at the panel path and is not registered as a page, so it has no sidebar entry unless you register it explicitly with `pages([Dashboard::class])`. Extra dashboards declared with `dashboards()` do appear.
- Navigation visibility is a convenience. Routes, actions, pages and widgets each authorize independently; a hidden item is never the security control.
- Add a new icon key with `php artisan panel:icons`. An unregistered key renders no icon and reports nothing.

## See also

- [Sidebar and Header Layouts](layouts.md)
- [Defining a Panel](defining-panels.md)
- [Dashboards](dashboards.md)
- [Panel API Reference](api.md)
- [Labels and Navigation](../resources/labels-navigation.md)
- [Per-Panel Configuration](../resources/per-panel-configuration.md)
- [Clusters](../pages-navigation/clusters.md)
- [Prefetching](../pages-navigation/prefetching.md)
- [Icons](../frontend/icons.md)
