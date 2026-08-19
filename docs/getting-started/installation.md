# Installation

Getting Panda Panel into an application: the package, the config, the frontend, a first panel,
and an account that can sign in. On a Laravel Vue starter kit application this is two commands
and a build.

## The short version

```bash
composer require chocoalano/panel
php artisan panel:install
npm install
npm run build
php artisan serve
```

Then open `/admin`. `panel:install` scaffolds the panel, registers it, and finishes by naming
anything it could not do for you — on a starter kit application, that list is usually empty.

## What `composer require` does on its own

The package declares its service provider and facade in `composer.json`, so Laravel's package
discovery wires both without an edit:

```json
"extra": {
    "laravel": {
        "providers": ["PandaPanel\\PandaPanelServiceProvider"],
        "aliases": { "PandaPanel": "PandaPanel\\Facades\\PandaPanel" }
    }
}
```

At boot, `PandaPanel\PandaPanelServiceProvider` registers the container bindings, the panels named
in `config/panda-panel.php` (none yet), four middleware aliases, the `web` middleware, the guest
redirect, the route groups, the package migrations, the publish tags and the artisan commands. No
panel exists yet, so nothing answers a URL.

## Step by step

`panel:install` runs these in order. Every one is available on its own, so nothing it does is a
step you cannot take by hand or repeat.

### 1. Publish the config

```bash
php artisan vendor:publish --tag=panda-panel-config
```

Writes `config/panda-panel.php`. The file is mostly comments explaining each key; the keys
themselves are in the [configuration reference](../configuration/panda-panel.md).

The package merges its own copy of this config at register time, so every key has a value even
before you publish. Publishing matters for one key in particular: `panels` is the list a panel has
to appear in before its URL answers.

### 2. Publish the frontend

```bash
php artisan vendor:publish --tag=panda-panel-assets
```

Seven sources, listed once in `PandaPanel\Support\Installer\PublishedAssets::map()` and read by
both `vendor:publish` and `panel:assets`:

| From the package | Into your application |
| --- | --- |
| `resources/js/panel` | `resources/js/panel` (configurable — `frontend.panel_path`) |
| `resources/js/components` | `resources/js/components` |
| `resources/js/composables` | `resources/js/composables` |
| `resources/js/lib` | `resources/js/lib` |
| `resources/js/pages` | `resources/js/pages` |
| `resources/js/types` | `resources/js/types` |
| `resources/css/panda-panel.css` | `resources/css/panda-panel.css` |

The frontend is published rather than imported from the package because every component registry
is an `import.meta.glob` allowlist over the application's own tree: a component the build never
saw is a component that cannot resolve. Published files are yours — in your repository, in your
build, and editable.

`vendor:publish` never overwrites an existing file without `--force`, so a starter kit's own
`resources/js/components/*` survive the publish untouched.

### 3. Publish the migrations, or do not

```bash
php artisan vendor:publish --tag=panda-panel-migrations
```

Optional, and off by default in the installer's prompt. The migrations already run from the
package; publishing is about *ownership*, not about the tables existing. If you publish them, set
`load_migrations` to `false` — a published copy and a package copy of the same migration is a
schema applied twice.

```bash
php artisan migrate
```

### 4. Scaffold the first panel

```bash
php artisan make:panel Admin
php artisan make:panel Admin --path=back-office
```

Writes `app/Panels/Admin/AdminPanelProvider.php` and the three directories discovery scans, each
with a `.gitkeep` — an empty directory is not tracked by Git, so without them the provider would
point at paths that vanish on clone.

```text
app/Panels/Admin/
├── AdminPanelProvider.php
├── Pages/.gitkeep
├── Resources/.gitkeep
└── Widgets/.gitkeep
```

### 5. Register it

Panels are listed rather than discovered: the config is the explicit set of panels the application
has. When the request does not name one, Panda Panel walks panels by id, not by config order, so the
landing panel stays stable and adding a panel remains a deliberate edit rather than a filesystem
side effect.

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

