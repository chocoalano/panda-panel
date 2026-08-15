# Creating the first user

A fresh install has a panel, routes and a login page — and no user, so the first thing anyone does
is open `tinker` and write a `create()` call from memory, guessing which columns this
application's user model actually has. `panel:user` closes that gap: it creates an account through
the auth guard's own model and then tells you whether that account can actually reach the panel.

```bash
php artisan panel:user
```

```text
 Name:
 > Ada Lovelace

 Email address:
 > ada@example.com

 Password:
 > ********

  INFO  Created Ada Lovelace <ada@example.com>.

  INFO  They can sign into the Administrator panel at admin.
```

## Signature

```text
panel:user
    {--name= : The account name}
    {--email= : The email address to sign in with}
    {--password= : The password, prompted for when omitted}
    {--guard= : The auth guard whose user model to create, defaults to the application default}
    {--panel= : Report whether the new account can reach this panel}
```

| Option | Default | Notes |
| --- | --- | --- |
| `--name=` | prompted | `required`, `string`, `max:255`. |
| `--email=` | prompted | `required`, `string`, `email`, `max:255`. Uniqueness is your table's constraint, not a rule here. |
| `--password=` | prompted, hidden | `required`, `string`, `min:8`. |
| `--guard=` | `config('auth.defaults.guard')` | The guard whose provider names the model to create. |
| `--panel=` | the first registered panel | Which panel to check access against. Reported only; never enforced. |

```bash
php artisan panel:user --name=Ada --email=ada@example.com --password=correct-horse
php artisan panel:user --guard=admin --panel=admin
php artisan panel:user --name=Ada --email=ada@example.com --password="$ADMIN_PASSWORD" --no-interaction
```

Exit code is `0` on success and `1` when the guard, the model, or validation refuses.

## Which model it creates

Not `App\Models\User`, and deliberately so. The command walks the auth config:

```text
--guard=  or  auth.defaults.guard
      ↓
auth.guards.{guard}.provider
      ↓
auth.providers.{provider}.model
```

A project with `App\Models\Admin` behind a second guard gets its admin; a project that renamed the
default gets that. Guessing `App\Models\User` would work for most projects and fail silently —
creating a row in the wrong table — for exactly the ones that need this most.

Two failures, both reported and both exit `1`:

| Message | Cause |
| --- | --- |
| `No auth guard named [x].` | `auth.guards.x.provider` is not a string, usually because the guard does not exist. |
| `The [users] user provider names no model this command can create.` | The provider has no `model` key, or names a class that does not exist. A token or LDAP provider will read like this. |

## What it writes

```php
$user->forceFill([
    'name' => $attributes['name'],
    'email' => $attributes['email'],
    'password' => Hash::make($attributes['password']),
    'email_verified_at' => now(),
])->save();
```

`forceFill` rather than `create`, because the point is to work against a model whose `$fillable`
the command has never seen. `email_verified_at` is set because a user created from the console has,
by definition, been verified by whoever ran the command — leaving it null would put them straight
into the verify-email wall, on a panel whose `->auth()` includes `verified`.

Anything the save throws — a unique constraint on `email`, a `NOT NULL` column the model does not
default — is caught and reported as `The user could not be created: …`, with the driver's own
message.

## The access report

After the account exists, the command asks the panel whether it can be entered:

```php
$panel->isAccessibleTo($user);
```

That is two independent questions, and both must agree:

```php
// A rule about the panel, in its provider:
->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true)

// A rule about the account, on the model:
final class User extends Authenticatable implements PandaPanel\Contracts\PanelUser
{
    public function canAccessPanel(PandaPanel\Core\Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }
}
```

So the message names which one said no, because the two are fixed in different files:

```text
WARN  They cannot reach the Administrator panel yet — the panel's own canAccess() says no.
      Set whatever that rule reads before signing in.
```

```text
WARN  They cannot reach the Administrator panel yet — your user model's canAccessPanel() says no.
      Set whatever that rule reads before signing in.
```

It reports and does not refuse. A user with `is_admin` still to be set is a normal intermediate
state rather than a mistake to block on — and the flag is usually not mass-assignable, which is
the next section.

Without `--panel`, the panel checked is the **first registered** one, which is also the panel a
bare sign-in lands in. With `--panel=`, it is the panel with that id — lower-case, as derived from
the provider class name (`AdminPanelProvider` → `admin`).

## Granting access

A privilege flag that is mass-assignable is a privilege anyone can grant themselves by adding a
field to a form post, so the example user model leaves `is_admin` out of `$fillable`. Promoting an
account is therefore an explicit write:

```bash
php artisan tinker
```

```php
use App\Models\User;

User::query()->where('email', 'ada@example.com')->first()->forceFill(['is_admin' => true])->save();
```

Then confirm the panel agrees:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->get('admin')->isAccessibleTo($user);   // true
```

## Non-interactive runs

Every prompt is skipped when the input is not interactive — a prompt written into a pipe that will
never answer is worse than a failure. The value becomes an empty string, and validation then
reports exactly which options the script forgot:

```text
ERROR  The name field is required.
ERROR  The email field is required.
ERROR  The password field is required.
```

Which means a CI or provisioning script must pass all three:

```bash
php artisan panel:user \
  --name="$ADMIN_NAME" \
  --email="$ADMIN_EMAIL" \
  --password="$ADMIN_PASSWORD" \
  --no-interaction
```

`panel:install` offers to run this for you, in an interactive terminal only, and passes
`--panel={panel}` for the panel it just scaffolded.

## Notes

- **The password rules here are not your application's.** `min:8` rather than
  `Password::defaults()`: this is a console command run by whoever owns the database, and a rule
  written for public sign-up refusing an operator's own password is friction with nothing behind
  it. Your sign-up form is unaffected.
- **`--password=` on the command line lands in your shell history**, and in the process list while
  it runs. Prefer the prompt, or an environment variable, on a shared machine.
- **A mistyped `--panel` reports nothing at all.** An unknown panel id means no report rather than
  an error, so `--panel=admn` looks like a successful creation with no access line. Check the id
  with `php artisan route:list --name=panel.` if the report you expected is missing.
- **No panel registered means no report either.** The account is still created.
- **The command creates; it does not update.** Running it twice with the same email hits your
  unique constraint and reports the driver's error.

## See also

- [Opening your first panel](first-panel.md) — signing in and what you land on
- [Running panel:install](installer.md) — the install step that offers this command
- [CLI: panel:user](../cli/panel-user.md)
- [Authentication: the user model](../authentication/user-model.md),
  [the PanelUser contract](../authentication/panel-user-contract.md)
- [Panels: access](../panels/access.md) — `canAccess()` in full
- [Concepts: authorization](../concepts/authorization.md) — policies, and why a new resource 403s
- [Troubleshooting: 403 from a panel](../troubleshooting/authorization-403.md)
