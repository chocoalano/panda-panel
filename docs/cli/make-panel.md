# `make:panel`

Scaffolds a panel provider and the three directories discovery scans. Reach for
it when the application needs another panel beside the one `panel:install`
created — a customer-facing `App` next to the staff `Admin` — or when you are
building the first one by hand.

```bash
php artisan make:panel Admin
```

```text
INFO  Created [app/Panels/Admin/AdminPanelProvider.php]
INFO  Add App\Panels\Admin\AdminPanelProvider::class to 'panels' in config/panda-panel.php.
```

Do that one edit and the panel answers at `/admin`.

## Signature

```text
make:panel
    {name : The panel name, for example Admin}
    {--path= : The URL prefix, defaults to the kebab-cased name}
    {--force}
```

| Argument / option | Type | Default | Effect |
| --- | --- | --- | --- |
| `name` | argument, required | — | Studly-cased. `admin` and `Admin` both produce `Admin`, and `back-office` produces `BackOffice`. Only the first letter of each word is touched, so `aDmIn` produces `ADmIn`. |
| `--path=` | option | the kebab-cased name | The URL prefix written into the provider's `->path()`. |
| `--force` | flag | off | Overwrite a provider that already exists. |

```bash
php artisan make:panel Admin
php artisan make:panel Admin --path=back-office
php artisan make:panel BackOffice                 # path defaults to back-office
php artisan make:panel Admin --force
```

## What lands on disk

```text
app/Panels/Admin/
    AdminPanelProvider.php
    Resources/.gitkeep
    Pages/.gitkeep
    Widgets/.gitkeep
```

The three `.gitkeep` files exist because discovery scans those directories and
Git does not track an empty one. Without them a fresh clone has a provider
pointing at paths that are not there, and a panel that discovers nothing is a
panel with an empty sidebar and no error.

`.gitkeep` files are created quietly — they are not reported as created, and an
existing one is left alone regardless of `--force`.

## The generated provider

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Admin')
            ->icon('layout-grid')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

That is a complete, working panel: a path, a display name, a navigation icon,
authentication, and the three discovery paths. It has no resources and no pages
yet, and it still renders — every panel gets a dashboard route at its own path.

## Registering it

Registration is an explicit edit rather than something the generator does
behind your back. The configured list is where every panel this application has
is visible, and a provider missing from it has no routes at all. The order you
write them in is not significant: `PanelRegistry::all()` sorts by id, so the
panel a user lands in when the request does not name one is the one whose id
sorts first.

```php
// config/panda-panel.php

'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

`panel:install` does write this line for you, through
`PandaPanel\Support\Installer\PanelRegistrar`. `make:panel` on its own only
prints it.

Until the provider is listed there, nothing about the panel exists at runtime:
no routes, no navigation entry, and a `404` at its path.

## Name, id, path — three different things

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

$panel->getId();    // 'admin'   — from the provider class name
$panel->getName();  // 'Admin'   — what the sidebar shows
$panel->getPath();  // 'admin'   — the URL prefix
```

| Value | Where it comes from | Changed by |
| --- | --- | --- |
| id | `PanelProvider::panelId()`, which is `class_basename()` minus `PanelProvider`, kebab-cased | renaming the provider class, or `->id()` in `panel()` |
| name | `->name('Admin')` in the generated provider | editing that call |
| path | `->path('admin')`, seeded from `--path` | editing that call |

`AdminPanelProvider` becomes the id `admin`; `BackOfficePanelProvider` becomes
`back-office`. The id is what `panel:user --panel=`, `panel:plugins --panel=`
and route names all use, and it is derived from the class name rather than from
`--path`, so renaming the URL does not rename the panel.

## Not overwriting

A file that exists is skipped and reported:

```text
WARN  Skipped [app/Panels/Admin/AdminPanelProvider.php] because it already exists. Use --force to overwrite.
```

`--force` overwrites it. There is no merge and no backup — a provider you have
edited is gone, so commit before forcing.

## Custom stubs

The generator reads `stubs/panel/panel-provider.stub` from the application when
it exists, and the package's copy otherwise. Publish to take ownership:

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

That writes every generator stub into `stubs/panel/`. The provider stub
supports two placeholders:

| Placeholder | Value |
| --- | --- |
| `{{ panel }}` | the studly panel name, `Admin` |
| `{{ path }}` | the URL prefix, `--path` or the kebab-cased name |

A published stub wins from then on, for this generator and every other one, so
a project that always adds `->brandName()` or a fixed navigation group writes it
once.

## Exit codes

| Outcome | Code |
| --- | --- |
| The provider was written | `0` |
| The provider already existed and was skipped | `1` |
| The provider was overwritten with `--force` | `0` |

The failure case is "nothing was created and something was skipped", which is
the only run where you asked for a file and did not get one.

## Gotchas

- **The panel is invisible until it is listed in config.** The generator prints
  the line; it does not write it. A scaffolded but unregistered panel 404s at
  its path, which is the most common "the generator did not work".
- **`--path=` with an empty value writes an empty path.** The default only
  applies when the option is absent, so `--path=` produces `->path('')` rather
  than the kebab-cased name.
- **A cached manifest does not hide a brand-new panel.** `PanelManifest::for()`
  falls back to discovery for a panel it holds no entry for, so a panel
  scaffolded after `panel:cache` ran still finds its own classes. It is the next
  cache that freezes them: once the panel is in the manifest, anything added
  under its discovery paths is invisible until `php artisan panel:clear`.
- **Two panels may not share a path.** `PandaPanel\Core\PanelRegistry` refuses
  the second registration for a path and domain pair at boot, with
  `PanelRegistrationException::duplicatePanelPath()`. Give each one its own
  prefix, or a domain.
- **The generator never touches `config/panda-panel.php`.** If you want that
  done for you, run [`panel:install`](panel-install.md), which registers the
  panel it scaffolds.

## See also

- [panel:install](panel-install.md) — scaffolds and registers a panel in one step
- [make:panel-resource](make-panel-resource.md), [make:panel-page](make-panel-page.md), [make:panel-widget](make-panel-widget.md)
- [panel:cache](panel-cache.md), [panel:clear](panel-clear.md)
- [Panels](../concepts/panels.md), [Panel Providers](../concepts/panel-providers.md)
- [Defining panels](../panels/defining-panels.md), [Ids, paths and domains](../panels/ids-paths-domains.md)
- [Multiple panels](../panels/multi-panel.md)
- [Discovery](../concepts/discovery.md)
- [Directory structure](../getting-started/directory-structure.md)
- [Publish tags](publish-tags.md)