`panel:install` writes this line for you — see [Running panel:install](installer.md) for the three
outcomes and what happens when the config has been restructured. `make:panel` on its own only
prints it, because a generator editing config silently is how a project loses track of which panels
are enabled.

**Without this step the install finishes, the provider exists, and the panel's URL 404s.** It is
the single most common "it did not work" after an install.

### 6. Install the npm dependencies and build

```bash
npm install
npm run build
```

The exact list your project is missing is printed by `panel:install`, read from the package's own
`package.json`. See [Frontend requirements](frontend-requirements.md) for the whole list and for
the modules under `@/` that your application owns rather than the package.

### 7. Create a user who can sign in

```bash
php artisan panel:user
php artisan panel:user --name=Ada --email=ada@example.com --password=correct-horse --panel=admin
```

The account is created through the auth guard's own user model, not a model this command names.
See [Creating the first user](first-user.md).

## Publish tags in full

| Tag | Publishes |
| --- | --- |
| `panda-panel-config` | `config/panda-panel.php` |
| `panda-panel-assets` | The frontend, as in the table above |
| `panda-panel-migrations` | `database/migrations` |
| `panda-panel-stubs` | `stubs/panel` — the templates every generator reads |
| `panda-panel` | Config, migrations and assets together. **Not** the stubs. |

Publishing the stubs is how a project changes what its generators write; the application's copy
always wins, and the package's is used until there is one.

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

## Verify the install

```bash
php artisan route:list --name=panel.
php artisan panel:cache        # discovery once, reported by count
php artisan panel:clear
```

`route:list` should show `panel.admin.dashboard` at `/admin` and, for a panel with `->auth()` and
`->login()`, its own guest routes under the same prefix. `panel:cache` prints how many panels,
resources, pages and widgets were discovered — a zero where you expected a number means a
discovery path that does not match where the classes actually are.

Signing in afterwards lands in the panel rather than on the starter kit's placeholder dashboard:
`/dashboard` redirects to the first panel the user can enter.

## Non-interactive installs

Both prompts — publish the migrations, create a user — are skipped when the input is not
interactive, so a scripted install never blocks on a pipe that will never answer.

```bash
php artisan panel:install --panel=Admin --no-user --no-interaction
php artisan panel:user --name=Ada --email=ada@example.com --password="$ADMIN_PASSWORD"
```

## Notes

- **`--force` republishes everything, including files you have edited.** After the first install,
  reach for [`panel:assets`](../cli/panel-assets.md) instead: it knows what you changed because
  `.panel-assets.json` records what each file looked like when it was published.
- **Commit `.panel-assets.json`.** It is the record of which version of the panel frontend this
  application published. Without it, an upgrade cannot tell your edits from a stale copy.
- **Installing does not build.** The components are Vue; `npm run build` (or `npm run dev`) is what
  turns them into something a browser loads. A panel URL that renders unstyled HTML is almost
  always a build that has not run.
- **A fresh resource 403s until its model has a policy.** The gate is asked and answers no. That is
  the intended default — a panel that showed every record because nobody had written a rule yet
  would be worse. In development the panel logs which model is missing one.

## See also

- [Running panel:install](installer.md) — every option, every step, and what it reports
- [Requirements](requirements.md) and [Compatibility](compatibility.md)
- [Frontend requirements](frontend-requirements.md) — npm packages, host modules, the build
- [Laravel Vue starter kit setup](vue-starter-kit.md)
- [Creating the first user](first-user.md)
- [Opening your first panel](first-panel.md)
- [Directory structure](directory-structure.md)
- [Common install problems](common-install-problems.md)
- [CLI: publish tags](../cli/publish-tags.md), [CLI: make:panel](../cli/make-panel.md)
- [Configuration reference](../configuration/panda-panel.md)
