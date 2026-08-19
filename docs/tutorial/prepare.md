# 1 · Prepare your environment

**Goal:** know, before you install anything, that this machine can run the panel — and know which
line to fix if it cannot.

Nothing here is guesswork. Every constraint below is declared in the package's own `composer.json`
and `package.json`, which means composer enforces them at install time rather than letting them
surface as a runtime error three steps later.

## Do this

Run all four. Each one answers a question the installer would otherwise answer for you later.

```bash
php -v                              # 8.2 or newer
php -m | grep -E '^(json|zip)$'     # both must print
composer -V                         # 2.2 or newer
node -v                             # 20.19 or newer
```

### What each one is for

| Requirement | Constraint | Why the package needs it |
| --- | --- | --- |
| `php` | `^8.2` | PHP 8.2 is supported *through* Laravel 12, the newest Laravel that runs on it |
| `ext-json` | any | Metadata crosses to Vue as JSON, and `.panel-assets.json` is read and written as JSON |
| `ext-zip` | any | XLSX import and export — an `.xlsx` file is a zip archive |
| `composer-runtime-api` | `^2.2` | Reports a plugin's installed version instead of trusting a hand-written string |
| `laravel/framework` | `^12.0 \|\| ^13.0` | Laravel 11 is not supported and cannot be |
| `inertiajs/inertia-laravel` | `^3.0` | Every panel screen is an Inertia response; version 2 is not supported |
| `laravel/fortify` | `^1.37.2` | Login, registration, password reset, two-factor and passkeys |
| Node | `20.19+` | Vite 7's floor, which is what builds the panel's Vue components |

::: warning PHP 8.2 with Laravel 13 does not exist
Laravel 13 requires PHP 8.3. On PHP 8.2 you get Laravel 12, and that combination is tested as a
real CI job rather than assumed to work.
:::

::: details `ext-zip` is required even if you never export a spreadsheet
It is declared in `require`, not in `suggest`, so composer refuses to install the package without
it. On Debian and Ubuntu: `sudo apt install php8.3-zip`. With Homebrew's PHP it is already
compiled in.
:::

## The database

Nothing in the package is engine-specific — queries are ordinary Eloquent, and the test suite runs
on SQLite. Any database Laravel supports will do, including the `database/database.sqlite` file a
fresh Laravel application creates for you.

What the package does need is its own schema, which ships as four migrations that run **from the
package** by default. You do not publish them, and you do not copy them:

| Migration | What it creates |
| --- | --- |
| `create_notifications_table` | Laravel's `notifications` table, read on every panel request |
| `add_email_two_factor_to_users_table` | `two_factor_email_confirmed_at` on `users` |
| `create_panel_integrations_table` | `panel_integrations` |
| `add_history_and_signing_to_panel_integrations` | `panel_integration_deliveries`, and a `secret` column |

Each one skips itself when the table or column is already there, so they are safe on an
application that already has a `notifications` table.

::: tip Do not publish the migrations "to be safe"
Publishing a copy while `load_migrations` is still `true` gives you the package's copy *and*
yours — the same schema applied twice. Publish only when you want to own them, and set
`load_migrations` to `false` in the same commit.
:::

## What the panel expects of your application

Three things must be true of the frontend, and none of them is something the package can do for
you. Step 2 gives you all three at once; this table is so you recognise them when the installer
names one.

| | What | Where it lives |
| --- | --- | --- |
| 1 | An Inertia root view, and Inertia's middleware | `resources/views/app.blade.php`, `app/Http/Middleware/HandleInertiaRequests.php` |
| 2 | A Vite config | `vite.config.ts` or `vite.config.js` |
| 3 | The npm packages the components import, and the `@/…` modules your application owns | `package.json`, `resources/js/` |

And one thing of the user model — though only the first two matter today:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use Notifiable;                  // the notification centre
    use TwoFactorAuthenticatable;    // the security settings page
}
```

A Laravel Vue starter kit application already has both traits.

## Check it worked

You are ready when all four commands printed a version in range and neither `json` nor `zip` was
missing. Write down what `php -v` said — if `composer require` refuses in step 3, that number is
the first thing to compare against.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| `php -m` prints no `zip` | The extension is not installed | `apt install php8.3-zip`, or enable `extension=zip` in `php.ini` |
| `node -v` prints 18.x or 20.10 | Below Vite 7's floor | Install Node 20.19+ or 22 LTS — `nvm install 22` |
| `composer -V` prints 1.x | Too old for `composer-runtime-api ^2.2` | `composer self-update --2` |
| Two PHP versions on the machine | The CLI PHP is not the one your web server runs | Compare `php -v` with a `phpinfo()` page before debugging anything else |

## Next

Everything above is a property of the machine. The next step is a property of the project.

**→ [2 · Create the project](project)**

## See also

- [Requirements](/getting-started/requirements) — the same list, with the reasoning in full
- [Compatibility matrix](/getting-started/compatibility) — what CI tests, and what is deliberately unsupported
- [Frontend requirements](/getting-started/frontend-requirements) — every npm package and host module
