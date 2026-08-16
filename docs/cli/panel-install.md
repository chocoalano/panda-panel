# `panel:install`

The one command a fresh install runs. It publishes the config and the frontend,
scaffolds a first panel, registers it, checks what the published components need
from your application, and offers to create an account that can sign in. Reach
for it once, immediately after `composer require`.

```bash
php artisan panel:install
```

Everything it does is available separately — `vendor:publish` by tag,
`make:panel`, `panel:user` — so nothing here is a step you cannot take by hand
or repeat. It exists because the order matters, and because an install that
stops one step short of working is the same as an install that failed.

## Signature

```text
panel:install
    {--panel=Admin : The name of the first panel to scaffold}
    {--no-panel : Publish and configure without scaffolding a panel}
    {--no-user : Skip the offer to create a signing-in account}
    {--force : Overwrite files that already exist}
```

| Option | Default | Effect |
| --- | --- | --- |
| `--panel=` | `Admin` | The name handed to `make:panel`. Studly-cased there, so `admin` and `Admin` are the same panel. |
| `--no-panel` | off | Publishes and configures and scaffolds nothing. Steps 2 and 3 do not run at all, and step 6 offers a user with no panel to check them against. |
| `--no-user` | off | Skips the account offer entirely, prompt included. |
| `--force` | off | Passed to both `vendor:publish` and `make:panel`. Every published file is overwritten. |

```bash
php artisan panel:install
php artisan panel:install --panel=Support
php artisan panel:install --no-panel
php artisan panel:install --panel=Admin --no-user --no-interaction
php artisan panel:install --force
```

## What it does, in order

### 1. Publish

```bash
php artisan vendor:publish --tag=panda-panel-config
php artisan vendor:publish --tag=panda-panel-assets
```

Both run with whatever `--force` you passed. Then the asset manifest is written:

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::write(AssetManifest::read());
```

`.panel-assets.json` records what each published file looked like when it was
published. That third value is what later lets [`panel:assets`](panel-assets.md)
tell a file you edited from one that has simply fallen behind. Commit it.

The migrations are offered rather than published:

```text
Publish the migrations into database/migrations? (yes/no) [no]
❯ They already run from the package. Publish only to own them, and set load_migrations
  to false in config/panda-panel.php if you do.
```

A published copy and a package copy of the same migration is a schema applied
twice. In a non-interactive run the answer is no.

### 2. Scaffold the panel

Calls [`make:panel`](make-panel.md) with `--panel`'s value and your `--force`.
Skipped under `--no-panel`.

### 3. Register it in config

The step that makes the panel's URL answer. It is a textual edit to
`config/panda-panel.php`, through
`PandaPanel\Support\Installer\PanelRegistrar`:

```php
use PandaPanel\Support\Installer\PanelRegistrar;

/**
 * @param  class-string  $provider
 * @param  string|null   $path  the config file, defaulting to config_path('panda-panel.php')
 * @return self::*
 */
