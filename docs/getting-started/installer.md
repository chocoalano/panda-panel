# Running `panel:install`

The one command a fresh install runs. Everything it does is available separately — `vendor:publish`
by tag, `make:panel`, `panel:user` — so nothing here is a step you cannot take by hand or repeat.
It exists because the order matters, and because an install that stops one step short of working
is the same as an install that failed.

```bash
php artisan panel:install
```

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
| `--panel=` | `Admin` | The name passed to `make:panel`. Studly-cased there, so `admin` and `Admin` mean the same panel. |
| `--no-panel` | off | Publishes and configures, scaffolds nothing. Right for a second install into an application that already has its panels. |
| `--no-user` | off | Skips the account prompt entirely, prompt and all. |
| `--no-scaffold` | off | Writes none of the application files. What is missing is reported instead. |
| `--npm` | off | Runs `npm install` and `npm run build` without prompting, `--no-interaction` included. |
| `--no-npm` | off | Never runs a package manager; reports the exact line instead. |
| `--force` | off | Passed through to both `vendor:publish` and `make:panel`: existing files are overwritten. |

The command always returns `0`. Things it could not do are reported as outstanding work, not as a
failure — a shell that treats "you still need to run `npm install`" as a broken build is a shell
that stops your deploy for the wrong reason.

```bash
php artisan panel:install --panel=Support
php artisan panel:install --no-panel
php artisan panel:install --panel=Admin --no-user --no-interaction
php artisan panel:install --force
```

## The nine steps

### 1. Publish

```php
$this->publish('panda-panel-config');   // vendor:publish --tag=panda-panel-config
$this->publish('panda-panel-assets');   // vendor:publish --tag=panda-panel-assets
```

Both run with whatever `--force` you passed. Then the asset manifest is written:

```php
use PandaPanel\Support\Installer\AssetManifest;

AssetManifest::write(AssetManifest::read());
```

That records what was just published, which is what lets a later `panel:assets` tell a file this
application edited from one that has simply fallen behind. Without it, every future upgrade is a
choice between overwriting your work and updating nothing.

The migrations are offered rather than published:

```text
Publish the migrations into database/migrations? (yes/no) [no]
❯ They already run from the package. Publish only to own them, and set load_migrations
  to false in config/panda-panel.php if you do.
```

Offered, because a published copy and a package copy of the same migration is a schema applied
twice. In a non-interactive run the answer is no.

### 2. Give the application what it needs

The step that makes `panel:install` work on a blank `laravel new` rather than finishing with a
list of homework. Only files that are **not there** are written — an application that already has
an entrypoint has made a decision, and an installer that replaced it would be destroying work to
save a step.

| File | Why |
| --- | --- |
| `resources/views/app.blade.php` | Inertia needs a root view with `@inertia` |
| `resources/js/app.ts` | The entrypoint that mounts the panel's pages |
| `resources/css/app.css` | Imports the published `panda-panel.css` |
| `vite.config.ts` | The Vue plugin and the `@` alias every published component imports through |

Inertia's middleware is published with `inertia:middleware` and added to the `web` group in
`bootstrap/app.php`. A `vite.config.js` that cannot build a Vue application — which is what
`laravel new` ships — is moved to `.bak` rather than edited, and only after asking.

