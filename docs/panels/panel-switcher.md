# Panel Switcher

The control in the panel header that moves a signed-in user between the panels
they may enter. It is built entirely from panel configuration — there is no
switcher API to call. You reach for this page when you want to change what an
entry says, decide who sees which entry, or draw the switcher yourself in a
replacement shell.

## A minimal working example

Register two panels. Registration is by hand, in order:

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

Each one names itself and says who may enter:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Administrator')
            ->brandName((string) config('app.name'))
            ->icon('shield')
            ->auth()
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
```

A user who may enter both now gets the switcher in the header of both. A user
who may enter one gets no switcher at all: the control hides itself rather than
offering a move to where they already are.

## What the server sends

`PandaPanel\Http\Middleware\SharePanelData` shares a `panels` prop on every
panel request. It is a closure, so a request that never renders panel props
never builds it.

| Key | Type | Where it comes from |
| --- | --- | --- |
| `id` | `string` | `Panel::getId()` |
| `name` | `string` | `Panel::getName()` — `name()`, or `Str::headline()` of the id |
| `brandName` | `string` | `Panel::getBrandName()` — `brandName()`, or `config('app.name')` |
| `path` | `string` | `'/'.Panel::getPath()` — `path()`, or the id |
| `icon` | `string\|null` | `Panel::getIcon()`, an icon registry key |
| `darkIcon` | `string\|null` | `Panel::getDarkIcon()`, used in dark mode when present |
| `url` | `string` | `route($panel->routeName('dashboard'), absolute: false)` |
| `current` | `bool` | Whether this is the panel the request is in |

Outside a panel the list is empty rather than the full set of panels — the
starter kit's own pages have nothing to switch between:

```php
use Inertia\Testing\AssertableInertia;

$this->actingAs($admin)
    ->get('/')
    ->assertInertia(fn (AssertableInertia $page) => $page->where('panels', []));
```

Nothing else about a panel crosses in this prop. `brandLogo`, middleware,
discovery paths and boot callbacks stay on the server; the switcher renders a
name, a brand, a path and an icon pair, so that is what it is sent.

## Who sees which entry

The list is filtered by `Panel::isAccessibleTo()`, the same predicate the panel
routes enforce. A panel a user would be refused never appears as somewhere to
go, so the switcher can never offer a destination that answers 403.

```php
public function isAccessibleTo(?Authenticatable $user): bool
```

Two questions, and both must agree:

```php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

// A rule about this panel.
$panel->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