PanelRegistrar::register('App\Panels\Admin\AdminPanelProvider');
```

| Constant | Value | When | Reported as |
| --- | --- | --- | --- |
| `PanelRegistrar::REGISTERED` | `registered` | The line was written, or the shipped commented placeholder was uncommented | `Registered … in config/panda-panel.php.` |
| `PanelRegistrar::ALREADY_PRESENT` | `already-present` | A live entry already exists | `That panel is already registered…` |
| `PanelRegistrar::NO_CONFIG` | `no-config` | `config/panda-panel.php` does not exist | outstanding work |
| `PanelRegistrar::UNRECOGNISED` | `unrecognised` | The `panels` array is not in the shape this package ships | outstanding work, file untouched |

### 4. Report the home redirect

```text
Signed-in visitors to /dashboard now land in the panel. Set home_redirect.enabled to false
in config/panda-panel.php to keep your own.
```

Printed, not asked: it is the one thing installing this package changes about a
screen the application already had. Silent when `home_redirect.enabled` is false
or the path list is empty.

### 5. Check the frontend

Six read-only checks, through
`PandaPanel\Support\Installer\FrontendRequirements`:

| Check | Method | What a failure means |
| --- | --- | --- |
| Inertia | `missingInertia(): list<string>` | No `resources/views/app.blade.php`, or no `app/Http/Middleware/HandleInertiaRequests.php`. Every panel screen is an Inertia response and will 500. |
| Vite | `hasVite(): bool` | No `vite.config.ts` or `.js`. The published components are Vue and have to be built by something. |
| npm manifest | `hasNpmManifest(): bool` | This package's own `package.json` did not reach the Composer archive, so the dependency list could not be read at all. Reported before the list itself, because "nothing missing" and "I could not look" are the same empty list. |
| npm dependencies | `missingNpmPackages(): list<string>` | Packages the components import that your `package.json` does not declare. Reported as a literal `npm install …` line. |
| Layout override | `layoutOverrides(): list<array{file: string, line: int, code: string}>` | Your Inertia entry assigns `page.default.layout` unconditionally. |
| Host modules | `missingHostModules(): list<string>` | `@/routes/*`, `@/actions/*` and starter-kit components the published files import. |

The layout check is the one worth understanding, because what it catches is
silent. Every panel page declares its own layout, and an unconditional
assignment in your entry file replaces it *after* the page asked:

```ts
page.default.layout = AppLayout;      // reported
page.default.layout ??= AppLayout;    // correct
page.default.layout ||= AppLayout;    // correct
```

Left as it is, every panel screen renders inside your application shell — your
sidebar, not the panel navigation — at HTTP 200, with nothing logged.

### 6. Offer a user

```text
Create a user who can sign in? (yes/no) [no]
```

Answering yes runs [`panel:user`](panel-user.md) with `--panel` set to the
lower-cased panel name, so the new account is checked against the panel that was
just scaffolded. Skipped under `--no-user`, and skipped when the input is not
interactive.

## What it reports at the end

```text
INFO  Done. Nothing is left to do by hand.
```

or

```text
WARN  3 thing(s) this package cannot do for your application:

  1. Install the npm dependencies the components import, then rebuild:

       npm install @inertiajs/vue3@^3.0.0 \
         @lucide/vue@^1.31.0 \
         …
       npm run build

  2. resources/js/app.ts line 12 overwrites the layout every panel page declares:
     …

  3. The published components import these modules, which belong to your application
     and are not there yet:
     …
```

Collected as it goes and printed once. An install that interleaves five
successes with three warnings is an install whose warnings are read as noise.

## What it does not do

| | Why |
| --- | --- |
| `npm install`, `npm run build` | It prints the exact line. Running a package manager from inside an artisan command is a side effect nobody asked for. |
| `php artisan wayfinder:generate` | Wayfinder runs against your routes. The installer names it when the generated modules are missing. |
| `php artisan migrate` | Standard Laravel. Run it when you are ready. |
| Register the guest redirect | Already done by the service provider, under `register_guest_redirect`. |
| Edit `resources/js/app.ts` | It reports the one shape that is wrong and leaves the file alone. |

## Exit code

Always `0`. Things it could not do are reported as outstanding work rather than
as a failure — a shell that treats "you still need to run `npm install`" as a
broken build stops your deploy for the wrong reason. Test the output, not the
status, if you script around it.

## Re-running it

Safe without `--force`: publishing skips files that exist, registration is a
no-op for a panel already listed, `make:panel` skips files it would overwrite
and says so, and the frontend checks are read-only.

With `--force` it is not safe in the same way. `vendor:publish --force`
overwrites every published file, including ones you have edited. After the first
install, upgrade the frontend with [`panel:assets`](panel-assets.md) instead,
which knows the difference.

## Gotchas

- **`--no-interaction` changes two answers, not the outcome.** Migrations are
  not published and no user is offered. Everything else runs identically.
- **`--no-panel` skips registration too.** There is no panel to register, so
  step 3 does not run and nothing is added to config.
- **A restructured `panels` array is never guessed at.** If yours is built from
  a variable, the installer leaves the file alone and tells you the line to add.
- **The npm list comes from this package's own `package.json`.** It cannot go
  stale relative to what the components import, because there is no second copy
  of it.
- **`.panel-assets.json` is written even when nothing was published.** It
  records what is on disk, so a re-run after a manual `vendor:publish` still
  produces a correct baseline.

## See also

- [Running panel:install](../getting-started/installer.md) — the same six steps, at length
- [Installation](../getting-started/installation.md) — doing it by hand
- [Frontend requirements](../getting-started/frontend-requirements.md)
- [Creating the first user](../getting-started/first-user.md), [Opening your first panel](../getting-started/first-panel.md)
- [Common install problems](../getting-started/common-install-problems.md)
- [make:panel](make-panel.md), [panel:user](panel-user.md), [panel:assets](panel-assets.md)
- [Publish tags](publish-tags.md)
- [Home redirect](../configuration/home-redirect.md), [Guest redirect](../configuration/guest-redirect.md)
- [Migrations](../configuration/migrations.md)
