# The `PanelUser` Contract

One method on your user model that decides which panels the account may enter.
It is the half of panel access that belongs to the *user* — suspended, not
onboarded, belonging to no tenant — as opposed to the half that belongs to the
*panel*. You reach for it when a rule should apply to every panel at once and
cannot be forgotten when a fourth one is added.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->suspended;
    }
}
```

```bash
curl -sI -b session.txt https://example.test/admin | head -1
# HTTP/1.1 403 Forbidden   — for a suspended account, on every panel
```

Nothing else to register. The contract is discovered by `instanceof`, so
implementing it is the whole wiring.

## The interface

`PandaPanel\Contracts\PanelUser` declares exactly one method:

```php
namespace PandaPanel\Contracts;

use PandaPanel\Core\Panel;

interface PanelUser
{
    public function canAccessPanel(Panel $panel): bool;
}
```

| Parameter | Type | Notes |
| --- | --- | --- |
| `$panel` | `PandaPanel\Core\Panel` | The panel being entered, so one method can answer differently per panel |
| returns | `bool` | `false` is a 403, never a redirect |

The panel is passed in, which is what lets a single method cover several:

```php
public function canAccessPanel(Panel $panel): bool
{
    return match ($panel->getId()) {
        'admin' => $this->is_admin && ! $this->suspended,
        'portal' => $this->hasVerifiedEmail(),
        default => ! $this->suspended,
    };
}
```

The panel object is the full `Panel`, so anything it exposes is available —
`getId()`, `getPath()`, `getName()`, `getTenantModel()` and the rest. Prefer
`getId()`: it is stable, while a path can move.

## Where it is asked

```php
public function isAccessibleTo(?Authenticatable $user): bool
{
    if ($user instanceof PanelUser && ! $user->canAccessPanel($this)) {
        return false;
    }

    return $this->canAccess === null || ($this->canAccess)($user);
}
```

`Panel::isAccessibleTo()` is the only caller, and it is reached from four
places:

| Caller | When | Effect of `false` |
| --- | --- | --- |
| `PandaPanel\Http\Middleware\ResolvePanel` | every request into the panel | `abort(403)` |
| `PandaPanel\Http\Middleware\SharePanelData` | building the panel switcher | the panel is not listed |
| `PandaPanel\Core\PanelManager::firstAccessibleTo()` | deciding where a signed-in user lands | the panel is skipped |
| `php artisan panel:user` | reporting on a new account | a warning naming this method |

`ResolvePanel` runs last in the panel's middleware stack, after `auth` and
`verified`, so `$request->user()` is populated before the question is asked. And
it asks *before* booting the panel:

```php
$this->manager->setCurrentPanel($panel);

abort_unless($panel->isAccessibleTo($request->user()), 403);

// After the access check, never before: a user who is refused the panel must
// not be able to trigger its boot work.
$panel->boot();
```

Because the check is middleware, it covers every route a panel registers — pages,
resource pages, the action endpoints, search, uploads, exports. There is no page
you can forget to protect.

## Both rules must agree

The other half is a closure on the panel:

```php
use Illuminate\Contracts\Auth\Authenticatable;

$panel->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true);
```

```php
public function canAccess(Closure $callback): self
public function isAccessibleTo(?Authenticatable $user): bool
```

| Rule | Right for | Lives in |
| --- | --- | --- |
| `Panel::canAccess()` | "this panel is for administrators" | the panel provider |
| `PanelUser::canAccessPanel()` | "this account is suspended" | the user model |

Both are asked and both must agree. A panel that says yes cannot overrule a user
model that says no, and a permissive user model cannot loosen a panel's
predicate:

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('both')->canAccess(static fn (?Authenticatable $user): bool => false);

$panel->isAccessibleTo($permissiveUser);   // false — the panel refused
```

A user model that implements neither is refused nothing, which is what every
panel written before the contract existed already assumed.

## Guests never reach it

`isAccessibleTo()` is typed `?Authenticatable`, and `null instanceof PanelUser`
is false — so for a guest the contract is skipped entirely and only the panel's
own closure runs:

```php
$panel->isAccessibleTo(null);   // asks the closure with null, never the contract
```

That matters on a panel with its own login. The panel's guest routes carry
`ResolvePanel` too, so a closure like
`fn (?Authenticatable $u) => $u?->is_admin === true` answers false for a guest and
403s the login page. Admit the guest explicitly:

```php
->canAccess(static fn (?Authenticatable $user): bool => $user === null || $user->is_admin === true)
```

The contract has no such hazard, because it is never asked about a guest.

## What it is not for

Panel access is a door, not a permission system. Once inside, every narrower
question is asked separately and on the server:

| Question | Answered by |
| --- | --- |
| May they list this resource? | `Resource::canViewAny()` → the `viewAny` policy ability |
| May they open this record? | `Resource::canView($record)` → `view` |
| May they create, edit, delete? | `canCreate()`, `canEdit()`, `canDelete()` |
| May they open this standalone page? | `Page::canAccess()`, enforced on the route |
| May they see this widget? | `Widget::canView()`, before `data()` runs |

A `canAccessPanel()` that tries to express "may edit users" is in the wrong
place — see [Authorization](../concepts/authorization.md).

## Testing it

```php
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

it('lets a user model refuse a panel for itself', function (): void {
    $user = new class extends User implements PanelUser
    {
        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return $panel->getId() !== 'door';
        }
    };

    $user->forceFill(User::factory()->make()->getAttributes())->save();

    $this->actingAs($user)->get('/door')->assertForbidden();
});

it('refuses nothing to a user model that implements neither', function (): void {
    expect($panel->isAccessibleTo(User::factory()->make()))->toBeTrue();
});
```

Both are from `tests/Feature/Panel/PanelAuthTest.php`. Asking the panel directly
is often enough and needs no HTTP:

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->get('admin')->isAccessibleTo($user);        // bool
app(PanelManager::class)->firstAccessibleTo($user)?->getId();         // where they land
```

## Gotchas

- **It runs on every request into the panel.** A database query inside it is a
  query on every page load, every action POST, and every widget refresh. Cache it
  on the model, or read a column you already loaded.
- **It is called outside a request too.** The switcher and
  `firstAccessibleTo()` both ask it, and `panel:user` asks it from the console. A
  method that reads `request()->route()` will be asked a question it cannot
  answer.
- **`false` is 403, not a redirect.** Deliberate: a redirect would tell an
  unauthorized user that a different panel exists, and would loop for a user with
  no panel at all.
- **It also guards the panel's own auth pages.** They carry `ResolvePanel`, so a
  signed-in user this method refuses gets 403 on `/{panel}/login` and
  `/{panel}/verify-email` as well. A rule like `hasVerifiedEmail()` therefore
  cannot be combined with the panel's own verification notice — use the
  `verified` middleware for that. See
  [Email Verification](email-verification.md).
- **Refusing is not hiding.** Navigation items are filtered by their own
  authorization; this method only decides whether the panel opens at all.
- **One method, every panel.** That is the point — but it means a rule that only
  ever applies to one panel is better written as that panel's `canAccess()`
  closure, where it is visible in the provider that owns it.

## See also

- [Panel Access Rules](../panels/access.md) — `canAccess()` in full
- [User Model Requirements](user-model.md)
- [panel:user](panel-user-command.md) — the command that reports on this rule
- [Email Verification](email-verification.md)
- [Authorization](../concepts/authorization.md)
- [Debugging a 403](../troubleshooting/authorization-403.md)
- [Contracts API Reference](../api/contracts.md)
