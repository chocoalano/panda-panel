# `panel:install`

The one command a fresh install runs. It publishes the config and the frontend,
gives the application what an Inertia + Vue build needs where it has none,
scaffolds a first panel, registers it, installs the npm dependencies the
components import, and offers to create an account that can sign in. Reach for
it once, immediately after `composer require`.

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
    {--no-scaffold : Do not write any of the application files a panel needs}
    {--npm : Install the npm dependencies without asking}
    {--no-npm : Never run npm}
    {--force : Overwrite files that already exist}
```

| Option | Default | Effect |
| --- | --- | --- |
| `--panel=` | `Admin` | The name handed to `make:panel`. Studly-cased there, so `admin` and `Admin` are the same panel. |
| `--no-panel` | off | Publishes and configures and scaffolds nothing. The panel steps do not run at all, and the user offer has no panel to check the account against. |
| `--no-user` | off | Skips the account offer entirely, prompt included. |
| `--no-scaffold` | off | Skips every application file. Nothing is written outside the publish tags, and what is missing is reported instead. |
| `--npm` | off | Runs `npm install` and `npm run build` without prompting, including under `--no-interaction`. |
| `--no-npm` | off | Never runs a package manager. The exact `npm install …` line is reported instead. |
| `--force` | off | Passed to both `vendor:publish` and `make:panel`. Every published file is overwritten. |

```bash
php artisan panel:install
php artisan panel:install --panel=Support
php artisan panel:install --no-panel
php artisan panel:install --panel=Admin --no-user --no-interaction
php artisan panel:install --npm --no-interaction     # scripted, and builds
php artisan panel:install --force
```

### Under `--no-interaction`

Every step that would prompt takes its safe answer, and "safe" is decided per
step rather than once:

| Step | Non-interactive answer |
| --- | --- |
| Write a file that is not there | **yes** — nothing can be lost |
| Publish Inertia's middleware | **yes** |
| Write host-module stand-ins | **yes** |
| Replace a `vite.config.js` that cannot build the panel | **no** — reported instead |
| Publish the migrations | **no** |
| `npm install` / `npm run build` | **no** unless `--npm` |
| Create a user | **no** |

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

### 2. Give the application what it needs

Skipped entirely under `--no-scaffold`. Everything here is written **only where
there is nothing**, so an application that already has an entrypoint keeps it.

```php
use PandaPanel\Support\Installer\ApplicationScaffold;

ApplicationScaffold::missing();     // ['resources/views/app.blade.php', 'resources/js/app.ts', …]
ApplicationScaffold::write('resources/js/app.ts');   // false if it is already there
```

| File | Why a panel needs it |
| --- | --- |
| `resources/views/app.blade.php` | Every panel screen is an Inertia response, and Inertia needs a root view with `@inertia` |
| `resources/js/app.ts` | The entrypoint that mounts them. Deliberately does **not** assign `page.default.layout` — see step 6 |
| `resources/css/app.css` | Imports the published `panda-panel.css`, which is where Tailwind and the whole token set come from |
| `vite.config.ts` | The Vue plugin, and the `@` alias every published component imports through |

An `app.css` that was already there is not replaced. It gains one line, inserted
after the imports already in the file because CSS requires every `@import`
first:

```css
@import 'tailwindcss';
@import './panda-panel.css';    /* added */
```

`panda-panel.css` imports Tailwind itself, so if your `app.css` had its own
`@import 'tailwindcss';` you can delete that line — the installer says so rather
than deleting it for you.

**A `vite.config.js` is a special case.** `laravel new` ships one with neither
the Vue plugin nor the alias, and Vite reads `.js` *before* `.ts` — so a
`vite.config.ts` written beside it would never be read, and the build would fail
for a reason nothing on disk explains. It is moved aside rather than edited, and
only after asking:

```text
Replace vite.config.js with a vite.config.ts that can build the panel? (yes/no) [yes]
❯ Yours has no Vue plugin and no '@' alias, and Vite reads it first. It is kept as
  vite.config.js.bak.
```

Inertia's middleware is two steps, and the second is the one that makes it run:
`php artisan inertia:middleware` writes the class, and
`PandaPanel\Support\Installer\InertiaMiddlewareRegistrar` adds it to the `web`
group in `bootstrap/app.php`.

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
})
```

| Constant | When | Reported as |
| --- | --- | --- |
| `InertiaMiddlewareRegistrar::REGISTERED` | The line was written | `Added Inertia's middleware to the web group…` |
| `InertiaMiddlewareRegistrar::ALREADY_PRESENT` | The file already mentions it | nothing |
| `InertiaMiddlewareRegistrar::NO_MIDDLEWARE` | The class was not published | nothing — the earlier step reports it |
| `InertiaMiddlewareRegistrar::NO_BOOTSTRAP` | There is no `bootstrap/app.php` | outstanding work |
| `InertiaMiddlewareRegistrar::UNRECOGNISED` | `withMiddleware()` is not in the shape Laravel ships | outstanding work, file untouched |

`bootstrap/app.php` is the most rewritten file in a Laravel application and the
one that decides whether it boots. A pattern match that missed is a report,
never a guess.

