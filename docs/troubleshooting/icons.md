# Icons that render nothing

`->icon('shield')` draws empty space, with no error and nothing in the log. The icon registry is a
build-time allowlist: a name that is not a key in `resources/js/panel/icons/registry.ts` resolves to
nothing. Reach for this page when an icon is missing from a button, a navigation item, a widget or a
notification.

## Start here

```bash
php artisan panel:icons
npm run build
```

```text
  INFO  Registered 24 icons.
```

The command scans your `app/` directory and the framework's own source for every shape an icon name
is declared in, checks each name against the icons Lucide actually ships, and rewrites the registry.
Then rebuild: the registry is a TypeScript module, so a new entry is not in the bundle until Vite
has seen it.

## Why an unknown name draws nothing

Icon names arrive from the server as plain strings. They resolve through one map and nothing else.

Resolving an arbitrary server-supplied name to a component would let panel metadata reach into the
bundle, and a dynamic import keyed on a runtime string is not statically analysable either. Lucide
ships 1768 icons; a panel uses a couple of dozen, and only those belong in the bundle.

Generating the registry changes who maintains the list, not what it guarantees.

## The command

```bash
php artisan panel:icons          # rewrite the registry from the source
php artisan panel:icons --check  # fail instead of writing, for CI
```

| Option | Effect |
| --- | --- |
| *(none)* | Writes `resources/js/panel/icons/registry.ts` and reports how many icons it registered |
| `--check` | Compares the file it would write against the one on disk, writes nothing, and fails when they differ |

Exit codes:

| Situation | Exit |
| --- | --- |
| Written, every name is a real Lucide icon | `0` |
| Written, one or more names are not Lucide icons | `1`, after `Not a Lucide icon: shildd` |
| `--check`, file is current, every name valid | `0` |
| `--check`, file is out of date | `1`, after `The icon registry is out of date. Run php artisan panel:icons.` |
| `--check`, file is current but a name is unknown | `1` |

A name Lucide does not have fails the command **by name**, which is the only warning you get — an
unregistered icon renders nothing at all, with no error.

Wire `--check` into CI beside the other static checks:

```bash
php artisan panel:icons --check
composer run analyse
npm run types:check
```

## What the command reads

Two roots, both scanned for `.php` files:

| Root | Why |
| --- | --- |
| `app_path()` | your panels, resources, pages, widgets and actions |
| the framework's own `src/` | half the icons a panel renders belong to actions the framework ships — delete, edit, export |

The second is not optional. Once the framework is a package in `vendor`, a scan of `app/` alone
would rewrite the registry without them, and every built-in action would render with no icon and no
error.

Five literal patterns, plus the body of any method literally named `icon()`:

```php
->icon('shield')                              // the fluent setter
protected static ?string $navigationIcon = 'users';
->emptyState(heading: 'None yet', icon: 'users')   // any named argument called icon
['icon' => 'download']                        // a serialized array entry
PandaPanel\Forms\Prime\Icon::make('mail')     // the prime component
```

```php
// scanned because the method is named icon()
public function icon(): string
{
    return match ($this) {
        self::Paid => 'check',
        self::Overdue => 'triangle-alert',
    };
}
```

The `icon()` method body is scanned as a special case because an enum answering "which icon does
this case wear" holds its names in match arms, which none of the literal patterns can see. It is
scoped to that exact method name so a `match` returning ordinary strings elsewhere is not mistaken
for a list of icons.

**A name that is not a literal is invisible to all of this.** A constant, a variable, or a name
built by concatenation will not be registered:

```php
->icon(self::ICON)                     // not seen
->icon('user-'.$suffix)                // not seen
->icon('user-check')                   // seen
```

Names must match `[a-z0-9-]+` — Lucide's own kebab-case spelling.

## Checking names against Lucide

The command reads `node_modules/@lucide/vue/dist/esm/icons` and treats each `.mjs` filename as an
available icon name.

```text
  WARN  @lucide/vue is not installed; nothing to check names against.
```

With no Lucide on disk there is nothing to check against, and treating every name as unknown would
empty the registry — every icon in the panel would silently disappear because somebody ran the
command before `npm install`. The names are taken as given instead, so the registry it writes is
still correct; only the validation is skipped.

## The registry module

```ts
import { isPanelIconName, resolveIcon } from '@/panel/icons/registry';
import type { PanelIconName } from '@/panel/icons/registry';

isPanelIconName('shield');    // true — narrows string to PanelIconName
resolveIcon('shield');        // the Lucide component
resolveIcon('shildd');        // null, plus one dev-only console warning
resolveIcon(null);            // null, silently
```

