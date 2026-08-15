# Directory structure

Where everything lives after an install: the files the package puts in your application, the ones
the generators write, and the two paths that are configurable. Read this once and you will know
which directory a missing file was supposed to be in.

## What an install leaves behind

```bash
php artisan panel:install
git status --short
```

```text
config/panda-panel.php               # published config
app/Panels/Admin/…                   # the scaffolded panel
resources/js/panel/…                 # the panel's components, layouts, renderers
resources/js/pages/panel/…           # framework-generic Inertia pages
resources/js/pages/Panels/…          # your panel-specific pages, widgets, columns
resources/js/components/…            # UI primitives, published only where absent
resources/js/composables/…
resources/js/lib/…
resources/js/types/…
resources/css/panda-panel.css
.panel-assets.json                   # what you published, and what it looked like
```

Everything under `resources/js` is *yours* from the moment it is published: in your repository, in
your build, and editable. That is what the build-time component registries require — a component
the build never saw is a component that cannot resolve.

## The three frontend locations

The split is not a preference. `@inertiajs/vite` only globs `resources/js/pages/**`, so anything
Inertia must resolve by name lives there and everything else does not.

| Location | Role | Inertia-resolvable |
| --- | --- | --- |
| `resources/js/panel/**` | Layouts, components, renderers, composables, registries, types | no |
| `resources/js/pages/panel/**` | Framework-generic pages | yes |
| `resources/js/pages/Panels/{Panel}/**` | Application-specific pages, widgets and columns | yes |

### `resources/js/panel`

```text
resources/js/panel/
├── actions/       ActionButton, ActionGroup, ActionDialog
├── components/    PanelSidebar, PanelNavigation, PanelHeader, PanelBreadcrumb, PageHeader, …
├── composables/   usePanel, useNavigation, usePanelPage, useResource, useActions
├── forms/         FormRenderer, FormComponentRenderer, FormSection, FormGrid, FormField, fields/*
├── hooks/         render hook plumbing
├── icons/         registry.ts — the build-time icon allowlist
├── infolists/     InfolistRenderer and its entries
├── layouts/       PanelLayout, SidebarPanelLayout, HeaderPanelLayout, PanelBlankLayout
├── lib/           grid.ts and shared helpers
├── relations/     relation manager UI
├── shell/         the panel chrome
├── tables/        DataTable, DataTableToolbar, DataTableFilters, DataTablePagination, …
├── types/         panel, navigation, breadcrumb, page, table, form, action, widget, guards
└── widgets/       WidgetGrid, WidgetRenderer, StatsWidget, TableWidget, ChartWidget, CustomWidget
```

Nothing here is resolvable by Inertia, and nothing here needs to be: these are imported by the
pages, not named in a response.

### `resources/js/pages/panel`

The pages the framework itself renders. Seventeen files, and each one declares its own layout:

```text
resources/js/pages/panel/
├── Dashboard.vue                    panel/Dashboard
├── Page.vue                         panel/Page — the generic renderer
├── auth/
│   ├── EmailCode.vue  ForgotPassword.vue  Login.vue
│   ├── Register.vue   ResetPassword.vue   VerifyEmail.vue
├── resources/
│   ├── Create.vue  Edit.vue  Index.vue
│   ├── Integrations.vue  ManageRelated.vue  View.vue
└── settings/
    ├── Appearance.vue  Profile.vue  Security.vue
```

A page's component name is the path under `resources/js/pages` without the extension — a page
class that says `protected static string $component = 'panel/Page'` is asking for
`resources/js/pages/panel/Page.vue`.

### `resources/js/pages/Panels`

Your tree. The generators write into it, and the component registries glob it:

```text
resources/js/pages/Panels/
└── Admin/
    ├── Columns/AccountAge.vue     custom table cells
    ├── Hooks/Announcement.vue     render hook components
    ├── Pages/Settings.vue         make:panel-page --component
    └── Widgets/SystemInfo.vue     make:panel-widget --type=custom
```

A name that is not in the glob resolves to nothing. In development the panel warns once per
unknown name, naming the directory the component has to live in; in production it simply renders
nothing.

## The application's PHP

```text
app/Panels/
├── Admin/
│   ├── AdminPanelProvider.php
│   ├── Pages/
│   │   └── Settings.php
│   ├── Resources/
│   │   └── Users/
│   │       ├── UserResource.php
│   │       ├── Forms/UserForm.php
│   │       ├── Tables/UsersTable.php
│   │       ├── Infolists/UserInfolist.php
│   │       ├── Exports/UserExporter.php
│   │       ├── Imports/UserImporter.php
│   │       └── Pages/
│   │           ├── ListUsers.php  CreateUser.php  ViewUser.php  EditUser.php
│   └── Widgets/
│       ├── UserStats.php  RecentUsers.php  UserGrowth.php  SystemInfo.php
└── App/
    └── AppPanelProvider.php
```

That is what `make:panel-resource User --panel=Admin` produces, minus the infolist, exporter and
importer, which you add when you need them. The resource directory is named for the **plural**
studly form (`Users`), the class for the singular (`UserResource`).

Discovery reads class names from Composer's PSR-4 prefixes, so this layout is a convention with
one hard rule: the namespace has to match the path, or discovery finds nothing in it.

Nothing else in `app/` is touched. Policies stay in `app/Policies`, models in `app/Models`, and a
panel resource points at both.

## Where each generator writes

