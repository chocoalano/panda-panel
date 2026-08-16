# `panel:icons`

Rewrites the frontend icon registry from the icon names your PHP actually asks
for. Reach for it every time you write a new `->icon('…')` or
`$navigationIcon`, and in CI with `--check` so a forgotten run fails the build
instead of shipping a blank button.

```bash
php artisan panel:icons
```

```text
INFO  Registered 24 icons.
```

Lucide ships 1768 icons; a panel uses a couple of dozen, and only those belong
in the bundle. The registry stays a build-time allowlist — an unknown name still
resolves to nothing — but it is no longer maintained by hand.

## Signature

```text
panel:icons
    {--check : Fail instead of writing, for CI}
```

| Option | Default | Effect |
| --- | --- | --- |
| `--check` | off | Writes nothing. Compares the file it would have written against the one on disk and fails if they differ. |

```bash
php artisan panel:icons
php artisan panel:icons --check
```

## What it scans

Two roots, both walked for `.php` files:

| Root | Why |
| --- | --- |
| `app_path()` | Your panels, resources, pages, widgets, actions. |
| the package's own `src/` | Half the icons a panel renders belong to actions the framework ships — delete, edit, export. A scan of `app/` alone would rewrite the registry without them, and every built-in action would then render with no icon and no error. |

Names are read from source rather than from booted panels on purpose. An icon
can be declared somewhere no runtime walk reaches: a wizard step, a filter tab,
a header action built inside a method. The string literal in the file is the one
thing every declaration has in common.

## The shapes it recognises

Five patterns, plus one special case:

| Shape | Example |
| --- | --- |
| `->icon('…')` | `Action::make('delete')->icon('trash')` |
| `$navigationIcon = '…'` | `protected static ?string $navigationIcon = 'folder';` |
| `icon: '…'` | a named argument, `Stat::make(...)->icon(icon: 'users')` |
| `'icon' => '…'` | an array key, in a serialized header action or sub-navigation entry |
| `Icon::make('…')` | `PandaPanel\Forms\Prime\Icon::make('shield')` |

```php
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Prime\Icon;

Action::make('approve')->icon('check');          // found
protected static ?string $navigationIcon = 'users';   // found
Icon::make('shield');                            // found
```

The special case is a method literally named `icon()`:

```php
public function icon(): string
{
    return match ($this) {
        self::Draft => 'pencil',
        self::Published => 'check',
    };
}
```

An enum answering "which icon does this case wear" holds its names in match
arms, which none of the five patterns can see. The whole body of a method with
that exact signature is scanned for single-quoted lowercase-kebab strings. It is
scoped to the method name rather than matched loosely, so a `match` returning
ordinary strings elsewhere is not mistaken for a list of icons.

Only lowercase kebab names are recognised: the pattern is `[a-z0-9-]+`. An icon
name held in a constant or built by concatenation is invisible to the scan.

## What it writes

`resources/js/panel/icons/registry.ts`, resolved through
`PandaPanel\Support\FrontendPaths::panel('icons/registry.ts')` — so a project
that moved the panel frontend with `frontend.panel_path` gets the file in the
right place:

```ts
import {
    Check,
    Folder,
    Trash,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

const ICONS = {
    check: Check,
    folder: Folder,
    trash: Trash,
    users: Users,
} satisfies Record<string, Component>;

export type PanelIconName = keyof typeof ICONS;

export function isPanelIconName(name: string): name is PanelIconName {
    return name in ICONS;
}

export function resolveIcon(name: string | null | undefined): Component | null {
    // …
}
```

Names are sorted, so the file is stable across runs and machines. A kebab name
becomes a quoted key (`'rotate-ccw': RotateCcw`) and a single-word name an
unquoted one. `resolveIcon()` returns `null` for anything not in the map, and
warns once per unknown name in development only:

```text
[panel] The icon [trash-2] is not in the icon registry, so nothing is drawn for
it. Run `php artisan panel:icons` to rebuild the registry from the icons your
panels declare.
```

Do not edit the file: the next run overwrites it.

## Validating names

Every name found is checked against the icons Lucide actually ships, read from
`node_modules/@lucide/vue/dist/esm/icons/*.mjs`:

```text
ERROR  Not a Lucide icon: trahs, user-circle-2
```

Unknown names are dropped from the registry and the command exits non-zero. That
message is the only warning you get — an unregistered icon renders nothing at
all, silently, so a typo would otherwise show up as a button that draws no icon.

Write whatever Lucide name you want and run the command. Being told by name is
the point.

If `@lucide/vue` is not installed:

```text
WARN  @lucide/vue is not installed; nothing to check names against.
```

Every name is then taken as given. Treating them all as unknown would empty the
registry — every icon in the panel would silently disappear because somebody ran
this before `npm install`.

## `--check`, for CI

```bash
php artisan panel:icons --check
```

```text
INFO  The icon registry is up to date.
```

```text
ERROR  The icon registry is out of date. Run php artisan panel:icons.
```

Nothing is written either way. Put it beside your lint step:

```yaml
- run: npm ci
- run: php artisan panel:icons --check
```

`npm ci` first, so the Lucide check has something to check against.

## Exit codes

| Run | Outcome | Code |
| --- | --- | --- |
| default | Written, every name known | `0` |
| default | Written, one or more names not Lucide icons | `1` |
| `--check` | File on disk matches, every name known | `0` |
| `--check` | File on disk matches, unknown names present | `1` |
| `--check` | File on disk differs | `1` |

A default run always writes, even when it found an unknown name — the known
icons still belong in the registry. The non-zero exit is about the typo, not
about the file.

## Gotchas

- **The registry is a build artifact, and it is also committed.** Regenerating
  it changes a tracked file, which is what makes `--check` meaningful.
- **A rebuild is not enough on its own.** The file is TypeScript compiled into
  the bundle, so `npm run dev` or `npm run build` has to run after it changes.
- **A name in a variable or a constant is invisible.** Only string literals in
  the five shapes above, and inside an `icon(): string` method, are found.
- **Names must be lowercase kebab.** `'ArrowRight'` matches no pattern and is
  never registered.
- **Running it before `npm install` disables validation.** The registry is
  written from whatever the scan found, typos included.
- **It scans `app/`, not `vendor/` beyond this package.** A plugin shipped as
  its own package declaring icons in its own `src/` is not scanned; register
  those names by declaring them somewhere under `app/`, or edit the plugin's
  published components.

## See also

- [Icons](../frontend/icons.md) — how a name becomes a component
- [Icon registry in production](../deployment/icon-registry.md)
- [Icons troubleshooting](../troubleshooting/icons.md)
- [Component registries](../concepts/component-registries.md)
- [Frontend paths](../configuration/frontend-paths.md)
- [Frontend build](../deployment/frontend-build.md)
- [make:panel-page](make-panel-page.md), [make:panel-widget](make-panel-widget.md)
- [Labels and navigation](../resources/labels-navigation.md)
