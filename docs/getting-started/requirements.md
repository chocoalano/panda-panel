# Requirements

What an application needs before `composer require chocoalano/panel` will install, boot, and
render a panel. Read this first if you are adding the panel to an application that already
exists; a freshly created Laravel Vue starter kit application satisfies almost all of it
already.

## Check what you have

```bash
php -v                     # 8.2 or newer
php -m | grep -E 'json|zip'
composer show laravel/framework | head -2
node -v                    # 20.19 or newer
```

Nothing here is a guess about your project: after installing, the same questions are answered
for you by the installer, which reads the real files and names what is missing.

```bash
php artisan panel:install
```

## PHP and composer

These are the constraints in this package's own `composer.json`. Composer enforces every one of
them, so an application that does not satisfy them fails at `composer require` rather than at
runtime.

| Requirement | Constraint | Why the package needs it |
| --- | --- | --- |
| `php` | `^8.2` | PHP 8.2 is supported *through* Laravel 12, the newest Laravel that runs on it. |
| `ext-json` | `*` | Metadata crosses to Vue as JSON, and `.panel-assets.json` is read and written as JSON. |
| `ext-zip` | `*` | XLSX import and export. An `.xlsx` file is a zip archive — see `PandaPanel\Support\Spreadsheet\Xlsx`. |
| `composer-runtime-api` | `^2.2` | `Composer\InstalledVersions`, which reports a plugin's installed version rather than trusting a hand-written string. |
| `composer/semver` | `^3.0` | Evaluates a plugin's `requiresPanel` constraint against this framework's version. |
| `laravel/framework` | `^12.0\|^13.0` | See [Compatibility](compatibility.md) for why Laravel 11 is not supported and cannot be. |
| `inertiajs/inertia-laravel` | `^3.0` | Every panel screen is an Inertia response. Version 2 is not supported. |
| `laravel/fortify` | `^1.37.2` | Login, registration, password reset, two-factor and passkeys. The panel renders the screens; Fortify owns the POSTs. |
| `symfony/finder` | `^7.0\|^8.0` | Discovery walks the panel directories to find resources, pages and widgets. |

`ext-zip` is a hard requirement rather than a suggestion because it is declared in `require`, not
in `suggest`. An application that never exports a spreadsheet still needs the extension present
for composer to install the package.

## The database

Nothing in the package is engine-specific. Queries are ordinary Eloquent; the suite runs on
SQLite. What the package does need is its own schema, which ships as four migrations that run
from the package by default:

| Migration | What it creates | Guard |
| --- | --- | --- |
| `create_notifications_table` | Laravel's own `notifications` table, read on every panel request by the notification centre. | Skipped when the table already exists. `down()` drops it only when it can establish this package created it. |
| `add_email_two_factor_to_users_table` | `two_factor_email_confirmed_at` on `users`. | Skipped when the column, or the table, is absent or already present. |
| `create_panel_integrations_table` | `panel_integrations`. | Skipped when the table already exists. |
| `add_history_and_signing_to_panel_integrations` | `panel_integration_deliveries`, and a `secret` column. | Each half is skipped when already applied. |

They run from the package because a panel cannot render without the first: an install that had to
remember a publish step would 500 on its very first page. To own them instead:

```bash
php artisan vendor:publish --tag=panda-panel-migrations
```

```php
// config/panda-panel.php
'load_migrations' => false,
```

Leaving `load_migrations` at `true` *and* publishing gives you two copies of the same migration,
which is a schema applied twice. Publish only to own them, and turn the flag off when you do.

## The user model

The panel asks for four things, and a Laravel Vue starter kit already provides the first two.

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