| Command | PHP | Vue |
| --- | --- | --- |
| `make:panel Admin` | `app/Panels/Admin/AdminPanelProvider.php`, plus `Pages/`, `Resources/`, `Widgets/` with `.gitkeep` | — |
| `make:panel-resource User --panel=Admin` | `app/Panels/Admin/Resources/Users/{UserResource,Forms/UserForm,Tables/UsersTable,Pages/*}.php` | — |
| `make:panel-page Reports --panel=Admin` | `app/Panels/Admin/Pages/Reports.php` (component `panel/Page`) | — |
| `make:panel-page Reports --panel=Admin --component` | as above, component `Panels/Admin/Pages/Reports` | `resources/js/pages/Panels/Admin/Pages/Reports.vue` |
| `make:panel-widget Revenue --panel=Admin --type=stats` | `app/Panels/Admin/Widgets/Revenue.php` | — |
| `make:panel-widget Health --panel=Admin --type=custom` | as above | `resources/js/pages/Panels/Admin/Widgets/Health.vue` |
| `make:panel-relation-manager posts --panel=Admin --resource=Users` | under the resource's directory | — |

A page without `--component` renders through the generic `panel/Page` component, so a page with
nothing bespoke to draw needs no Vue file at all. A `custom` widget is the exception where the
component is not optional: without it the widget would draw the fallback.

## The two configurable paths

```php
// config/panda-panel.php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Both are relative to `resources/`, and both are read through one class so that a path which could
be spelled differently in the publisher, the generators and the icon command is not:

```php
use PandaPanel\Support\FrontendPaths;

FrontendPaths::panel();                       // /app/resources/js/panel
FrontendPaths::panel('icons/registry.ts');    // /app/resources/js/panel/icons/registry.ts
FrontendPaths::pages();                       // /app/resources/js/pages/Panels
FrontendPaths::pages('Admin/Widgets/X.vue');  // /app/resources/js/pages/Panels/Admin/Widgets/X.vue
```

`pages_path` is also the root of the `import.meta.glob` the frontend resolves component names
through, so moving it means changing that glob too.

## Files that are not source

| Path | Written by | Commit it? |
| --- | --- | --- |
| `.panel-assets.json` | `panel:install`, `panel:assets` | **Yes.** It records which version of the panel frontend this application published, the way `composer.lock` records what it installed. Without it an upgrade cannot tell your edits from a stale copy. |
| `bootstrap/cache/panels.php` | `panel:cache` | No. A build artefact, holding class names only, rebuilt at deploy time. `panel:clear` removes it. |
| `stubs/panel/*.stub` | `vendor:publish --tag=panda-panel-stubs` | Yes, if you publish them. They are how a project changes what its generators write. |

The manifest sits at the application root deliberately: under `bootstrap/cache` it would be
regenerated and useless, and under `storage` it would be gitignored and lost on the first deploy.

## Inside the package

Useful when reading a stack trace. `PandaPanel\*` maps to `src/`:

| Namespace | What is in it |
| --- | --- |
| `PandaPanel\Core` | `Panel`, `PanelProvider`, `PanelManager`, `PanelRegistry` |
| `PandaPanel\Resources` | `Resource`, its pages, `ResourceConfiguration` |
| `PandaPanel\Tables`, `Forms`, `Infolists`, `Widgets`, `Actions` | The schema builders and their components |
| `PandaPanel\Pages` | `Page`, `Dashboard`, the three settings pages |
| `PandaPanel\Http` | Controllers and middleware |
| `PandaPanel\Routing` | `PanelRouteRegistrar` |
| `PandaPanel\Discovery`, `Cache` | Discovery and the manifest |
| `PandaPanel\Console\Commands` | Every artisan command |
| `PandaPanel\Support` | Helpers, `FrontendPaths`, `PanelContext`, and `Support\Installer\*` |
| `PandaPanel\Contracts` | `PanelUser`, `PanelNotifiable`, `HasPanelTenants`, `PanelPlugin` |
| `PandaPanel\Testing` | The shipped test helpers, autoloaded |

`docs/`, `examples/`, `tests/` and `frontend/` are `export-ignore`d, so `composer require` brings
none of them. Read them on GitHub; do not expect them under `vendor/`.

## Notes

- **`vendor:publish` into `resources/js/components` does not overwrite yours.** Without `--force`,
  existing files are skipped — which is the intended outcome on a starter kit that already has
  most of them.
- **An empty directory is not tracked by Git.** That is why `make:panel` writes `.gitkeep` into the
  three discovery directories; deleting them means a clone whose discovery paths do not exist.
- **Moving `panel_path` after publishing leaves the old copy behind.** `panel:assets` compares
  against the *configured* destination, so the stale tree reads as untracked rather than as
  removable.
- **`resources/js/pages/Panels` is case-sensitive on Linux.** The lower-case `panel` directory is
  the framework's pages; the capitalised `Panels` is yours. They are different directories and
  both exist.

## See also

- [Installation](installation.md) — the publish tags and what each one moves
- [Frontend requirements](frontend-requirements.md) — the published map and the host seam
- [Opening your first panel](first-panel.md)
- [Resources: directory convention](../resources/directory-convention.md)
- [Concepts: component registries](../concepts/component-registries.md),
  [frontend assets](../concepts/frontend-assets.md), [caching](../concepts/caching.md)
- [Configuration: frontend paths](../configuration/frontend-paths.md)
- [Frontend: custom pages](../frontend/custom-pages.md),
  [custom widgets](../frontend/custom-widgets.md),
  [custom columns](../frontend/custom-columns.md)