| Export | Signature | Notes |
| --- | --- | --- |
| `PanelIconName` | `type PanelIconName = keyof typeof ICONS` | the registered names, as a union |
| `isPanelIconName` | `isPanelIconName(name: string): name is PanelIconName` | a type guard |
| `resolveIcon` | `resolveIcon(name: string \| null \| undefined): Component \| null` | `null` for unknown or absent |

`resolveIcon()` answers `null` for both an unknown name and no name at all — callers render no icon
rather than a broken one, and production stays console-clean. In development it warns once per name:

```text
[panel] The icon [shildd] is not in the icon registry, so nothing is drawn for it.
Run `php artisan panel:icons` to rebuild the registry from the icons your panels declare.
```

That warning is the whole difference between "a typo" and "you forgot to re-run the command", which
were previously indistinguishable from the screen. It is development-only: in production the icon is
still simply absent, because this is a build problem rather than a runtime one.

The registry currently ships with these names:

```text
check          circle-alert   copy           download       eye
info           layout-grid    link           mail           palette
pencil         plus           receipt        rotate-ccw     search
settings       shield         trash          trash-2        triangle-alert
unlink         upload         user           users
```

That list is generated output. Do not read it as the supported set — write whatever Lucide name you
want and run `panel:icons`.

## Where the file is written

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                      // resource_path('js/panel')
FrontendPaths::panel('icons/registry.ts');   // resource_path('js/panel/icons/registry.ts')
FrontendPaths::pages();                      // resource_path('js/pages/Panels')
```

| Method | Signature | Config key | Default |
| --- | --- | --- | --- |
| `panel` | `static panel(string $path = ''): string` | `panda-panel.frontend.panel_path` | `js/panel` |
| `pages` | `static pages(string $path = ''): string` | `panda-panel.frontend.pages_path` | `js/pages/Panels` |

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

A project that arranged `resources/js` its own way changes the key, and `panel:icons`,
`vendor:publish` and `panel:assets` all follow — they read the same class.

## Asserting it in a test

The framework's own suite walks every panel, every navigation item, every resource's row and bulk
actions, and asserts each requested name is a key in the registry. The shape is worth copying:

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\NavigationBuilder;

$source = file_get_contents(resource_path('js/panel/icons/registry.ts'));
$body = str($source)->between('const ICONS = {', '} satisfies')->toString();

preg_match_all("/^\s*'?([a-z0-9-]+)'?:/m", $body, $matches);

expect($matches[1])->toContain('shield');
```

Navigation is not the only place a name can hide: a table's row and bulk actions carry icons too,
and an unregistered one there fails exactly as silently.

## Notes

- **The registry is generated. Do not edit it by hand.** The file says so at the top, and the next
  `panel:icons` run overwrites whatever you wrote.
- **Adding an icon needs two steps**: the command, and `npm run build`. Neither works alone.
- **The command is not registered as an `optimize` hook.** `panel:cache` and `panel:clear` are;
  `panel:icons` is a source-generation step and belongs in the build, before the assets are compiled.
- **A misspelled name and a name declared after the last run look identical on screen** — both draw
  nothing. The dev console warning is what tells them apart, and it only appears in development.
- **`--check` failing on a clean checkout usually means an icon was added in PHP and the registry
  was not regenerated.** Run the command and commit the result.
- **Icons in metadata are strings all the way down.** Nothing about a name is validated on the
  server, because the check that matters — "is this a component the bundle has" — can only be
  answered by the build.
- **A panel's `icon()`, a resource's `$navigationIcon`, a cluster's `$navigationIcon` and
  `$activeNavigationIcon`, an action's `icon()`, and a notification's `icon()` all resolve through
  this one registry.** There is no second lookup anywhere.

## See also

- [Icons](../frontend/icons.md), [`panel:icons`](../cli/panel-icons.md)
- [Icon registry in deployment](../deployment/icon-registry.md), [frontend build](../deployment/frontend-build.md)
- [Component registries](../concepts/component-registries.md)
- [Configuration: frontend paths](../configuration/frontend-paths.md)
- [Branding, logo, icon, favicon](../panels/branding.md), [labels and navigation](../resources/labels-navigation.md)
- [Common install problems](../getting-started/common-install-problems.md)
- [Vite build errors](vite.md), [asset conflicts](asset-conflicts.md)
