# The `panel:user` Command

Creates an account that can sign into a panel, through the auth guard's own user
model, and then tells you whether that account can actually get in. You reach for
it on a fresh install — there is a panel, there are routes, there is a login page,
and there is no user — and in provisioning scripts that need a first
administrator.

## A minimal working example

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

Everything can be passed instead of prompted:

```bash
php artisan panel:user --name=Ada --email=ada@example.com --password=correct-horse-battery
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

| Option | Default | Validation |
| --- | --- | --- |
| `--name=` | prompted | `required`, `string`, `max:255` |
| `--email=` | prompted | `required`, `string`, `email`, `max:255` |
| `--password=` | prompted, hidden | `required`, `string`, `min:8` |
| `--guard=` | `config('auth.defaults.guard')`, itself defaulting to `web` | must name a guard with a provider |
| `--panel=` | the first registered panel | reported only, never enforced |

Exit code `0` on success, `1` when the guard, the model, or validation refuses.

```php
// PandaPanel\Console\Commands\MakePanelUserCommand
public function handle(PandaPanel\Core\PanelManager $manager): int
```

The password rule is deliberately not `Illuminate\Validation\Rules\Password::defaults()`.
This is a console command run by whoever owns the database, and a rule written
for public sign-up refusing an operator's own password is friction with nothing
behind it. Your registration form is unaffected.

## Which model it creates

Not `App\Models\User`. The command walks the auth config:

```text
--guard=  or  auth.defaults.guard
      ↓
auth.guards.{guard}.provider
      ↓
auth.providers.{provider}.model
```

```bash
php artisan panel:user --guard=admin --panel=admin
```

A project with `App\Models\Admin` behind a second guard gets its admin; a project
that renamed the default gets that. Guessing `App\Models\User` would work for
most projects and fail silently — creating a row in the wrong table — for exactly
the ones that need this most.

Two failures, both reported, both exit `1`:

| Message | Cause |
| --- | --- |
| `No auth guard named [x].` | `auth.guards.x.provider` is not a string, usually because the guard does not exist |
| `The [users] user provider names no model this command can create.` | The provider has no `model` key, or names a class that does not exist — a token or LDAP provider reads like this |

## What it writes

```php
$user = new $model;

$user->forceFill([
    'name' => $attributes['name'],
    'email' => $attributes['email'],
    'password' => Hash::make($attributes['password']),
    'email_verified_at' => now(),
])->save();
```

`forceFill()` rather than `create()`, because the point is to work against a
model whose `$fillable` the command has never seen. `Hash::make()` rather than
relying on a `hashed` cast, for the same reason.

`email_verified_at` is set because a user created from the console has, by
definition, been verified by whoever ran the command. Leaving it null would put
the very first account straight into the verify-email wall, on a panel whose
`->auth()` includes `verified`. See [Email Verification](email-verification.md).

Anything the save throws — a unique constraint on `email`, a `NOT NULL` column
the model does not default — is caught and reported with the driver's own
message:

```text
ERROR  The user could not be created: SQLSTATE[23000]: Integrity constraint violation…
```

## The access report

After the account exists, the command asks the panel whether it can be entered:

```php
$panel->isAccessibleTo($user);
```

That is two independent questions, and both must agree — a closure on the panel
and a method on the user model. So the message names which one said no, because
the two are fixed in different files:

```text
INFO  They can sign into the Administrator panel at admin.
```

```text
WARN  They cannot reach the Administrator panel yet — the panel's own canAccess() says no.
      Set whatever that rule reads before signing in.
```

```text
WARN  They cannot reach the Administrator panel yet — your user model's canAccessPanel() says no.
      Set whatever that rule reads before signing in.
```

The attribution is exact: the command asks
`$user instanceof PanelUser && ! $user->canAccessPanel($panel)` first, and blames
the panel's own predicate otherwise.

It reports and does not refuse. A user with `is_admin` still to be set is a
normal intermediate state rather than a mistake to block on — and that flag is
usually not mass-assignable, which is why setting it is a second step:

```php
use App\Models\User;