class User extends Authenticatable implements PanelUser
{
    /**
     * A rule about the user: a suspended account belongs in no panel,
     * whatever any individual panel says.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->suspended_at === null;
    }
}
```

`canAccess()` takes a `Closure(?Authenticatable): bool` and is optional — a
panel that never calls it refuses nobody. `PanelUser` is optional too, and a
user model that does not implement it is refused nothing. Neither can quietly
loosen the other: a panel that says yes cannot overrule a user model that says
no.

## What an entry looks like

Four setters shape one row of the switcher.

| Method | Signature | Default | Shown as |
| --- | --- | --- | --- |
| `name` | `name(string $name): self` | headline of the id | The entry's title |
| `brandName` | `brandName(string $brandName): self` | `config('app.name')` | The line under the title |
| `path` | `path(string $path): self` | the id | Beside the brand name |
| `icon` | `icon(?string $icon): self` | `null` | The square badge |

```php
$panel
    ->name('Administrator')      // "Administrator"
    ->brandName('Acme')          // "Acme · /admin"
    ->path('admin')
    ->icon('sun', darkIcon: 'moon');
```

The icon is a registry key, never a component or a path. Names resolve through
`resources/js/panel/icons/registry.ts` and nothing else, so a name that is not
a key there renders no icon at all — silently, because a decorative icon must
not be able to break a header. `darkIcon` is used only when the resolved
appearance is dark; otherwise the switcher falls back to `icon`. The registry
is generated:

```bash
php artisan panel:icons          # rewrite it from the names the PHP declares
php artisan panel:icons --check  # fail instead of writing, for CI
```

## Where an entry goes

Every entry points at the panel root — `route($panel->routeName('dashboard'))`,
which is the `panel.{id}.dashboard` route. Not the equivalent page in the other
panel: two panels register different resources, and a URL translated between
them would be a guess.

```php
$panel->routeName('dashboard');   // 'panel.admin.dashboard'
$panel->getRouteNamePrefix();     // 'panel.admin.'
```

Both are public, so an application can build the same link the switcher does:

```php
use PandaPanel\Facades\PandaPanel;

$url = route(PandaPanel::get('app')->routeName('dashboard'), absolute: false);   // '/app'
```

## The frontend

`usePanel()` exposes the list and the one derived question the switcher asks:

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { panels, canSwitchPanels } = usePanel();
```

| Binding | Type | Meaning |
| --- | --- | --- |
| `panels` | `ComputedRef<PanelSummary[]>` | The entries, empty outside a panel |
| `canSwitchPanels` | `ComputedRef<boolean>` | `panels.length > 1` |

`PanelSummary` is declared in `resources/js/panel/types/panel.ts` and mirrors the
prop exactly.

`PanelSwitcher.vue` renders them as a sheet — each entry carries a brand, a
name and a path, which needs more room than a menu row — and returns nothing
when `canSwitchPanels` is false. It is drawn by `PanelHeader.vue`, which both
shells render while `shell.topbar` is true, so the switcher is present in the
sidebar shell and the header shell alike.

Two calls affect whether the built-in control is there at all:

```php
$panel->topbar(false);                                        // no bar, and so no switcher
$panel->topbarComponent('Panels/Admin/Shell/Topbar');         // a replacement top navigation
```

`topbarComponent()` is honoured by the header shell only — `topNavigation()`,
or `sidebar(variant: 'header')` — where it replaces the navigation row above
the bar. It is rendered whether or not `topbar(false)` removed the bar itself,
so a panel that wants one bar of its own drawing sets both.

The replacement is handed the same navigation the built-in row gets, and reads
the switcher entries from the same composable:

```vue
<!-- resources/js/pages/Panels/Admin/Shell/Topbar.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePanel } from '@/panel/composables/usePanel';
import type { NavigationGroup } from '@/panel/types/navigation';

defineProps<{ groups: NavigationGroup[] }>();

const { panels, canSwitchPanels } = usePanel();
</script>

<template>
    <header class="flex items-center gap-4">
        <!-- your navigation, from `groups` -->

        <nav v-if="canSwitchPanels" class="ml-auto flex items-center gap-2">
            <Link
                v-for="entry in panels"
                :key="entry.id"
                :href="entry.url"
                :aria-current="entry.current ? 'page' : undefined"
            >
                {{ entry.name }}
            </Link>
        </nav>
    </header>
</template>
```

`Panels/Admin/Shell/Topbar` is a build-time registry key under
`resources/js/pages/Panels/{Panel}/Shell/`, never a path. An unregistered name
falls back to the built-in bar rather than leaving the page with no navigation
at all.

## Choosing a panel from PHP

The switcher answers "where else may I go" for a user already in a panel. Three
other questions come up, and each has one answer.

```php
use PandaPanel\Facades\PandaPanel;

PandaPanel::all();                        // list<Panel>, in registration order
PandaPanel::has('admin');                 // bool
PandaPanel::get('admin');                 // Panel, throws when unknown
PandaPanel::currentPanel();               // ?Panel for this request
PandaPanel::resolveFromRequest($request); // ?Panel, matched on domain then longest path
PandaPanel::firstAccessibleTo($user);     // ?Panel — where to send somebody
```

The `panel()` helper covers the common case:

```php
panel();          // the panel for this request, or null
panel('admin');   // an explicit panel; throws if unknown
```

`firstAccessibleTo()` walks panels in registration order and returns the first
the user may enter, which is why the order in `config/panda-panel.php` matters:
the answer is the same on every request rather than depending on which route
happened to run. It is what decides where a user lands when the request names
no panel at all — the starter kit's `/dashboard`, redirected by
`PandaPanel\Support\PanelHomeRedirect`:

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

A signed-in administrator opening `/dashboard` is sent to `/admin`; a member,
who fails the admin panel's predicate, is sent to `/app`. A request already
inside a panel is left alone, so a panel mounted on one of these paths cannot
redirect to itself.

## Notes

- The switcher is not a setting. There is no `panelSwitcher(false)`: it is
  absent when the user may enter one panel, and removed with the bar it lives
  in when a panel calls `topbar(false)`.
- Every registered panel's `canAccess()` closure runs each time the prop is
  built, which is once per full page render. Keep the predicate a check on
  something already loaded rather than a query.
- The entry's `url` is a path (`absolute: false`). A panel served from its own
  `domain()` is therefore linked as a path on the host of the current request,
  which is not where that panel is served. Cross-domain switching needs a
  replacement bar that builds the absolute URL itself.
- `brandLogo` is shared with the panel shell but is not part of a switcher
  entry. The badge draws `icon` or `darkIcon` and nothing else.
- The tenant switcher beside it is a different control with different rules —
  see [Tenant Switcher](../tenancy/switcher.md).
- The behaviour above is pinned by `tests/Feature/Panel/PanelSwitcherTest.php`,
  which is the shortest description of it that cannot go out of date.

## See also

- [Multi-Panel Applications](multi-panel.md)
- [Panel Access Rules](access.md)
- [Panel IDs, Paths, and Domains](ids-paths-domains.md)
- [Branding, Logo, Icon, Favicon](branding.md)
- [Sidebar and Header Layouts](layouts.md)
- [Render Hooks](render-hooks.md)
- [Tenant Switcher](../tenancy/switcher.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
- [Panel User Contract](../authentication/panel-user-contract.md)
