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
    {--force : Overwrite files that already exist}
```

| Option | Default | Effect |
| --- | --- | --- |
| `--panel=` | `Admin` | The name passed to `make:panel`. Studly-cased there, so `admin` and `Admin` mean the same panel. |
| `--no-panel` | off | Publishes and configures, scaffolds nothing. Right for a second install into an application that already has its panels. |
| `--no-user` | off | Skips the account prompt entirely, prompt and all. |
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

## The six steps

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

### 2. Scaffold the panel

```php
$this->call('make:panel', ['name' => $panel, '--force' => (bool) $this->option('force')]);
```

Skipped entirely under `--no-panel`, in which case steps 3 and 5 have no panel to work with.

### 3. Register it in config

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

### 4. Report the home redirect

```text
Signed-in visitors to /dashboard now land in the panel. Set home_redirect.enabled to false
in config/panda-panel.php to keep your own.
```

Printed, not asked: it is the one thing installing this package changes about a screen the
application already had, and a redirect nobody was told about is a bug report. Silent when
`home_redirect.enabled` is false or the path list is empty.

### 5. Check the frontend

Five checks, in this order. Each failure is added to the outstanding list rather than printed
where it happens.

| Check | Method | Outstanding message |
| --- | --- | --- |
| Inertia | `FrontendRequirements::missingInertia()` | Names the missing root view or middleware, and says every panel screen will 500 without it. |
| Vite | `FrontendRequirements::hasVite()` | "the published components are Vue and have to be built by something." |
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

### 6. Offer a user

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
WARN  3 thing(s) this package cannot do for your application:

  1. Install the npm dependencies the components import, then rebuild:
     …
  2. resources/js/app.ts line 12 overwrites the layout every panel page declares:
     …
  3. The published components import these modules, which belong to your application
     and are not there yet:
     …
```

Collected as it goes and printed once, rather than interleaved: an install that mixes five
successes with three warnings is an install whose warnings are read as noise.

## What it does not do

| | Why |
| --- | --- |
| `npm install` / `npm run build` | It tells you the exact line. Running a package manager inside an artisan command is a side effect nobody asked for. |
| `php artisan wayfinder:generate` | Wayfinder is the application's tool, run against the application's routes. The installer names it when the generated modules are missing. |
| `php artisan migrate` | Standard Laravel; run it when you are ready. |
| Register the guest redirect | Already done, by the service provider. `register_guest_redirect => false` hands it back. |
| Edit `resources/js/app.ts` | It reports the one shape that is wrong and leaves the file alone. |

## Re-running it

Safe. Publishing skips files that exist, registration is a no-op for a panel already listed,
`make:panel` skips files it would overwrite and says so, and the frontend checks are read-only.

With `--force` it is no longer safe in the same way: `vendor:publish --force` overwrites every
published file, including ones you have edited. After the first install, use
[`panel:assets`](../cli/panel-assets.md), which knows the difference.

## Notes

- **Exit code is always `0`.** Test the output, not the status, if you script around it.
- **`--no-interaction` changes two answers, not the outcome.** Migrations are not published and no
  user is offered; everything else runs identically.
- **The npm list is read from the package's `package.json`.** It cannot go stale relative to what
  the components import, because there is no second copy of it.
- **The installer writes `.panel-assets.json` even when nothing was published** — it records what
  is on disk, so a re-run after a manual `vendor:publish` still produces a correct baseline.

## See also

- [Installation](installation.md) — the same steps, done by hand
- [Frontend requirements](frontend-requirements.md) — every check in step 5, in full
- [Creating the first user](first-user.md) — the command step 6 calls
- [Opening your first panel](first-panel.md) — what to do once it finishes
- [Common install problems](common-install-problems.md)
- [CLI: panel:install](../cli/panel-install.md), [CLI: panel:assets](../cli/panel-assets.md)
- [Configuration: home redirect](../configuration/home-redirect.md),
  [guest redirect](../configuration/guest-redirect.md)
