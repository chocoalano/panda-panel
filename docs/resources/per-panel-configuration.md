# Resource Configuration Per Panel

`PandaPanel\Resources\ResourceConfiguration` registers one resource class in one panel with a different slug, different labels, a different place in the sidebar, or a narrower query. Reach for it when the same model belongs in two panels and means something slightly different in each — without it, a shared class would have to agree with itself everywhere, and the only way out would be a subclass that exists purely to change a label.

## The minimal case

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People')
        ->navigationLabel('Directory')
        ->navigationGroup('Company')
        ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('is_admin', false)),
]);
```

`UserResource` is now `/admin/users` in the admin panel and `/directory/people` in this one, labelled differently, grouped differently, and unable to reach an administrator record at all. The class itself is untouched.

## Where it goes

`Panel::resources()` accepts class names and configurations in the same array, so a panel mixes both:

```php
use PandaPanel\Core\Panel;
use PandaPanel\Resources\ResourceConfiguration;

public function panel(Panel $panel): Panel
{
    return $panel
        ->path('directory')
        ->resources([
            PostResource::class,
            ResourceConfiguration::for(UserResource::class)->slug('people'),
        ]);
}
```

A discovered resource is registered unconfigured. To configure a class that discovery also finds, name it explicitly with a configuration — a configured class does not additionally claim its default slug.

## Every method

`ResourceConfiguration::for()` is the only constructor; everything else is fluent and returns `self`.

```php
public static function for(string $resource): self;

public function slug(string $slug): self;
public function label(string $label): self;
public function pluralLabel(string $pluralLabel): self;
public function navigationLabel(string $navigationLabel): self;
public function navigationGroup(?string $navigationGroup): self;
public function navigationIcon(?string $navigationIcon): self;
public function navigationSort(int $navigationSort): self;
public function registerNavigation(bool $register = true): self;
public function modifyQueryUsing(Closure $callback): self;
```

| Method | Overrides | Falls back to |
| --- | --- | --- |
| `slug()` | the URL segment and route name | `Resource::defaultSlug()` |
| `label()` | the singular label | `Resource::defaultLabel()` |
| `pluralLabel()` | the plural label | `Resource::defaultPluralLabel()` |
| `navigationLabel()` | the sidebar text | `$navigationLabel`, then the plural label |
| `navigationGroup()` | the sidebar group | `$navigationGroup` on the class |
| `navigationIcon()` | the sidebar icon | `$navigationIcon` on the class |
| `navigationSort()` | order within the group | `$navigationSort` on the class |
| `registerNavigation()` | whether there is an entry at all | `$shouldRegisterNavigation` on the class |
| `modifyQueryUsing()` | narrows `Resource::query()` | no narrowing |

Every field falls back to the class's own, so a panel states only what it wants to differ. `navigationGroup()` and `navigationIcon()` are nullable, which is how a panel removes a group or an icon the class declared:

```php
ResourceConfiguration::for(UserResource::class)
    ->navigationGroup(null)      // ungrouped here, whatever the class says
    ->navigationIcon(null);
```

Note that `navigationGroup()` takes a string only. A class may name its group with a backed enum; a configuration names it with the resolved string.

## Narrowing the query

```php
use Illuminate\Database\Eloquent\Builder;

ResourceConfiguration::for(UserResource::class)
    ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('is_admin', false));
```

The callback receives the resource's query — after the resource's own `query()` has run — and returns it narrowed. Because every read goes through `query()`, the narrowing applies to the list, the view page, the edit page, deletes, bulk operations, action lookups, and global search alike.

**A record this panel may not reach is a 404, not a filtered row.**

```php
app(PanelManager::class)->setCurrentPanel($directory);

UserResource::resolveRecord($admin->getKey());   // ModelNotFoundException
```

That is the guarantee worth testing: it means a guessed URL in the narrowed panel is refused by the same rule that shortened the list.

## Asking which panel

Four static methods on the resource answer per panel rather than per class:

```php
use PandaPanel\Core\Panel;

public static function slug(): string;                    // the current panel's
public static function slugIn(?Panel $panel): string;     // a named panel's
public static function label(): string;
public static function pluralLabel(): string;
public static function configurationIn(?Panel $panel): ?ResourceConfiguration;
```

```php
UserResource::slugIn(panel('admin'));      // 'users'
UserResource::slugIn(panel('directory'));  // 'people'
UserResource::slug();                      // whichever panel this request is in
```

Outside a panel there is nobody to ask, and the class's own default is the answer: `configurationIn(null)` is `null`, and `slug()` falls back to `defaultSlug()`.

The class's own values are always reachable, whatever a panel did with them:

```php
public static function defaultSlug(): string;
public static function defaultLabel(): string;
public static function defaultPluralLabel(): string;
```

## URLs across panels

```php
UserResource::url();                          // the current panel
UserResource::url(panel: 'admin');            // /admin/users
UserResource::url(panel: $directoryPanel);    // /directory/people
UserResource::url('edit', $user, 'admin');    // /admin/users/1/edit
```

`Resource::url()` is always route-name based, and route names follow the panel's slug: `panel.directory.resources.people.index`. Asking for a URL in a panel that does not register the resource throws, which is what makes panel isolation provable rather than accidental.

```php
UserResource::url(panel: 'app');
// PanelRegistrationException: ... is not registered in the panel [app]
```

## What the registry refuses

A panel keys its resources by slug, and the registry fails loudly rather than picking a winner.

```php
// Two classes on one slug: which one does /shared belong to?
$panel->resources([
    ResourceConfiguration::for(UserResource::class)->slug('shared'),
    ResourceConfiguration::for(AccountResource::class)->slug('shared'),
]);
// PanelRegistrationException: ... is used by both ...
```

```php
// One class twice in one panel: which slug would Resource::url() mean?
$panel->resources([
    ResourceConfiguration::for(UserResource::class)->slug('staff'),
    UserResource::class,
]);
// One registration survives — the configured one. Slugs: ['staff']
```

The second case is deliberately not an error but also not two registrations: a configured class does not additionally claim its default slug, so a panel that both configures a class and lets discovery find it ends up with exactly one entry.

To have one model appear twice in one panel — an "Active users" list and an "Archived users" list — write two resource classes over the same model. Two registrations of one class would leave `Resource::url()` with no way to say which was meant.

## Notes

- **The registry owns the effective slug, not the class.** Route registration asks the registry, because during boot there is no current panel to ask the class.
- **Route names change with the slug.** `panel.directory.resources.people.index`, not `...users...`. Code that hardcodes a route name for a class registered under two slugs will break in one of them; `Resource::url(panel: ...)` will not.
- **`modifyQueryUsing()` composes with the resource's own `query()`,** it does not replace it. A resource that overrode `query()` without calling `parent::query()` drops the panel's narrowing silently.
- **The callback runs per query, per request.** Keep it to constraints; anything expensive runs on every read.
- **Configuration is per panel, not per user.** A rule that depends on who is asking belongs in `query()` or in a policy.
- **`ResourceConfiguration` is `final`.** It carries no behaviour beyond these fields; anything else belongs on the resource class.

## See also

- [Creating resources](creating-resources.md)
- [Resource queries](queries.md)
- [Labels and navigation](labels-navigation.md)
- [URLs and route names](urls-routes.md)
- [Resource API reference](api.md)
- [Defining panels](../panels/defining-panels.md)
- [Multi-panel applications](../panels/multi-panel.md)
- [Panel ids, paths and domains](../panels/ids-paths-domains.md)
- [Discovery](../concepts/discovery.md)