User::query()
    ->where('email', 'ada@example.com')
    ->firstOrFail()
    ->forceFill(['is_admin' => true])
    ->save();
```

### Which panel is checked

```bash
php artisan panel:user --panel=admin
```

Without `--panel`, it is the **first registered** panel — `config('panda-panel.panels')`
order — which is also the panel a bare sign-in lands in, so it is the one an
operator means when they do not say. With `--panel=`, it is the panel with that
id — `PanelProvider::panelId()` kebab-cases the provider's class basename
(`AdminPanelProvider` → `admin`, `CustomerPortalPanelProvider` →
`customer-portal`) unless the provider set its own with `->id()`.

An unknown id produces **no report at all** rather than an error, so
`--panel=admn` looks like a successful creation with a missing access line. So
does an application with no panels registered. The account is created either way.

## Non-interactive runs

Every prompt is skipped when the input is not interactive — a prompt written into
a pipe that will never answer is worse than a failure. The value becomes an empty
string, and validation then names exactly what the script forgot:

```bash
php artisan panel:user --no-interaction
```

```text
ERROR  The name field is required.
ERROR  The email field is required.
ERROR  The password field is required.
```

Which means CI and provisioning must pass all three:

```bash
php artisan panel:user \
  --name="$ADMIN_NAME" \
  --email="$ADMIN_EMAIL" \
  --password="$ADMIN_PASSWORD" \
  --panel=admin \
  --no-interaction
```

Interactive prompts use `Laravel\Prompts`: `text(label: …, required: true)` for
the name and email, `password(label: …)` for the password so it is never echoed.

## Where it fits with `panel:install`

`php artisan panel:install` asks "Create a user who can sign in?" at the end of a
scaffold — defaulting to no, in an interactive terminal only, and skipped
entirely by `--no-user`. It then calls this command with `--panel={panel}` for
the panel it just created. Running `panel:user` yourself afterwards is the same
thing.

```bash
php artisan panel:install
php artisan panel:user --panel=admin
```

## Testing it

The command is exercised in `tests/Feature/Panel/InstallerTest.php`, with its
signature pinned by `tests/Feature/Panel/InstallCommandSmokeTest.php`. Driving it
from a test of your own is ordinary Artisan testing:

```php
it('creates a user through the guard\'s own model', function (): void {
    $this->artisan('panel:user', [
        '--name' => 'Ada Lovelace',
        '--email' => 'ada@example.com',
        '--password' => 'correct-horse-battery',
        '--panel' => 'admin',
    ])->assertSuccessful();

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('fails on an unknown guard', function (): void {
    $this->artisan('panel:user', ['--guard' => 'nope'])->assertFailed();
});
```

## Gotchas

- **`--password=` lands in shell history** and in the process list while it
  runs. Prefer the prompt, or an environment variable, on a shared machine.
- **It creates; it does not update.** Running it twice with the same address hits
  your unique constraint and reports the driver's error. There is no
  `--force`, and no update mode.
- **The access report is a report.** A user who cannot reach a panel is still
  created, still signs in, and still gets a 403 at the panel's door until the
  rule it names is satisfied.
- **A mistyped `--panel` is silent.** No error, no report. Confirm the id with
  `php artisan route:list --name=panel.`.
- **Only four attributes are written.** A model with an extra `NOT NULL` column
  and no default fails at the save, with the database's message. Give the column
  a default or a model-level attribute default.
- **The panel's own `canAccess()` closure is called during the report**, outside
  a request. A predicate that reads `request()->route()` will be asked a question
  it cannot answer. See [Panel Access Rules](../panels/access.md).

## See also

- [Creating the First User](../getting-started/first-user.md) — the same command in the install flow
- [CLI: panel:user](../cli/panel-user.md)
- [The `PanelUser` Contract](panel-user-contract.md)
- [User Model Requirements](user-model.md)
- [Panel Access Rules](../panels/access.md)
- [Running panel:install](../getting-started/installer.md)
- [Login](login.md), [Email Verification](email-verification.md)
