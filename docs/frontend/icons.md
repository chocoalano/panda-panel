# Icons

Every icon in a panel is a string on the PHP side and a Lucide component on the Vue side, and the two are joined by one generated file: `resources/js/panel/icons/registry.ts`. Reach for this page when an icon renders as nothing, when adding an icon name to a panel, or when wiring `panel:icons` into CI.

The registry is a build-time allowlist rather than a lookup. Lucide ships over a thousand icons; a panel uses a couple of dozen, and only those belong in the bundle.

## A minimal working example

Name an icon in PHP, using the Lucide name in kebab-case:

```php
use PandaPanel\Pages\Page;

final class Reports extends Page
{
    protected static ?string $navigationIcon = 'chart-line';
}
```

Rebuild the registry from the source, then rebuild the bundle:

```bash
php artisan panel:icons
npm run build
```

```text
Registered 24 icons.
```

Skip `panel:icons` and the icon is simply absent — no error, no fallback glyph, nothing drawn.

## Where a name may be declared

`panel:icons` scans PHP source for every shape an icon name takes. These are the five patterns it matches, plus one special case:

| Shape | Example |
| --- | --- |
| The fluent setter | `->icon('trash-2')` |
| The navigation property | `protected static ?string $navigationIcon = 'users';` |
| A named argument | `icon: 'shield'` |
| An array key | `'icon' => 'mail'` |
| The prime component | `Icon::make('info')` |
| A method named `icon()` | every string literal inside its body |

The last exists for enums. An enum that answers "which icon does this case wear" holds its names in match arms, which none of the patterns above can see:

```php
enum OrderState: string
{
    case Pending = 'pending';
    case Shipped = 'shipped';

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'clock',
            self::Shipped => 'truck',
        };
    }
}
```

The scan is scoped to the method *name* rather than matched loosely, so a `match` returning ordinary strings elsewhere is not mistaken for a list of icons.

Names are read from source rather than from booted panels on purpose. An icon can be declared in a place no runtime walk reaches: a wizard step, a filter tab, a header action built inside a method. The literal in the file is the one thing every declaration has in common.

## Which APIs accept an icon name

Everywhere a name is a registry key, never a component path or a class:

| Class | Method or property |
| --- | --- |
| `PandaPanel\Core\Panel` | `icon(?string $icon): self` — the brand mark |
| `PandaPanel\Pages\Page` | `$navigationIcon`, `$activeNavigationIcon` |
| `PandaPanel\Resources\Resource` | `$navigationIcon`, `$activeNavigationIcon` |
| `PandaPanel\Actions\Action` | `icon(string $icon): static` |
| `PandaPanel\Tables\Tab` | `icon(?string $icon): self` |
| `PandaPanel\Forms\Layouts\Tab` | `icon(string $icon): self` |
| `PandaPanel\Forms\Layouts\Step` | `icon(?string $icon): self` |
| `PandaPanel\Forms\Layouts\Callout` | `icon(string $icon): self` |
| `PandaPanel\Forms\Layouts\EmptyState` | `icon(string $icon): self` |
| `PandaPanel\Forms\Prime\Icon` | `Icon::make(string $icon): self` |
| `PandaPanel\Forms\Prime\Text` | `icon(string $icon): self` |
| `PandaPanel\Forms\Support\Block` | `icon(string $icon): self` |
| `PandaPanel\Infolists\Layouts\Tab` | `icon(string $icon): self` |
| `PandaPanel\Notifications\Notification` | `icon(string $icon): self` |
| `PandaPanel\Widgets\Support\Stat` | `icon(string $icon): self` |
| `PandaPanel\Tables\TableSchema` | `emptyState(string $heading, ?string $description = null, ?string $icon = null): self` |
| `Panel::userMenuItems()` | the optional `icon` key of each entry |

## The command

```bash
php artisan panel:icons
php artisan panel:icons --check
```

| Option | Effect |
| --- | --- |
| *(none)* | rewrites `resources/js/panel/icons/registry.ts` |
| `--check` | writes nothing; fails when the file is out of date |

The command:

1. reads every Lucide icon on disk, from `node_modules/@lucide/vue/dist/esm/icons`;
2. scans two trees for declared names — the application's `app/` **and** the framework's own source;
3. drops any name Lucide does not have, reporting it by name;
4. writes the registry, sorted.

Both trees are scanned, and the second is not optional. Half the icons a panel renders belong to actions the framework ships — delete, edit, export — and a scan of `app/` alone would rewrite the registry without them, leaving every built-in action with no icon and no error.

A name Lucide does not have is a typo, and a typo here is a button with no icon and no message:

```text
Not a Lucide icon: users-round-alt
```

The command exits non-zero when that happens, even though it still writes the rest.

**With no Lucide on disk** — `panel:icons` run before `npm install` — there is nothing to check names against. Rather than treating every name as unknown and emptying the registry, the command warns and takes the names as given:

```text
@lucide/vue is not installed; nothing to check names against.
```

The output file is written to `FrontendPaths::panel('icons/registry.ts')`, so a project that moved `panda-panel.frontend.panel_path` gets it in the right place.

