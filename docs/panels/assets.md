# Panel Assets

Vite entrypoints that load on one panel's pages and nowhere else. Reach for this when a panel needs its own stylesheet or script — a theme that has to differ by colour scheme, a third-party widget library, print styles for a reporting panel — and you do not want it on every other panel or on the application's own pages.

## Declaring one

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
            ->assets('resources/css/panels/admin.css');
    }
}
```

```ts
// vite.config.ts
laravel({
    input: [
        'resources/css/app.css',
        'resources/js/app.ts',
        'resources/css/panels/admin.css',   // the same path, declared to Vite
    ],
    refresh: true,
}),
```

Two edits, deliberately. The panel names a path; Vite has to have built it. Without the second edit the page fails with a manifest error, which is the right failure — a declared asset that was never built is a mistake — but it is why this is not a one-line change.

## The method

```php
public function assets(string ...$entrypoints): self

/** @return list<string> */
public function getAssets(): array
```

Paths relative to the project root, exactly as they appear in `vite.config.ts`. Calls accumulate and duplicates collapse, so a plugin or a module can add a stylesheet without displacing the panel's own:

```php
$panel
    ->assets('resources/css/panels/admin.css')
    ->assets('resources/js/panels/admin.ts', 'resources/css/panels/admin.css');

$panel->getAssets();
// ['resources/css/panels/admin.css', 'resources/js/panels/admin.ts']
```

## Emitting them

The panel's entrypoints are appended to the application's own in the Inertia root view:

```blade
{{-- resources/views/app.blade.php --}}
@vite([
    'resources/css/app.css',
    'resources/js/app.ts',
    "resources/js/pages/{$page['component']}.vue",
    ...(panel()?->getAssets() ?? []),
])
```

`panel()` is null outside a panel, so the spread contributes nothing there. Inside a panel it contributes that panel's list and no other's. This line is part of the application's root view rather than something the package injects, because the root view is the application's file — see [Inertia root view](../troubleshooting/inertia-root-view.md) if yours does not have it.

The order matters: the application's entrypoints load first, so a panel stylesheet can override what `app.css` established rather than being overridden by it.

## Where this lands

| Request | Panel entrypoints emitted |
| --- | --- |
| `/admin` and every page under it | `admin`'s |
| `/app` and every page under it | `app`'s |
| `/` and any non-panel page | none |

The list itself never crosses to the frontend. `toSharedArray()` has no `assets` key: the browser gets the tags, not what produced them.

## A per-panel stylesheet

The most common use is styling that CSS custom properties alone cannot express — anything scheme-dependent, since `colors(dark: ...)` is serialized but inline styles cannot express "only under `.dark`":

```css
/* resources/css/panels/admin.css */
@import 'tailwindcss';

.panel-shell {
    --primary: #4f46e5;
}

.dark .panel-shell {
    --primary: #818cf8;
}

.panel-topbar {
    border-bottom-width: 2px;
}
```

Every part of the shell carries a stable `panel-*` class whether or not the panel configured anything, which is what a stylesheet targets. The full list is in [Branding](branding.md#css-hooks).

Classes added through `cssHooks()` are a different mechanism and have a different failure mode: a class written in a PHP provider is not in any file Tailwind scans, so it must either already appear somewhere Tailwind reads or the provider has to be added to the content globs. A stylesheet loaded this way has no such problem, because Vite compiles it.

## Notes

- Entrypoints are paths, not built files. A path that is not in `vite.config.ts`'s `input` throws a Vite manifest exception on the first page of that panel.
- Run `npm run build` (or keep `npm run dev` running) after adding one. Nothing about this is resolved at request time except the list itself.
- `assets()` accumulates and never removes. There is no method to clear the list; a panel that should not load something must not declare it.
- This is unrelated to `php artisan vendor:publish --tag=panda-panel-assets`, which publishes the panel's Vue components into the application, and to `php artisan panel:assets`, which reports which of those published files are behind. See [Updating Assets](../frontend/updating-assets.md).
- Panel entrypoints are emitted on the panel's own auth pages too, because those routes carry the panel prefix and resolve the panel — a login page keeps the panel's look.

## See also

- [Branding, Logo, Icon, Favicon](branding.md)
- [Sidebar and Header Layouts](layouts.md)
- [Defining a Panel](defining-panels.md)
- [Frontend Assets](../concepts/frontend-assets.md)
- [Assets](../frontend/assets.md)
- [Tailwind Theme](../frontend/tailwind-theme.md)
- [Updating Assets](../frontend/updating-assets.md)
- [panel:assets](../cli/panel-assets.md)
- [Vite problems](../troubleshooting/vite.md)
