# `make:panel-page`

Generates a standalone panel page — a screen that belongs to a panel but is not
one of a resource's CRUD pages. Reach for it for a settings screen, a second
dashboard, a report, or anything else that needs a URL, a navigation entry, and
its own props.

```bash
php artisan make:panel-page Reports --panel=Admin
```

```text
INFO  Created [app/Panels/Admin/Pages/Reports.php]
```

The panel's `discoverPages()` path already covers `app/Panels/Admin/Pages`, so
`/admin/reports` answers on the next request and a "Reports" entry appears in
the sidebar.

## Signature

```text
make:panel-page
    {name : The page class name}
    {--panel= : The panel it belongs to}
    {--component : Also generate a Vue component for the page}
    {--force}
```

| Argument / option | Default | Effect |
| --- | --- | --- |
| `name` | required | Studly-cased. `reports`, `Reports` and `report-summary` all become valid class names. |
| `--panel=` | required | The panel to generate into, studly-cased. Omitting it fails the command. |
| `--component` | off | Sets `$component` to this page's own Vue file and generates that file. |
| `--force` | off | Overwrite files that already exist. |

```bash
php artisan make:panel-page Reports --panel=Admin
php artisan make:panel-page Settings --panel=Admin --component
php artisan make:panel-page Settings --panel=Admin --component --force
```

## The generated page

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class Reports extends Page
{
    protected static ?string $navigationIcon = 'file-text';

    protected static int $navigationSort = 0;

    protected static string $component = 'panel/Page';

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [];
    }
}
```

Everything else is inherited and derived:

| Behaviour | Where it comes from | Result for `Reports` |
| --- | --- | --- |
| URL slug | `Page::slug()`, kebab of the class basename | `reports` |
| Route path | `Page::routePath()`, the slug under the cluster prefix when there is one | `reports` |
| Route name | `Page::routeName()` | `panel.admin.pages.reports` — read it with `Reports::routeName()` |
| Title | `Page::title()`, `Str::headline()` of the class basename | `Reports` |
| Heading | `Page::heading()`, the title unless overridden | `Reports` |
| Access | `Page::canAccess()` | `true` — override to restrict |

```php
use App\Panels\Admin\Pages\Reports;

Reports::slug();       // 'reports'
Reports::url();        // '/admin/reports'
Reports::routeName();  // 'panel.admin.pages.reports'
```

## Without `--component`: the generic renderer

`$component = 'panel/Page'` points at the component the package publishes at
`resources/js/pages/panel/Page.vue`. It renders the page heading and subheading,
the header actions and the widgets, inside `PanelLayout` — which draws the
breadcrumbs from the same page metadata. A page filter bar is the one thing it
does not draw: `filterSchema()` controls are rendered by the dashboard
component, so a standalone page that wants them on screen needs its own
`--component`.

A page with nothing bespoke to draw therefore needs no Vue file at all. That is
the default because most standalone pages are a heading and some widgets:

```php
use App\Panels\Admin\Widgets\UserStats;
use PandaPanel\Pages\Page;

final class Reports extends Page
{
    protected static ?string $navigationIcon = 'chart-line';

    protected static string $component = 'panel/Page';

    /**
     * @return list<class-string<\PandaPanel\Widgets\Widget>>
     */
    public function widgets(): array
    {
        return [UserStats::class];
    }
}
```

## With `--component`: your own Vue file

```bash
php artisan make:panel-page Settings --panel=Admin --component
```

```text
INFO  Created [app/Panels/Admin/Pages/Settings.php]
INFO  Created [resources/js/pages/Panels/Admin/Pages/Settings.vue]
```

The PHP side changes in exactly one place:

```php
protected static string $component = 'Panels/Admin/Pages/Settings';
```

and the Vue file is a working starting point:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';

defineProps<{
    page: PageMetadata;
}>();
</script>

<template>
    <Head :title="page.title" />

    <div class="flex flex-col gap-6">
        <PageHeader :heading="page.heading" :subheading="page.subheading" />
    </div>
</template>
```