## The generated file

```ts
import { Check, CircleAlert, Copy, Eye, /* … */ } from '@lucide/vue';
import type { Component } from 'vue';

const ICONS = {
    check: Check,
    'circle-alert': CircleAlert,
    copy: Copy,
    eye: Eye,
    // …
} satisfies Record<string, Component>;

export type PanelIconName = keyof typeof ICONS;

export function isPanelIconName(name: string): name is PanelIconName;
export function resolveIcon(name: string | null | undefined): Component | null;
```

Kebab-case names are quoted keys; single-word names are not. Both are generated, so do not edit the file by hand — the next `panel:icons` overwrites it.

## Resolving an icon in Vue

```ts
import { resolveIcon, isPanelIconName } from '@/panel/icons/registry';
import type { PanelIconName } from '@/panel/icons/registry';

resolveIcon('shield');        // Component
resolveIcon('not-an-icon');   // null
resolveIcon(null);            // null
resolveIcon(undefined);       // null

isPanelIconName('shield');    // true
```

| Export | Signature |
| --- | --- |
| `PanelIconName` | `type PanelIconName = keyof typeof ICONS` |
| `isPanelIconName` | `(name: string) => name is PanelIconName` |
| `resolveIcon` | `(name: string \| null \| undefined) => Component \| null` |

Used the way every shipped component uses it — resolve, then render only if there is something to render:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { resolveIcon } from '@/panel/icons/registry';

const props = defineProps<{ icon: string | null }>();

const resolved = computed(() => resolveIcon(props.icon));
</script>

<template>
    <component :is="resolved" v-if="resolved" class="size-4" />
</template>
```

`resolveIcon` accepts `null` and `undefined` so a caller never has to guard before calling it; the guard is in the template, on the result.

## Why a generated map rather than a dynamic import

Two reasons, and the second decides it.

Resolving an arbitrary server-supplied name to a component would let panel metadata reach into the bundle. The name always originates from a registered PHP declaration rather than from request input, so the allowlist is the second lock rather than the first — but a second lock is worth having on the path between data and code execution.

The other reason is that it has to work at all. A dynamic import keyed on a runtime string is not statically analysable, so the bundler cannot know which files to emit. A generated import list is: every icon in it is in the bundle, and nothing else is.

## When an icon does not appear

In development, `resolveIcon` says so once per name:

```text
[panel] The icon [chart-line] is not in the icon registry, so nothing is drawn
for it. Run `php artisan panel:icons` to rebuild the registry from the icons
your panels declare.
```

Production is silent — this is a build problem, and a console message on a live panel helps nobody.

The order to check:

1. **Did you run `panel:icons`?** This is the failure mode. Declaring a name in PHP and not regenerating is the most common reason an icon is absent.
2. **Is it a real Lucide name?** `panel:icons` names the ones that are not.
3. **Is it kebab-case?** `trash-2`, not `Trash2` or `trash2`.
4. **Did you rebuild?** The registry is a source file; a bundle built before it changed does not contain the new import.

## In CI

```bash
php artisan panel:icons --check
```

Fails when the file on disk differs from what the command would write, and fails when any declared name is not a Lucide icon. That is what turns a silent missing icon into a red build:

```text
The icon registry is out of date. Run php artisan panel:icons.
```

The package's own suite runs exactly that assertion, alongside a walk of every registered panel's navigation, resource icons, row actions and bulk actions.

## Gotchas

- **An unregistered name renders nothing at all.** Not a placeholder, not a broken image — an empty space. This is the one place in the framework where an invalid value fails silently by design, which is why `--check` in CI matters.
- **`panel:icons` needs `node_modules`.** Without `@lucide/vue` on disk it cannot validate names, and it says so rather than emptying the registry.
- **Do not hand-edit the registry.** It carries a "generated" banner and the next run overwrites it. Add the name in PHP instead.
- **`activeNavigationIcon` falls back to `navigationIcon`.** Both are sent with every navigation item so the swap happens on a client-side navigation, without waiting for the server to say which item won — so both names must be registered. The scan matches `$navigationIcon` only, so a name used solely as an `$activeNavigationIcon` is not picked up; declare it somewhere the patterns above reach, or the active icon is the one that renders as nothing.
- **Icons in `data()` payloads are not scanned.** The patterns match literals in PHP source. An icon name assembled at runtime — `'chart-'.$type` — is invisible to the scan and will not be registered.
- **The scan covers `app/` and the package.** Icons declared in a package of your own outside `app/` are not seen; add them to a scanned file or register the name in a panel provider.

## See also

- [Component Registries](../concepts/component-registries.md)
- [Branding, Logo, Icon, Favicon](../panels/branding.md)
- [Navigation Groups](../panels/navigation-groups.md)
- [Vue Component Tree](component-tree.md)
- [Custom Shell Components](custom-shell.md)
- [panel:icons](../cli/panel-icons.md)
- [Icon registry in deployment](../deployment/icon-registry.md)
- [Icons troubleshooting](../troubleshooting/icons.md)