class User extends Authenticatable implements PanelUser
{
    use Notifiable;
    use TwoFactorAuthenticatable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }
}
```

| What | Required? | What needs it |
| --- | --- | --- |
| `Illuminate\Notifications\Notifiable` | Yes, for the notification centre | `PandaPanel\Contracts\PanelNotifiable` names the same three methods so static analysis can see the requirement. Nothing has to implement the interface — the trait already satisfies the controller. |
| `Laravel\Fortify\TwoFactorAuthenticatable` | Yes, for the security settings page | The panel's own `settings/security` page. |
| `PandaPanel\Contracts\PanelUser` | Optional | One method, `canAccessPanel(Panel $panel): bool`. A rule about the *account* — suspended, unverified, no tenant — asked on every panel request alongside that panel's own `canAccess()`. Both must agree; neither can loosen the other. |
| `PandaPanel\Contracts\HasPanelTenants` | Only for a tenant-scoped panel | Which tenants this account may enter, and whether it may enter a given one. See [Tenancy](../tenancy/concepts.md). |

A user model that implements neither `PanelUser` nor anything else is refused nothing — the
panel's own predicate is then the only question.

## The frontend

The panel's screens are Vue 3 SFCs published into your `resources/js` and built by your Vite.
Three things have to be true, and none of them is something this package can do for you:

| | What | Checked by |
| --- | --- | --- |
| 1 | An Inertia root view at `resources/views/app.blade.php`, and `app/Http/Middleware/HandleInertiaRequests.php`. | `FrontendRequirements::missingInertia()` |
| 2 | A `vite.config.ts` or `vite.config.js`. | `FrontendRequirements::hasVite()` |
| 3 | The npm dependencies the components import, and the modules under `@/` that belong to your application. | `FrontendRequirements::missingNpmPackages()`, `FrontendRequirements::missingHostModules()` |

The npm dependency list is read from this package's own `package.json` rather than restated, so
`panel:install` and the build can never disagree about it:

| Package | Range |
| --- | --- |
| `@inertiajs/vue3` | `^3.0.0` |
| `@internationalized/date` | `^3.12.0` |
| `@laravel/echo-vue` | `^2.4.0` |
| `@laravel/passkeys` | `^0.4.0` |
| `@lucide/vue` | `^1.31.0` |
| `@tailwindcss/vite` | `^4.1.0` |
| `@tanstack/vue-table` | `^9.0.0` |
| `@vueuse/core` | `^14.0.0` |
| `class-variance-authority` | `^0.7.0` |
| `clsx` | `^2.1.0` |
| `reka-ui` | `^2.0.0` |
| `tailwind-merge` | `^3.0.0` |
| `tailwindcss` | `^4.1.0` |
| `tw-animate-css` | `^1.2.0` |
| `vue` | `^3.5.0` |
| `vue-input-otp` | `^0.4.0` |
| `vue-sonner` | `^2.0.0` |

Node 20.19 or newer, which is Vite 7's floor and what `engines.node` declares. The exact
`npm install` line for your project — only the packages you are actually missing — is printed by
`panel:install`.

The full list of `@/…` modules your application owns rather than the package is in
[Frontend requirements](frontend-requirements.md).

## What the panel takes over

Installing the package changes two addresses in an application that has them, and both stay
reachable:

| Address | What happens | How to keep yours |
| --- | --- | --- |
| `/dashboard` | A signed-in user is redirected to the first panel they can enter. Your route, its name and `pages/Dashboard.vue` are untouched — the request is answered earlier by a `web` middleware. | `home_redirect.enabled => false` in `config/panda-panel.php` |
| A guest on a panel URL | Sent to *that panel's* login when the panel has one, and to `route('login')` otherwise — which is Laravel's own default. | `register_guest_redirect => false`, then call `PandaPanel\Support\PanelLoginRedirect::for()` from your own rule |

Nothing else the application owns is read, edited or overwritten.

## Notes

- **PHP 8.2 with Laravel 13 does not exist.** Laravel 13 requires PHP 8.3. PHP 8.2 users get
  Laravel 12, and CI runs that combination as a real job rather than inferring it.
- **`ext-zip` is required even without spreadsheets.** It is in `require`, so composer refuses to
  install the package without it.
- **The `notifications` table is not optional.** The notification centre queries it on every panel
  request. If you turn `load_migrations` off, publish the migrations and run them.
- **Fortify is a dependency, not an integration.** `composer require chocoalano/panel` installs
  it. What Fortify still needs from you is the application half — `Fortify::createUsersUsing()`
  and the view callbacks for the addresses outside the panel.

## See also

- [Compatibility matrix](compatibility.md) — what CI tests, and what is deliberately unsupported
- [Installation](installation.md) — the install itself, step by step
- [Frontend requirements](frontend-requirements.md) — the npm packages and the host seam in full
- [Laravel Vue starter kit setup](vue-starter-kit.md) — the fastest application to install into
- [Common install problems](common-install-problems.md) — symptoms and their causes
- [Configuration reference](../configuration/panda-panel.md) — every key in `config/panda-panel.php`
- [Authentication and users](../authentication/user-model.md) — the user model in detail
