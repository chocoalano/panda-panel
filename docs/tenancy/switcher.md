# Tenant Switcher

The tenant switcher is the dropdown in the panel header that moves a user
between the tenants they belong to. The server builds the list —
`PandaPanel\Http\Middleware\SharePanelData` shares it as the `tenancy` prop —
and `PanelTenantSwitcher.vue` renders it. It appears on its own once a panel has
tenancy, a URL builder, and a user who belongs to more than one tenant; there is
nothing to register.

## Turning it on

```php
use App\Models\Workspace;
use Illuminate\Http\Request;
use PandaPanel\Core\Panel;

$panel
    ->tenant(
        Workspace::class,
        static fn (Request $request): ?Workspace => Workspace::query()
            ->find($request->query('workspace')),
    )
    ->tenantUrlUsing(
        static fn (Workspace $workspace, Panel $panel): string => '/'
            .$panel->getPath().'/documents?workspace='.$workspace->getKey(),
    );
```

`tenant()` is what produces the list. `tenantUrlUsing()` is what makes the
entries clickable, and without it the switcher does not render at all — see
[Tenant URLs](urls.md).

## The three conditions

The switcher renders only when all three are true:

| Condition | Where it is decided |
| --- | --- |
| The panel declared tenancy | `Panel::tenant()` — otherwise `tenancy` is `null` |
| The user may enter more than one tenant | `HasPanelTenants::getPanelTenants()` returns two or more |
| At least one entry has a URL | `Panel::tenantUrlUsing()` was called |

```ts
canSwitchTenants: computed(
    () =>
        (tenancy.value?.available.length ?? 0) > 1 &&
        (tenancy.value?.available ?? []).some(
            (entry) => entry.url !== null,
        ),
),
```

A user who belongs to exactly one tenant sees nothing, because there is nowhere
to switch to. A panel that never said how to build a tenant's URL sees nothing,
because a switcher whose entries went nowhere would be worse than no switcher.

## The shared prop

`SharePanelData` shares `tenancy` for every `web` request. It is a closure, so
a panel screen that never draws a switcher never runs the query behind it.

```php
/**
 * @return array{
 *     current: array{key: int|string, name: string, url: string|null, current: bool}|null,
 *     available: list<array{key: int|string, name: string, url: string|null, current: bool}>
 * }|null
 */
private function tenancy(Request $request): ?array
{
    $panel = $this->manager->currentPanel();

    if ($panel === null || ! $panel->hasTenancy()) {
        return null;
    }

    $current = Tenancy::current();
    $currentKey = $current === null ? null : Tenancy::keyOf($current);

    $describe = static fn (Model $tenant): array => [
        ...Tenancy::describe($tenant),
        'url' => $panel->getTenantUrl($tenant),
        'current' => $currentKey !== null && Tenancy::keyOf($tenant) === $currentKey,
    ];

    return [
        'current' => $current === null ? null : $describe($current),
        'available' => array_map(
            $describe,
            Tenancy::availableTo($request->user(), $panel),
        ),
    ];
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `current` | entry or `null` | The tenant this request is bound to |
| `available` | list of entries | Every tenant this user may enter, from `getPanelTenants()` |
| entry `key` | `int \| string` | `Tenancy::keyOf()` |
| entry `name` | `string` | `Tenancy::nameOf()` |
| entry `url` | `string \| null` | `Panel::getTenantUrl()`, null when no builder was declared |
| entry `current` | `bool` | Key-identical to the bound tenant |

The whole prop is `null` — rather than an empty shape — for a panel with no
tenancy, so the frontend's check is `tenancy === null` and nothing tenant-shaped
renders in an application that has no tenants.

The list is filtered by the user model's own answer, which is what stops the
switcher offering a destination that responds 403.

## Reading it in Vue

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { tenancy, canSwitchTenants } = usePanel();
```

| Property | Type | Notes |
| --- | --- | --- |
| `tenancy` | `ComputedRef<PanelTenancy \| null>` | `null` for a panel with no tenancy |
| `canSwitchTenants` | `ComputedRef<boolean>` | The three conditions above |

```ts
export interface PanelTenantSummary {
    key: string | number;
    name: string;
    url: string | null;
    current: boolean;
}

export interface PanelTenancy {
    current: PanelTenantSummary | null;
    available: PanelTenantSummary[];
}
```