### 3. Fill the host seam

The published components import nineteen modules they do not ship. Half are
generated, half belong to a starter kit, and a blank application has none of
them — which used to be a build error and a list of nineteen files to write by
hand.

`php artisan wayfinder:generate` runs first when Wayfinder is installed, because
`@/routes/*` and `@/actions/*` are generated from your own route table and a real
module beats a stand-in every time. Whatever is still missing gets the stand-in
this package ships, copied out of `frontend/host/`:

```php
ApplicationScaffold::missingHostModules();   // ['resources/js/components/Heading.vue' => '/…', …]
ApplicationScaffold::writeHostModules();     // what it wrote
```

They are minimal and correctly typed — they resolve, they type-check, and they
are not a design. `components/*` are yours to style; `routes/*` and `actions/*`
are overwritten the moment you run `wayfinder:generate`.

Existence is checked the way a bundler resolves, so a starter kit that writes a
component as `components/UserInfo/index.vue` or its shared types as
`types/index.d.ts` keeps both. Nothing runs at all until the frontend has been
published — a stand-in is only ever needed by a component that imports it.

Wayfinder is **not** installed for you: `composer require` inside a running
artisan command changes the autoloader underneath the process using it. It is
named in the outstanding list instead.

### 4. Scaffold the panel

Calls [`make:panel`](make-panel.md) with `--panel`'s value and your `--force`.
Skipped under `--no-panel`.

### 5. Register it in config

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

### 6. Report the redirects

```text
Signing in at a panel now lands in that panel rather than on fortify.home. Set login_redirect
to false in config/panda-panel.php to keep your own.
Signed-in visitors to /dashboard now land in the panel. Set home_redirect.enabled to false
in config/panda-panel.php to keep your own.
```

Printed, not asked: these are the two things installing this package changes
about screens the application already had. Each is silent when its own key is
off. See [`login_redirect`](../configuration/panda-panel.md#login_redirect) and
[`home_redirect`](../configuration/home-redirect.md).

### 7. Install the npm dependencies

The packages the published components import, plus the build-time ones the
scaffolded Vite config needs. The exact command is shown before it runs:

```text
Install the 17 npm package(s) the panel components import? (yes/no) [yes]
❯ npm install @inertiajs/vue3@^3.0.0 \
    @lucide/vue@^1.31.0 \
    …
```

Answering yes runs it and then offers `npm run build`. Answering no — or
`--no-npm`, or no `npm` on `PATH`, or a run that does not finish — reports the
literal command instead. A failed `npm install` never fails the install: an
installer whose last act is to abort over a network timeout has thrown away the
six things it already did.

Forced in either direction with `--npm` and `--no-npm`, which is what a scripted
run should use. Without either, a non-interactive run does not touch the
network.

### 8. Check what is left

Six read-only checks, through
`PandaPanel\Support\Installer\FrontendRequirements`. Most of what they used to
report is now written rather than named, so what surfaces here is the genuinely
outstanding: a step you declined, a package manager that is not installed, and
the one thing that cannot be fixed from inside this package at all.

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

### 9. Offer a user

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
WARN  2 thing(s) this package cannot do for your application:

  1. Wayfinder is not installed, so `@/routes/*` and `@/actions/*` are stand-ins rather
     than generated from your own routes:

       composer require laravel/wayfinder --dev
       php artisan wayfinder:generate

     Run it again after every route change — the panel's links come from it.

  2. resources/js/app.ts line 12 overwrites the layout every panel page declares:
     …
```

Collected as it goes and printed once. An install that interleaves five
successes with three warnings is an install whose warnings are read as noise.

## What it does not do

| | Why |
| --- | --- |
| `composer require` anything | Changing the autoloader underneath the process that is using it is not a thing to find out about during an install. Wayfinder is named, not installed. |
| `php artisan migrate` | Standard Laravel. Run it when you are ready. |
| Overwrite any file you already have | Every write is to a path where there was nothing. The two exceptions are named and both ask first: an `app.css` gains one `@import`, and a `vite.config.js` that cannot build Vue is moved to `.bak`. |
| Edit `resources/js/app.ts` | If it wrote the file, it wrote a correct one. If you wrote it, it reports the one shape that is wrong and leaves it alone. |
| Register the guest redirect or the login redirect | Already done by the service provider, under `register_guest_redirect` and `login_redirect`. |
| `npm install` without asking | Unless `--npm`. It writes to `node_modules`, touches the lockfile and reaches the network. |

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

- [Running panel:install](../getting-started/installer.md) — the same steps, at length
- [Installation](../getting-started/installation.md) — doing it by hand
- [Frontend requirements](../getting-started/frontend-requirements.md)
- [Creating the first user](../getting-started/first-user.md), [Opening your first panel](../getting-started/first-panel.md)
- [Common install problems](../getting-started/common-install-problems.md)
- [make:panel](make-panel.md), [panel:user](panel-user.md), [panel:assets](panel-assets.md)
- [Publish tags](publish-tags.md)
- [Home redirect](../configuration/home-redirect.md), [Guest redirect](../configuration/guest-redirect.md)
- [Migrations](../configuration/migrations.md)
