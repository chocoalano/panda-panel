# User Model Requirements

Panda Panel does not ship a user model, and never names one. It asks the auth
guard for `$request->user()` and works with whatever comes back. What it does
have is a short list of things it asks *of* that object, each one attached to a
feature you can turn off. You reach for this page when you are wiring an
existing application's user model into a panel, or when a panel feature is
quietly doing nothing and you want to know which method it went looking for.

## A minimal working example

A panel with `->auth()` needs nothing that a stock Laravel install does not
already have:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];
}
```

```bash
php artisan panel:user --name="Ada" --email=ada@example.test --password=secret123
```

```text
INFO  Created Ada <ada@example.test>.
INFO  They can sign into the Admin panel at admin.
```

That model can sign in, reach every page, and be refused nothing. Everything
below adds one capability, and every one of them is optional.

## What each feature asks for

| Feature | What the model needs | Without it |
| --- | --- | --- |
| Signing in at all | `Illuminate\Contracts\Auth\Authenticatable`, via the guard's provider | The panel has no user; guests are redirected |
| `->auth(verified: true)` | `Illuminate\Contracts\Auth\MustVerifyEmail` | Laravel's `verified` middleware checks nothing and lets everyone through |
| Per-account access rules | `PandaPanel\Contracts\PanelUser` | The account is refused nothing; only the panel's own `canAccess()` is asked |
| Notification centre | Laravel's `Notifiable` trait (named by `PandaPanel\Contracts\PanelNotifiable`) | The bell shows `0`, and its endpoints 403 |
| Emailed two-factor codes | `notify()` and a `two_factor_email_confirmed_at` column | `send()` aborts 500; the factor reads as off |
| TOTP two-factor | `Laravel\Fortify\TwoFactorAuthenticatable` | The security page reports two-factor off |
| Passkeys | `Laravel\Fortify\Contracts\PasskeyUser` + `Laravel\Fortify\PasskeyAuthenticatable` | The `passkeys` prop is `[]` |
| Tenancy | `PandaPanel\Contracts\HasPanelTenants` | The user belongs to no tenant and every tenant request is refused |

Every check is `method_exists()` or `instanceof` on the object, never a
`class_exists('App\Models\User')`. A project with `App\Models\Admin` behind a
second guard is a first-class case.

## The baseline: which model, and how it is found

Nothing in the framework hardcodes a model class. Two places resolve one, and
both go through the auth config:

- Requests: `$request->user()`, which is the guard's business.
- `php artisan panel:user`: reads `auth.defaults.guard`, then
  `auth.guards.{guard}.provider`, then `auth.providers.{provider}.model`.

```bash
php artisan panel:user --guard=admin
```

A guard whose provider names no resolvable class fails loudly:

```text
ERROR  The [admins] user provider names no model this command can create.
```

## Deciding which panels an account may enter

```php
namespace PandaPanel\Contracts;

interface PanelUser
{
    public function canAccessPanel(Panel $panel): bool;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->suspended;
    }
}
```

This is one of two questions, and both must agree. The other is the panel's
own predicate:

```php
use Illuminate\Contracts\Auth\Authenticatable;

$panel->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true);
```

`PandaPanel\Core\Panel::isAccessibleTo()` asks both:

```php
public function isAccessibleTo(?Authenticatable $user): bool
```

```php
$panel->isAccessibleTo($user);   // false if either says no
```

A closure on the panel is the right home for "this one is for administrators".
The contract is the right home for "this account is suspended" — written on the
model it applies to every panel at once and cannot be forgotten when a new panel
is added. A model that says yes cannot overrule a panel that says no, and a
panel that says yes cannot overrule the model.

`PandaPanel\Http\Middleware\ResolvePanel` enforces it on every request, with a
403 rather than a redirect. The same answer also filters the header's panel
switcher, so a panel the user would be refused is never offered as somewhere to
go. Full treatment in [The `PanelUser` contract](panel-user-contract.md).

## The notification centre

`PandaPanel\Contracts\PanelNotifiable` names what the bell needs. It is exactly
Laravel's own `Notifiable`, written down so static analysis can see the
requirement:

```php
namespace PandaPanel\Contracts;

interface PanelNotifiable
{
    public function notifications();        // MorphMany<DatabaseNotification, Model>
    public function unreadNotifications();  // MorphMany<DatabaseNotification, Model>
    public function notify($instance);      // void
}
```

No native return types, deliberately: the trait declares none, and a model using
it would be an incompatible declaration against an interface that did.

```php
use Illuminate\Notifications\Notifiable;
use PandaPanel\Contracts\PanelNotifiable;

