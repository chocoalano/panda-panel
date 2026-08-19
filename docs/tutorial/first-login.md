# 4 · First account, first login

**Goal:** an account that can actually reach `/admin`, and an understanding of the two rules that
decide whether it can.

A fresh install has a panel, routes and a login page — and no user. The usual next move is to open
`tinker` and write a `create()` call from memory, guessing which columns this application's user
model has. There is a command for it instead.

## Do this

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

  INFO  They can sign into the Admin panel at admin.
```

Non-interactively — for a provisioning script or CI — all three options are required, because every
prompt is skipped when the input is not interactive:

```bash
php artisan panel:user \
  --name="$ADMIN_NAME" \
  --email="$ADMIN_EMAIL" \
  --password="$ADMIN_PASSWORD" \
  --panel=admin \
  --no-interaction
```

::: warning `--password=` lands in your shell history
And in the process list while it runs. Prefer the prompt, or an environment variable, on any
machine you share.
:::

## Which model it creates

Not `App\Models\User`, and deliberately so. The command walks your auth config:

```text
--guard=  or  auth.defaults.guard
      ↓
auth.guards.{guard}.provider
      ↓
auth.providers.{provider}.model
```

A project with `App\Models\Admin` behind a second guard gets its admin; a project that renamed the
default gets that. Guessing `App\Models\User` would work for most projects and fail *silently* —
creating a row in the wrong table — for exactly the ones that need this most.

What it writes:

```php
$user->forceFill([
    'name' => $attributes['name'],
    'email' => $attributes['email'],
    'password' => Hash::make($attributes['password']),
    'email_verified_at' => now(),
])->save();
```

`forceFill` rather than `create`, because the point is to work against a model whose `$fillable` the
command has never seen. `email_verified_at` is set because an account created from the console has,
by definition, been verified by whoever ran the command — leaving it null would put them straight
into the verify-email wall, on a panel whose `->auth()` includes `verified`.

## The access report

After the account exists, the command asks the panel whether it can be entered. That is **two
independent questions, and both must agree**:

```php
// 1. A rule about the panel, in its provider:
->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true)

// 2. A rule about the account, on the user model:
final class User extends Authenticatable implements PandaPanel\Contracts\PanelUser
{
    public function canAccessPanel(PandaPanel\Core\Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }
}
```

Neither can loosen the other. Because they live in different files, the report names *which* one
said no:

```text
WARN  They cannot reach the Admin panel yet — the panel's own canAccess() says no.
      Set whatever that rule reads before signing in.
```

The scaffolded panel from step 3 calls neither, so both questions default to yes and your new
account can enter. You will add a real rule in step 8.

::: tip It reports; it never refuses
An account with `is_admin` still to be set is a normal intermediate state, not a mistake to block
on. The row is created either way.
:::

## Sign in

```bash
php artisan serve
```

Open `http://localhost:8000/admin`. A guest on a panel URL is sent to that panel's own login when it
has one, and to `route('login')` otherwise — which is Laravel's default, so this adds a case rather
than replacing one. Sign in with the account you just made.

## What you are looking at

| | |
| --- | --- |
| `/admin` | `PandaPanel\Pages\Dashboard` — an ordinary page whose widgets come from the panel's registry. Empty for now |
| The sidebar | Built per request from the panel's registries. There is no hardcoded array anywhere, which is why it has nothing in it yet |
| `/admin/settings/profile` | Account pages, on by default. Turn them off with `settings(false)` |
| `/admin/settings/security` | Two-factor and passkeys, behind a password confirmation |
| The search box | `/admin/search`, answering JSON. It finds nothing until a resource declares itself searchable |

Every route name is `panel.{id}.*`, so `panel.admin.dashboard`, `panel.admin.actions.record`, and so
on. That predictability is what lets Wayfinder and server-side URL generation stay in step.

## Check it worked

You are signed in and looking at an empty dashboard inside the panel's own shell — not the starter
kit's. If you see *your* application sidebar instead, that is the `app.ts` layout assignment from
[step 2](project), not an authentication problem.

## If it did not work

| Symptom | Meaning | Fix |
| --- | --- | --- |
| **404** on `/admin` | The provider is not in `config/panda-panel.php` | Add it — nothing scans for it |
| **403** after signing in | One of the two access rules said no | Check `canAccess()` first, then `canAccessPanel()` |
| Redirected to `/login` forever | The session guard is not the guard the account was created under | `php artisan panel:user --guard=web` |
| Sent to a verify-email wall | `->auth()` includes `verified` and the row has a null `email_verified_at` | The command sets it; a user made another way may not have it |
| No access line in the report at all | `--panel=` named a panel id that does not exist | Check with `php artisan route:list --name=panel.` |

::: details Promoting an account later
A privilege flag that is mass-assignable is a privilege anyone can grant themselves by adding a
field to a form post, so keep `is_admin` out of `$fillable` and write it explicitly:

```bash
php artisan tinker
```

```php
use App\Models\User;

User::query()->where('email', 'ada@example.com')->first()
    ->forceFill(['is_admin' => true])->save();
```
:::

## Next

An empty panel is not much of a panel. Give it something to manage.

**→ [5 · Your first resource](resource)**

## See also

- [Creating the first user](/getting-started/first-user) — every option, and both failure modes
- [Opening your first panel](/getting-started/first-panel) — the provider and its URLs in full
- [Panel access](/panels/access) — `canAccess()` in detail
- [Troubleshooting: 403 from a panel](/troubleshooting/authorization-403)