Both types live in `resources/js/panel/types/panel.ts`. Read them through
`usePanel()` rather than `usePage()` directly — `panelSharedProps()` performs
the single cast in the whole panel frontend, and a contract test asserts no
other file under `resources/js/panel` reads one of those keys off `usePage()`.

## The built-in component

`resources/js/panel/components/PanelTenantSwitcher.vue` is rendered by
`PanelHeader.vue`, between the search palette and the panel switcher.

It is a dropdown rather than the sheet the *panel* switcher uses, and the
difference is not cosmetic: a panel entry carries a brand, an icon and a path
and needs the room, while a tenant is a name.

Each entry is a plain `<a>`, not Inertia's `<Link>`. A tenant usually lives on
another host, and an Inertia visit across origins is a request the browser
refuses; a navigation is what actually moves. An entry whose `url` is `null`
renders as disabled text rather than a link.

## Rendering your own

The shared prop is a public contract, so a custom shell or a topbar component
can render the same data any way it likes:

```vue
<script setup lang="ts">
import { usePanel } from '@/panel/composables/usePanel';

const { tenancy, canSwitchTenants } = usePanel();
</script>

<template>
    <nav v-if="canSwitchTenants" aria-label="Tenants">
        <a
            v-for="tenant in tenancy?.available ?? []"
            :key="tenant.key"
            :href="tenant.url ?? undefined"
            :aria-current="tenant.current ? 'true' : undefined"
        >
            {{ tenant.name }}
        </a>
    </nav>
</template>
```

Place it with a render hook or a custom topbar rather than editing the
package's component — see [Render Hooks](../panels/render-hooks.md) and
[Custom Shell](../frontend/custom-shell.md).

Anything richer than a name — a logo, a plan badge, a member count — is your
own prop. `Tenancy::describe()` returns exactly two keys and there is no hook
to add a third; share what you need from your application's own
`HandleInertiaRequests` and read it alongside `tenancy`.

## Testing it

```php
use Inertia\Testing\AssertableInertia;

it('gives every offered tenant a url and marks the current one', function (): void {
    $this->get('/app/documents?workspace='.$acme->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenancy.current.name', 'Acme')
            ->where('tenancy.available.0.current', true)
            ->where('tenancy.available.1.current', false)
            ->where('tenancy.available.1.url', '/app/documents?workspace='.$beta->getKey()));
});

it('offers no tenant this user does not belong to', function (): void {
    $this->get('/app/documents?workspace='.$acme->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tenancy.available', 1)
            ->where('tenancy.available.0.name', 'Acme'));
});

it('shares nothing at all for a panel with no tenancy', function (): void {
    $response = $this->actingAs($admin)->get('/admin');

    expect($response->viewData('page')['props']['tenancy'])->toBeNull();
});
```

## Notes

- **The switcher navigates; it does not post.** There is no "switch tenant"
  endpoint and no session state to change. Where a tenant lives is a URL, and
  moving is a navigation — which is what makes the same mechanism work for
  subdomains, path segments and query parameters alike.
- **`current` may be `null` while `available` is populated.** That is what the
  panel's own login page looks like: guest routes are registered without
  `ResolveTenant`, so nothing is bound there.
- **`available` order is your order.** `Tenancy::availableTo()` preserves
  whatever `getPanelTenants()` returned. Add an `orderBy`.
- **Keys are compared with `===` after `Tenancy::keyOf()`.** A model returning
  `'7'` from `getTenantKey()` while the bound tenant returns `7` never marks
  itself current. Cast consistently.
- **There is no "no tenant" entry.** A panel with tenancy always has one bound
  on its authenticated routes, or the request was a 404.

## See also

- [Tenancy Concepts](concepts.md)
- [Tenant URLs](urls.md)
- [`PanelTenant`](panel-tenant.md)
- [`HasPanelTenants`](has-panel-tenants.md)
- [Panel Switcher](../panels/panel-switcher.md)
- [Server Metadata to Vue](../concepts/metadata-to-vue.md)
- [Render Hooks](../panels/render-hooks.md)
- [Custom Shell](../frontend/custom-shell.md)