The component name is the path below `resources/js/pages/`, without the
extension, which is how Inertia resolves a page component. `Panels/Admin/Pages/Settings`
therefore means `resources/js/pages/Panels/Admin/Pages/Settings.vue`.

## Passing your own props

`props()` is merged into the Inertia response alongside the framework's own
`page`, `widgets`, `widgetData` and `filters` keys:

```php
use App\Models\User;
use PandaPanel\Pages\Page;

final class Settings extends Page
{
    protected static string $component = 'Panels/Admin/Pages/Settings';

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'userCount' => User::query()->count(),
        ];
    }
}
```

```vue
<script setup lang="ts">
import type { PageMetadata } from '@/panel/types/page';

defineProps<{
    page: PageMetadata;
    userCount: number;
}>();
</script>
```

Serializable values only — the props cross to the browser as JSON.

## Where the Vue file goes

`resources/js/pages/Panels/{Panel}/Pages/{Class}.vue`, and the `Panels` segment
is configurable:

```php
// config/panda-panel.php

'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

`pages_path` is read through `PandaPanel\Support\FrontendPaths::pages()`, which
both this generator and `make:panel-widget` use. It is also the root of the
`import.meta.glob` allowlists the frontend resolves component names through, so
moving it means changing those globs too.

## Custom stubs

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

| Stub | Written to | Placeholders |
| --- | --- | --- |
| `stubs/panel/page.stub` | `app/Panels/{Panel}/Pages/{Class}.php` | `panel`, `class`, `component` |
| `stubs/panel/page-component.stub` | `resources/js/pages/Panels/{Panel}/Pages/{Class}.vue` | none |

The page-component stub takes no placeholders — it is copied verbatim.

## Exit codes

| Outcome | Code |
| --- | --- |
| At least one file created | `0` |
| Every file already existed and was skipped | `1` |
| `--panel` missing | `1`, with `The --panel option is required.` |

## Gotchas

- **The page is discovered, not registered.** It must be under a directory the
  panel's `discoverPages()` names. A page moved elsewhere vanishes silently.
- **A cached manifest hides a new page.** No route, no navigation entry, no
  error. Run `php artisan panel:clear`.
- **`--component` alone does not rebuild the frontend.** The generated `.vue`
  file is a new source file; run `npm run dev` or `npm run build` before the
  page will render it.
- **Removing `--component` later means editing `$component` by hand.** Deleting
  the Vue file is not enough — the PHP class still names it, and an unresolvable
  component name renders nothing.
- **The navigation icon must be in the icon registry.** The stub uses
  `file-text`; any name you write instead needs `php artisan panel:icons` before
  it will draw. An unregistered name renders nothing at all, with no error.
- **Two pages in one panel may not share a slug.** The slug comes from the class
  basename, so `Admin\Pages\Reports` and a cluster page also called `Reports`
  collide unless one sets `$slug`. A `$cluster` does not help: it prefixes the
  route path and leaves `slug()` alone, so `PageRegistry` still throws
  `duplicatePageSlug()` at boot.

## See also

- [make:panel](make-panel.md), [make:panel-widget](make-panel-widget.md)
- [panel:icons](panel-icons.md) — register the icon the page declares
- [panel:clear](panel-clear.md), [panel:cache](panel-cache.md)
- [Custom pages](../pages-navigation/custom-pages.md)
- [Page discovery](../pages-navigation/discovery.md), [Headings](../pages-navigation/headings.md)
- [Page authorization](../pages-navigation/authorization.md)
- [Clusters](../pages-navigation/clusters.md)
- [Frontend: custom pages](../frontend/custom-pages.md), [Inertia pages](../frontend/inertia-pages.md)
- [Widgets overview](../widgets/overview.md)
- [Frontend paths](../configuration/frontend-paths.md)
- [Publish tags](publish-tags.md)