class User extends Authenticatable implements PanelNotifiable
{
    use Notifiable;
}
```

Implementing the interface is optional. `PandaPanel\Http\Controllers\PanelNotificationController`
accepts a model that merely uses the trait:

```php
abort_unless(
    $user instanceof PanelNotifiable || (is_object($user) && method_exists($user, 'unreadNotifications')),
    403,
);
```

`SharePanelData` is softer still — a model with no `unreadNotifications()`
method, or an application whose `notifications` table has not been migrated,
gets an unread count of `0` rather than a 500 on every panel page.

## Two-factor and the security page

Three independent factors, and `PandaPanel\Http\Middleware\RequireTwoFactor`
accepts any of them.

**TOTP** is Fortify's, and the check is on the object because
`TwoFactorAuthenticatable` is a trait rather than an interface:

```php
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use TwoFactorAuthenticatable;
}
```

```php
method_exists($user, 'hasEnabledTwoFactorAuthentication')
    && $user->hasEnabledTwoFactorAuthentication();
```

**The panel's emailed code** is a column, not a trait:

```php
use PandaPanel\Auth\EmailCodeFactor;

public static function isEnabledFor(?object $user): bool
```

```php
EmailCodeFactor::isEnabledFor($user);   // true once two_factor_email_confirmed_at is set
```

It reads `$user->getAttributes()['two_factor_email_confirmed_at']` directly
rather than through `getAttribute()`. An application running
`Model::preventAccessingMissingAttributes()` would otherwise throw for a user
loaded with a narrowed select — a table widget picking four columns. "Was not
selected" is not "is turned on".

Cast it if you want an `Illuminate\Support\Carbon` back rather than a string:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_confirmed_at' => 'datetime',
        'two_factor_email_confirmed_at' => 'datetime',
    ];
}
```

**Passkeys** need both halves — the contract, which
`PandaPanel\Pages\Settings\SecuritySettings` tests with `instanceof`, and the
trait that supplies `passkeys()`:

```php
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    use PasskeyAuthenticatable;
}
```

A model with the trait but not the contract still satisfies `RequireTwoFactor`
(which asks `method_exists($user, 'passkeys') && $user->passkeys()->exists()`)
while showing an empty passkey list on the security page, because
`SecuritySettings::passkeys()` returns `[]` for anything that is not a
`PasskeyUser`. Declare both.

See [Two-Factor Authentication](two-factor.md) and [Passkeys](passkeys.md).

## Email verification

`Panel::auth()` appends Laravel's `verified` middleware by default:

```php
public function auth(bool $verified = true): self
```

```php
$panel->auth();                 // appends ['auth', 'verified']
$panel->auth(verified: false);  // appends ['auth']
```

Laravel's `verified` middleware only holds a user whose model implements
`Illuminate\Contracts\Auth\MustVerifyEmail` — a model without it is waved
through, so `auth(verified: true)` on a model that never declared the interface
enforces nothing rather than failing loudly. The panel's profile page reads the
same interface to decide whether to draw the "resend verification" block:

```php
// PandaPanel\Pages\Settings\ProfileSettings::props()
'mustVerifyEmail' => Auth::user() instanceof MustVerifyEmail,
```

## Tenancy

Required only for a panel that declared a tenant model:

```php
namespace PandaPanel\Contracts;

interface HasPanelTenants
{
    public function getPanelTenants(Panel $panel): Collection;

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Core\Panel;

class User extends Authenticatable implements HasPanelTenants
{
    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class);
    }

    /** @return Collection<int, Model> */
    public function getPanelTenants(Panel $panel): Collection
    {
        return $this->workspaces()->orderBy('id')->get();
    }

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return $this->workspaces()->whereKey($tenant->getKey())->exists();
    }
}
```

Two methods rather than one derived from the other: the list builds the
switcher, the check guards every request. `PandaPanel\Tenancy\Tenancy::availableTo()`
returns `[]` for a model that does not implement the interface, so a
tenant-scoped panel refuses rather than guessing. See
[`HasPanelTenants`](../tenancy/has-panel-tenants.md).

## Columns

| Column | Written by | Read by |
| --- | --- | --- |
| `name`, `email`, `password` | your registration action, `panel:user` | the shell's user menu, the profile page |
| `email_verified_at` | Laravel, `panel:user` | the `verified` middleware, `ProfileSettings` |
| `two_factor_email_confirmed_at` | `PanelTwoFactorController::enable()` / `disable()` | `EmailCodeFactor::isEnabledFor()` |
| `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` | Fortify | Fortify, and `hasEnabledTwoFactorAuthentication()` |

Only one of those is the package's. It arrives with a migration that runs from
the package unless `panda-panel.load_migrations` is false:

```bash
php artisan vendor:publish --tag=panda-panel-migrations
php artisan migrate
```

The migration is defensive on both ends — it returns early when `users` does not
exist or already has the column, and positions itself after
`two_factor_confirmed_at` only when Fortify's column is there to sit behind.

Hide the credentials:

```php
/** @var list<string> */
protected $hidden = [
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
];
```