Skipped entirely under `--no-scaffold`. Full detail, including every outcome the bootstrap edit
can report, is in [CLI: panel:install](../cli/panel-install.md#2-give-the-application-what-it-needs).

### 3. Fill the host seam

`wayfinder:generate` where Wayfinder is installed, because `@/routes/*` and `@/actions/*` are
generated from your own routes. A minimal stand-in for whatever is still missing, copied out of
the package's `frontend/host/` — never over a module you already have, however it is spelled on
disk. See [Host modules](../frontend/host-modules.md).

### 4. Scaffold the panel

```php
$this->call('make:panel', ['name' => $panel, '--force' => (bool) $this->option('force')]);
```

Skipped entirely under `--no-panel`, in which case steps 3 and 6 have no panel to work with —
nothing is registered, and `panel:user` is called without a `--panel` to check against.

### 5. Register it in config

This is the step that makes the panel's URL answer. It is a textual edit to
`config/panda-panel.php` — the same line you would write, in the same place — because the file is
mostly comments explaining every key, and re-emitting it from the parsed array would throw all of
them away.

```php
use PandaPanel\Support\Installer\PanelRegistrar;

PanelRegistrar::register('App\Panels\Admin\AdminPanelProvider');
```

```php
/**
 * @param  class-string  $provider
 * @param  string|null   $path  the config file, defaulting to config_path('panda-panel.php')
 * @return self::*
 */
public static function register(string $provider, ?string $path = null): string
```

Four outcomes, and each is reported differently:

| Constant | Value | When | What the installer says |
| --- | --- | --- | --- |
| `PanelRegistrar::REGISTERED` | `registered` | The line was written, or the commented placeholder uncommented. | `Registered … in config/panda-panel.php.` |
| `PanelRegistrar::ALREADY_PRESENT` | `already-present` | A live entry for that provider already exists. Nothing is written. | `That panel is already registered…` |
| `PanelRegistrar::NO_CONFIG` | `no-config` | `config/panda-panel.php` does not exist. | Outstanding: add the line yourself. |
| `PanelRegistrar::UNRECOGNISED` | `unrecognised` | The `panels` array is not in the shape this package ships — built from a variable, restructured. | Outstanding: add the line yourself. The file is left untouched. |

Three details worth knowing:

- **The shipped config has `// App\Panels\Admin\AdminPanelProvider::class,` already present and
  commented out.** Treating that as "already registered" would report success and leave the panel
  unreachable, which is the exact failure this step prevents. A commented line is uncommented; a
  live one is left alone.
- **A second panel is appended, not prepended.** Order decides which panel a user lands in when the
  request does not name one, so a new panel goes last — where somebody adding one by hand would
  put it.
- **A restructured config is never guessed at.** A `panels` key built from a variable is a config
  somebody is managing themselves.

### 6. Report the redirects

```text
Signing in at a panel now lands in that panel rather than on fortify.home. Set login_redirect
to false in config/panda-panel.php to keep your own.
Signed-in visitors to /dashboard now land in the panel. Set home_redirect.enabled to false
in config/panda-panel.php to keep your own.
```

Printed, not asked: these are the two things installing this package changes about screens the
application already had, and a redirect nobody was told about is a bug report. Each line is
silent when its own config key is off.

### 7. Install the npm dependencies

The packages the components import, plus `@vitejs/plugin-vue` for the scaffolded Vite config. The
exact command is shown before it runs, and answering yes offers `npm run build` after it. Declined,
forced off with `--no-npm`, or unable to run, it reports the literal line instead — a failed
`npm install` never fails the install.

Under `--no-interaction` nothing reaches the network unless `--npm` says so.

### 8. Check what is left

Six checks, in this order. Each failure is added to the outstanding list rather than printed
where it happens. Most of what they used to report is written by steps 2, 3 and 7 now, so on a
blank application this list is short and on a starter kit it is usually empty.

| Check | Method | Outstanding message |
| --- | --- | --- |
| Inertia | `FrontendRequirements::missingInertia()` | Names the missing root view or middleware, and says every panel screen will 500 without it. |
| Vite | `FrontendRequirements::hasVite()` | "the published components are Vue and have to be built by something." |
| The dependency list is readable | `FrontendRequirements::hasNpmManifest()` | Names the path this package's own `package.json` was expected at, and says the npm dependencies could not be checked at all — a packaging fault rather than something wrong with your application. |
| npm dependencies | `FrontendRequirements::missingNpmPackages()` | The literal `npm install …` line for the packages you do not declare, followed by `npm run build`. |
| Layout override | `FrontendRequirements::layoutOverrides()` | The file, the line number, the offending code, and the replacement. |
| Host modules | `FrontendRequirements::missingHostModules()` | The list of `@/…` specifiers, with `wayfinder:generate` named for the generated ones. |

The layout check is the one worth understanding, because what it catches is silent. Every panel
page declares its own layout with `defineOptions({ layout: PanelLayout })`. An application entry
that assigns unconditionally overwrites that choice *after* the page asked:

```ts
page.default.layout = AppLayout;      // reported: every panel screen renders in your shell
page.default.layout ??= AppLayout;    // correct
page.default.layout ||= AppLayout;    // correct
```

Left as it is, every panel screen renders inside the application shell — with your sidebar, not
the panel navigation — at HTTP 200 and with nothing logged. `resources/js/app.ts`, `app.js`,
`ssr.ts` and `ssr.js` are all read.

Missing host modules are reported as a list rather than a count, deliberately: *which* ones are
missing says what to do. All of `@/routes/*` and `@/actions/*` means Wayfinder has not run; a
handful of components means this is not a starter kit application.

### 9. Offer a user

```text
Create a user who can sign in? (yes/no) [no]
```

Offered rather than assumed: an install into an application that already has users does not need
one, and creating a row in somebody's user table uninvited is not a thing an installer should do.
Answering yes runs `panel:user --panel={panel}` with the panel name lower-cased, so the new
account is checked against the panel that was just scaffolded.

Skipped under `--no-user`, and skipped when the input is not interactive.

## What it reports at the end

```text
Done. Nothing is left to do by hand.
```

or

```text
WARN  2 thing(s) this package cannot do for your application:

  1. Wayfinder is not installed, so `@/routes/*` and `@/actions/*` are stand-ins rather
     than generated from your own routes:
     …
  2. resources/js/app.ts line 12 overwrites the layout every panel page declares:
     …
```

Collected as it goes and printed once, rather than interleaved: an install that mixes five
successes with three warnings is an install whose warnings are read as noise.

## What it does not do

| | Why |
| --- | --- |
| `composer require` anything | It changes the autoloader underneath the process using it. Wayfinder is named, not installed. |
| `php artisan migrate` | Standard Laravel; run it when you are ready. |
| Overwrite a file you already have | Every write goes to a path where there was nothing. The two exceptions ask first: `app.css` gains one `@import`, and a `vite.config.js` that cannot build Vue is moved to `.bak`. |
| Edit `resources/js/app.ts` | If it wrote the file, it wrote a correct one. If you wrote it, it reports the one shape that is wrong and leaves it alone. |
| Register the guest or login redirect | Already done, by the service provider. `register_guest_redirect => false` and `login_redirect => false` hand them back. |
| `npm install` without asking | Unless `--npm`. It writes to `node_modules`, touches the lockfile, and reaches the network. |

## Re-running it

Safe. Publishing skips files that exist, registration is a no-op for a panel already listed,
`make:panel` skips files it would overwrite and says so, and the frontend checks are read-only.

With `--force` it is no longer safe in the same way: `vendor:publish --force` overwrites every
published file, including ones you have edited. After the first install, use
[`panel:assets`](../cli/panel-assets.md), which knows the difference.

## Notes

- **Exit code is always `0`.** Test the output, not the status, if you script around it.
- **`--no-interaction` takes the safe answer per step.** Files that are not there are written and
  Inertia's middleware is published; the migrations are not published, no user is offered, no
  `vite.config.js` is moved aside, and nothing reaches the network unless `--npm` says so.
- **The npm list is read from the package's `package.json`.** It cannot go stale relative to what
  the components import, because there is no second copy of it.
- **The installer writes `.panel-assets.json` even when nothing was published** — it records what
  is on disk, so a re-run after a manual `vendor:publish` still produces a correct baseline.

## See also

- [Installation](installation.md) — the same steps, done by hand
- [Frontend requirements](frontend-requirements.md) — every check in step 8, in full
- [Creating the first user](first-user.md) — the command step 9 calls
- [Opening your first panel](first-panel.md) — what to do once it finishes
- [Common install problems](common-install-problems.md)
- [CLI: panel:install](../cli/panel-install.md), [CLI: panel:assets](../cli/panel-assets.md)
- [Configuration: home redirect](../configuration/home-redirect.md),
  [guest redirect](../configuration/guest-redirect.md)
