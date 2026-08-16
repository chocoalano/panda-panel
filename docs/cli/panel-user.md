# `panel:user`

Creates an account that can sign into a panel. Reach for it right after
installing, when there is a panel, routes and a login page but no user — and
again whenever you need to hand somebody access without a registration form.

```bash
php artisan panel:user
```

```text
 ┌ Name ────────────────────────────────────────────────┐
 │ Ada Lovelace                                         │
 └──────────────────────────────────────────────────────┘
 ┌ Email address ───────────────────────────────────────┐
 │ ada@example.com                                      │
 └──────────────────────────────────────────────────────┘
 ┌ Password ────────────────────────────────────────────┐
 │ ••••••••••••                                         │
 └──────────────────────────────────────────────────────┘

INFO  Created Ada Lovelace <ada@example.com>.
INFO  They can sign into the Admin panel at admin.
```

The gap this closes is small and unmissable. Without it the first thing anyone
does is open `tinker` and write a `create()` call from memory, guessing which
columns this application's user model actually has.

## Signature

```text
panel:user
    {--name= : The account name}
    {--email= : The email address to sign in with}
    {--password= : The password, prompted for when omitted}
    {--guard= : The auth guard whose user model to create, defaults to the application default}
    {--panel= : Report whether the new account can reach this panel}
```

| Option | Default | Effect |
| --- | --- | --- |
| `--name=` | prompted | Written to `name`. Required, max 255 characters. |
| `--email=` | prompted | Written to `email`. Required, must be a valid address, max 255 characters. |
| `--password=` | prompted, hidden | Hashed with `Hash::make()`. Required, minimum 8 characters. |
| `--guard=` | `auth.defaults.guard` | Which guard's user model to instantiate. |
| `--panel=` | the first panel by id | The panel id to check access against. Reporting only — it never blocks. |

There is no `--force` and no `--update`: the command creates a row, and an
existing email is a database error, reported as one.

## Non-interactive

Every prompt has an option, so the command scripts:

```bash
php artisan panel:user \
    --name="Ada Lovelace" \
    --email=ada@example.com \
    --password="a-long-password" \
    --panel=admin \
    --no-interaction
```

A missing value in a non-interactive run is not prompted for — prompting into a
pipe that will never answer is worse than failing. It answers with an empty
string instead, and validation then reports which options the command needed:

```text
ERROR  The name field is required.
ERROR  The email field is required.
```

## Which model it creates

The auth guard's, resolved through the auth config:

```php
$guard = $this->option('guard') ?? config('auth.defaults.guard', 'web');
$provider = config("auth.guards.{$guard}.provider");
$model = config("auth.providers.{$provider}.model");
```

So a project with `App\Models\Admin` behind a second guard gets its admin, and a
project that renamed the default gets that:

```bash
php artisan panel:user --guard=admin
```

Guessing `App\Models\User` would work for most projects and fail silently —
creating a row in the wrong table — for exactly the ones that need this most.

Two failures are reported rather than guessed around:

```text
ERROR  No auth guard named [staff].
ERROR  The [users] user provider names no model this command can create.
```

## What it writes

```php
$user->forceFill([
    'name' => $attributes['name'],
    'email' => $attributes['email'],
    'password' => Hash::make($attributes['password']),
    'email_verified_at' => now(),
])->save();
```

Four columns, and `email_verified_at` is one of them on purpose: a user created
from the console has, by definition, been verified by whoever ran the command.
Leaving it null would put them straight into the verify-email wall.

`forceFill()` rather than `create()`, so a model with a restrictive `$fillable`
still works.

Anything else your users table requires — a tenant id, a role, an `is_admin`
flag — is not written, and a `NOT NULL` column without a default fails here:

```text
ERROR  The user could not be created: SQLSTATE[23000]: Integrity constraint violation…
```

## Validation

```php
'name' => ['required', 'string', 'max:255'],
'email' => ['required', 'string', 'email', 'max:255'],
'password' => ['required', 'string', 'min:8'],
```

Deliberately not `Password::defaults()`. This is a console command run by
whoever owns the database, and a rule written for public sign-up refusing an
operator's own password is friction with nothing behind it.

Note what is not checked: uniqueness. A duplicate email is caught by the
database, and reported as the exception it is.

## The access report

After creating the account, the command says whether it can actually get in:

```text
INFO  They can sign into the Admin panel at admin.
```

or

```text
WARN  They cannot reach the Admin panel yet — your user model's canAccessPanel() says no.
      Set whatever that rule reads before signing in.
```

```text
WARN  They cannot reach the Admin panel yet — the panel's own canAccess() says no.
      Set whatever that rule reads before signing in.
```

A panel refuses on two independent rules and both must agree, so the message
names which one said no — they are fixed in different files:

| Rule | Where it is set | Signature |
| --- | --- | --- |
| the panel's own | the panel provider | `Panel::canAccess(Closure $callback): self` |
| your user model's | the model, when it implements `PandaPanel\Contracts\PanelUser` | `canAccessPanel(Panel $panel): bool` |

Both are read through `Panel::isAccessibleTo(?Authenticatable $user): bool`,
which is what the command calls. A user model implementing neither contract is
refused nothing.

Reported, never enforced: a user with `is_admin` still to be set is a normal
intermediate state rather than a mistake to block on. The account is created
either way.

Which panel is checked:

```bash
php artisan panel:user --panel=admin      # that panel
php artisan panel:user                    # the first panel by id
```

`PanelManager::all()` is sorted by panel id, not by the order the providers are
listed in config, so "first" means the id that sorts first. That is also where a
bare sign-in lands — `PanelHomeRedirect` takes the first panel in the same list
the user can enter — so it is what an operator means when they do not say. An
unknown `--panel` value skips the report entirely rather than failing — the
account was still created.

The value is a panel **id**, which comes from the provider class name:
`AdminPanelProvider` is `admin`.

## Exit codes

| Outcome | Code |
| --- | --- |
| User created | `0` |
| Validation failed | `1` |
| The guard or its model could not be resolved | `1` |
| The insert threw | `1` |

The access warning does not change the exit code.

## Gotchas

- **It does not update an existing user.** Re-running with the same email hits
  the unique index and reports the driver's error.
- **`--password` is visible in your shell history and in `ps`.** Prefer the
  prompt on a shared machine.
- **Only four columns are written.** An application whose users table needs more
  should seed instead, or add defaults in a migration.
- **Two-factor and email-code challenges still apply.** This command creates an
  account, not a bypass; a panel that requires a second factor still requires
  one on the first sign-in.
- **`--panel` is the panel id, not its path or name.** They are usually the same
  string, and diverge as soon as a panel sets `->path()` to something else.
- **Nothing is written when validation fails.** The three values are gathered
  and validated together, before the model is touched.

## See also

- [panel:install](panel-install.md) — offers this command as its last step
- [Creating the first user](../getting-started/first-user.md)
- [The panel:user command](../authentication/panel-user-command.md)
- [The PanelUser contract](../authentication/panel-user-contract.md), [User model](../authentication/user-model.md)
- [Login](../authentication/login.md), [Fortify](../authentication/fortify.md)
- [Two-factor](../authentication/two-factor.md), [Email code challenge](../authentication/email-code-challenge.md)
- [Panel access](../panels/access.md), [Authorization](../concepts/authorization.md)
- [make:panel](make-panel.md)
