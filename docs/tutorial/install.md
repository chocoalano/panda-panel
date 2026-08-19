# 3 · Install Panda Panel

**Goal:** a panel registered at `/admin`, and a frontend that builds — plus the ability to read the
installer's report, which is the single most useful skill in this tutorial.

## Do this

```bash
composer require chocoalano/panel
php artisan panel:install
npm install
npm run build
```

That is the whole install. The rest of this page explains what each part did, because when
something is wrong later, this is where the answer usually is.

## What `composer require` does on its own

The package declares its service provider and facade, so Laravel's package discovery wires both
without an edit from you:

```json
"extra": {
    "laravel": {
        "providers": ["PandaPanel\\PandaPanelServiceProvider"],
        "aliases": { "PandaPanel": "PandaPanel\\Facades\\PandaPanel" }
    }
}
```

At boot the service provider registers the container bindings, the panels named in
`config/panda-panel.php` (none yet), four middleware aliases, the guest redirect, the route groups,
the package migrations, the publish tags and the artisan commands.

**No panel exists yet, so nothing answers a URL.** That is what the next command is for.

## What `panel:install` does, step by step

Six steps, in this order. Every one of them is available on its own — `vendor:publish` by tag,
`make:panel`, `panel:user` — so nothing here is a step you cannot repeat or take by hand.

### 1. Publish the config and the frontend

```bash
php artisan vendor:publish --tag=panda-panel-config    # config/panda-panel.php
php artisan vendor:publish --tag=panda-panel-assets    # the Vue components
```

Seven sources are copied into your application:

| From the package | Into your application |
| --- | --- |
| `resources/js/panel` | `resources/js/panel` |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

The frontend is *published* rather than imported from vendor because every component registry is an
`import.meta.glob` allowlist over your own tree: a component the build never saw is a component
that cannot resolve. These files are now yours — in your repository, in your build, and editable.

`vendor:publish` never overwrites an existing file without `--force`, so the starter kit's own
`resources/js/components/*` survive untouched.

The installer then writes `.panel-assets.json`, which records what each file looked like when it
was published. **Commit that file.** Without it, a future upgrade cannot tell your edits from a
stale copy.

### 2. Scaffold the panel

```bash
php artisan make:panel Admin
```

```text
app/Panels/Admin/
├── AdminPanelProvider.php
├── Pages/.gitkeep
├── Resources/.gitkeep
└── Widgets/.gitkeep
```

The `.gitkeep` files are not decoration — discovery scans those directories, and Git does not track
an empty one, so without them the provider would point at paths that vanish on clone.

Here is the provider it wrote, which is worth reading line by line since you will edit it in step 7:

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
            ->path('admin')                                          // the URL prefix
            ->name('Admin')                                          // the display name
            ->icon('layout-grid')                                    // a Lucide icon key
            ->auth()                                                 // adds auth + verified middleware
            ->discoverResources(app_path('Panels/Admin/Resources'))  // scanned for Resource classes
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

The panel **id** is derived from the class basename: `AdminPanelProvider` → `admin`. That id is
what every route name, every `--panel=` option and every middleware parameter refers to.

### 3. Register it in the config

This is the step that makes the URL answer:

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

Panels are **listed rather than discovered**, for two reasons: the application declares its panel
set in one place, and adding a panel should be a deliberate edit rather than a filesystem side
effect. When the request does not name a panel, Panda Panel walks panels by id, not by config order.
The classes *inside* a panel are discovered.

The installer edits the file textually — the same line you would write, in the same place — because
the config is mostly comments explaining each key, and re-emitting it from a parsed array would
throw all of them away. It reports one of four outcomes:

| Outcome | Meaning |
| --- | --- |
| `registered` | The line was written, or the shipped commented-out placeholder was uncommented |
| `already-present` | A live entry already exists; nothing was written |
| `no-config` | `config/panda-panel.php` does not exist — add the line yourself |
| `unrecognised` | The `panels` array is not in the shape the package ships. The file is left untouched |

::: warning This is the most common "it did not work"
Without this line the install finishes, the provider exists, and `/admin` returns **404**. If you
ever see that, check this array first.
:::

### 4. Report the home redirect

```text
Signed-in visitors to /dashboard now land in the panel. Set home_redirect.enabled to false
in config/panda-panel.php to keep your own.
```

Printed, not asked. It is the one thing installing this package changes about a screen your
application already had — a redirect nobody was told about is a bug report. Your route, its name
and `pages/Dashboard.vue` are untouched; the request is simply answered earlier by a middleware.

### 5. Check the frontend

Six read-only checks: Inertia, Vite, the dependency manifest, npm packages, the layout assignment
from step 2, and the `@/…` host modules. Each failure joins an outstanding list rather than
printing where it happens.

### 6. Offer to create a user

```text
Create a user who can sign in? (yes/no) [no]
```

Offered rather than assumed — creating a row in somebody's user table uninvited is not a thing an
installer should do. Say **no** for now; step 4 covers this command properly.

## Reading the report

Two possible endings. The good one:

```text
Done. Nothing is left to do by hand.
```

And the useful one:

```text
WARN  2 thing(s) this package cannot do for your application:

  1. Install the npm dependencies the components import, then rebuild:
     npm install @tanstack/vue-table@^9.0.0 vue-sonner@^2.0.0
     npm run build
  2. resources/js/app.ts line 12 overwrites the layout every panel page declares:
     page.default.layout = AppLayout;
     Use ??= or ||= instead.
```

Everything on that list is copy-pasteable. The npm line names only the packages *your* project is
missing, read from the package's own `package.json` — so it cannot go stale relative to what the
components actually import.

::: tip The exit code is always 0
Outstanding work is reported as work, not as failure. A shell that treated "you still need to run
`npm install`" as a broken build would stop your deploy for the wrong reason. If you script around
this command, test its output rather than its status.
:::

## Build the frontend

```bash
npm install
npm run build
```

**Installing does not build.** The panel's components are Vue; `npm run build` — or `npm run dev`
while you work — is what turns them into something a browser loads. A panel URL that renders
unstyled HTML is almost always a build that has not run.

## Check it worked

```bash
php artisan route:list --name=panel.
```

You should see `panel.admin.dashboard` at `/admin`, plus the panel's action, notification, search
and settings routes. Then:

```bash
php artisan panel:cache    # discovery once, reported by count
php artisan panel:clear    # and back to discovering per request
```

`panel:cache` prints how many panels, resources, pages and widgets were discovered. Right now that
is one panel and zero of everything else — which is correct, and which is exactly the number to
compare against after step 5.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| `composer require` refuses | A constraint from [step 1](prepare) is unmet | Read composer's message — it names the package and the version |
| `/admin` 404s | The provider is not in `panels` | Add the line in `config/panda-panel.php` |
| `/admin` renders unstyled HTML | The frontend was never built | `npm run build` |
| Every panel screen 500s | No Inertia root view or middleware | `php artisan inertia:middleware` |
| Panel screens render inside *your* sidebar | The layout assignment in `app.ts` | Change `=` to `??=` |

## Next

The panel exists. Nobody can sign into it yet.

**→ [4 · First account, first login](first-login)**

## See also

- [Installation](/getting-started/installation) — the same steps, done by hand
- [Running panel:install](/getting-started/installer) — every option and every outcome
- [Common install problems](/getting-started/common-install-problems)
- [Publish tags](/cli/publish-tags)