That matters more in a panel than elsewhere: the application's
`HandleInertiaRequests` serialises `$request->user()` into `auth.user` on every
page, so anything not hidden is in the HTML.

## What the frontend expects of the serialised user

The package does *not* share the user. Your application's
`HandleInertiaRequests` does, and the published components read it:

```php
'auth' => [
    'user' => $request->user(),
],
```

| Component | Reads |
| --- | --- |
| `resources/js/components/NavUser.vue` | `auth.user`, handed straight to the application's own `UserInfo` and `UserMenuContent` |
| `resources/js/pages/panel/settings/Profile.vue` | `user.name`, `user.email`, `user.email_verified_at` |

`UserInfo` and `UserMenuContent` are the application's — part of the host seam
the package imports but never ships, because a project's account menu is its
own. Whatever they read from the user is up to you.

The shape is declared in `resources/js/types/auth.ts`, which
`vendor:publish --tag=panda-panel-assets` writes to `resources/js/types`:

```ts
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};
```

A model that renames `name` needs an accessor, or a fork of those two
components. Nothing on the server side cares.

## Creating one

```bash
php artisan panel:user
```

| Option | Meaning | Default |
| --- | --- | --- |
| `--name=` | The account name | prompted |
| `--email=` | The email address to sign in with | prompted |
| `--password=` | The password | prompted, hidden |
| `--guard=` | Which guard's user model to create | `auth.defaults.guard` |
| `--panel=` | Report whether the account can reach this panel | the first registered panel |

It `forceFill`s `name`, `email`, a hashed `password`, and `email_verified_at`
set to now — an account created from the console has been verified by whoever
ran the command, and leaving it null would put them straight into the
verify-email wall. Validation is `required|string|max:255` on the name,
`required|string|email|max:255` on the address, and `required|string|min:8` on
the password: deliberately not `Password::defaults()`, because a rule written
for public sign-up refusing an operator's own password is friction with nothing
behind it.

It reports, but never refuses, when the new account cannot reach the panel, and
names which of the two rules said no:

```text
WARN  They cannot reach the Administrator panel yet — the panel's own canAccess() says no. Set whatever that rule reads before signing in.
```

```text
WARN  They cannot reach the Administrator panel yet — your user model's canAccessPanel() says no. Set whatever that rule reads before signing in.
```

The panel is named by `Panel::getName()`, which falls back to
`Str::headline($id)` when the provider did not set one.

A user with `is_admin` still to be set is a normal intermediate state, not a
mistake to block on. Full page: [`panel:user`](panel-user-command.md).

## Notes

- **The broadcast channel name is fixed.**
  `PandaPanel\Broadcasting\PanelNotification::channelFor()` returns
  `'App.Models.User.'.$user->getAuthIdentifier()` regardless of the model's
  class. A project whose panel user is `App\Models\Admin` still broadcasts and
  subscribes on `App.Models.User.{id}`, so that is the string to register in
  `routes/channels.php`. See
  [Channel authorization](../notifications/channel-authorization.md).
- **Keep privilege flags out of `$fillable`.** Registration and profile updates
  both fill from request input, and a mass-assignable `is_admin` is a privilege
  anyone can grant themselves by adding a field to a form post. Promote with an
  explicit `forceFill()` or a dedicated action.
- **A model implementing neither `PanelUser` nor a panel `canAccess()` is
  refused nothing.** That is the behaviour every panel written before the
  contract existed already assumed, and it is why adding the interface is a
  tightening rather than a migration.
- **`canAccessPanel()` runs on every panel request**, before any page is built
  and before `Panel::boot()`. Keep it cheap, or cache inside it; a query per
  request is a query on every navigation.
- **The security page needs `password.confirm` to exist.** `SecuritySettings`
  runs behind `Illuminate\Auth\Middleware\RequirePassword`, which redirects
  there, and Fortify only registers it when your provider calls
  `Fortify::confirmPasswordView()`.
- **`panel:user` is non-interactive-safe.** Run without a TTY it answers the
  prompts with empty strings, so validation reports which options a script
  forgot rather than hanging on a pipe that will never reply.

## See also

- [Fortify Integration](fortify.md)
- [The `PanelUser` contract](panel-user-contract.md)
- [`panel:user`](panel-user-command.md), [CLI reference](../cli/panel-user.md)
- [Two-Factor Authentication](two-factor.md),
  [Email Code Challenge](email-code-challenge.md), [Passkeys](passkeys.md)
- [Profile Settings](profile.md), [Security Settings](security.md)
- [Panels: access rules](../panels/access.md)
- [Authorization](../concepts/authorization.md)
- [`HasPanelTenants`](../tenancy/has-panel-tenants.md)
- [Notification centre](../notifications/notification-center.md)
- [Migrations](../configuration/migrations.md)
