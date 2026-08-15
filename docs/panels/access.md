# Panel Access Rules

Who may enter a panel at all, as opposed to what they may do once inside. It is one question, asked by middleware on every request into the panel, and answered by two independent rules that must agree: a predicate on the panel, and a method on the user model. Everything narrower — which resource, which record, which action — is the Gate's job and is documented under [Authorization](../concepts/authorization.md).

## A rule about the panel

```php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
```

A signed-in user who fails this gets **403**, not a redirect. A guest never reaches the check on a panel that called `auth()`: the auth middleware redirects first.

```php
public function canAccess(Closure $callback): self     // Closure(?Authenticatable): bool
public function isAccessibleTo(?Authenticatable $user): bool
```

`canAccess()` holds one closure; calling it twice replaces the first. The parameter is nullable because `isAccessibleTo()` is also asked outside a request — by the panel switcher, and by `firstAccessibleTo()` when deciding where to send somebody after signing in.

## A rule about the user

The other half lives on the user model:

```php
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

`PandaPanel\Contracts\PanelUser` declares exactly one method:

```php
public function canAccessPanel(Panel $panel): bool;
```

Use the closure for a rule about *this panel* — "this one is for administrators". Use the contract for a rule about *the account* — suspended, no tenant, not onboarded. Written on the model it applies to every panel at once and cannot be forgotten when a fourth panel is added.

## Both must agree

`Panel::isAccessibleTo()` asks the contract first and the closure second:

```php
$panel->isAccessibleTo($user);
// false when the user model implements PanelUser and says no
// false when the panel's own predicate says no
// true when neither refuses
```

A panel that says yes cannot overrule a user model that says no, and a permissive user model cannot loosen a panel's predicate. A user model implementing neither is refused nothing, which is what every panel written before the contract existed already assumed.

```php
// A panel that refuses everybody stays refused, however permissive the model is.
$panel = Panel::make('both')->canAccess(static fn (?Authenticatable $user): bool => false);

$panel->isAccessibleTo($permissiveUser);   // false
```

## Where the check runs

`PandaPanel\Http\Middleware\ResolvePanel` is appended last in the panel's middleware stack, after `auth` and `verified`, so `$request->user()` is populated when the predicate runs. In order, it:

1. resolves the panel by the id baked into the middleware parameter;
2. binds it as the current panel;
3. `abort_unless($panel->isAccessibleTo($request->user()), 403)`;
4. runs the panel's boot callbacks and its plugins' `boot()`.

Step 4 is after step 3 on purpose: a user refused the panel never triggers its boot work.

Because the check is middleware, it covers every route the panel registers — pages, resource pages, the action endpoints, the search endpoint, uploads, exports. There is no page you can forget to protect.

## What access does *not* cover

Panel access is a door, not a permission system. Once inside:

| Question | Answered by |
| --- | --- |
| May this user see this resource in the sidebar and list it? | `Resource::canViewAny()` → the `viewAny` policy ability |
| May they open this record? | `Resource::canView($record)` → `view` |
| May they create, edit, delete? | `canCreate()`, `canEdit()`, `canDelete()` and the `*Any` bulk variants |
| May they open this standalone page? | `Page::canAccess()`, enforced on the route |
| May they see this widget? | `Widget::canView()`, checked before `data()` runs |
| May they run this action? | The action's own authorization |

Each of those is checked independently, on the server, at the point the thing is used. Hiding a navigation item or a button is a convenience, never the control.

```php
use PandaPanel\Pages\Page;

final class Reports extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-reports') === true;
    }
}
```

## Strict authorization

A missing policy reads exactly like a policy that said no. Turn that into a loud failure while developing:

```php
$panel->strictAuthorization();          // strictAuthorization(bool $strictAuthorization = true): self
panel('admin')->hasStrictAuthorization();   // bool, false by default
```

With it on, a resource whose model has no registered policy — or whose policy is missing the ability being checked — throws `PandaPanel\Exceptions\PanelAuthorizationException` instead of denying. A policy defining `before()` is exempt, since it can answer for every ability. Every `can*()` on `Resource` routes through one `authorize()` call, so this is a single check rather than eight.

It is off by default because it turns a 403 into a 500. In development the failure names the model and the ability; in production a denial is the safer answer.

## Guests

A panel that calls `auth()` sends guests to a login. Which login depends on whether the panel has one of its own:

```php
$panel->login();   // a login page at /{path}/login, carrying this panel's brand
```

With `register_guest_redirect` on (the default), `PandaPanel\Support\PanelLoginRedirect` sends a guest who opened a panel URL to that panel's login when it has one, and to `route('login')` otherwise. The intended URL is kept, so they land where they were going.

A panel *without* `auth()` is public. `canAccess()` still runs, and receives `null` for a guest:

```php
$panel->canAccess(static fn (?Authenticatable $user): bool => $user !== null || app()->isLocal());
```

## Access and the switcher

`SharePanelData` filters the panel switcher by `isAccessibleTo()`, so the list a user sees is exactly the set of panels they may enter. `PanelManager::firstAccessibleTo()` uses the same predicate to decide where a signed-in user lands when the request names no panel.

```php
use PandaPanel\Core\PanelManager;

app(PanelManager::class)->firstAccessibleTo($user);   // ?Panel, in registration order
```

Both call the predicate outside a panel request. Keep it cheap and keep it free of request state — a `canAccess()` that reads `request()->route()` will be asked questions it cannot answer.

## Testing it

```php
it('keeps a normal user out of the admin panel', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('never boots a panel the user may not enter', function (): void {
    $ran = false;

    app(PanelManager::class)->get('admin')->bootUsing(function () use (&$ran): void {
        $ran = true;
    });

    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();

    expect($ran)->toBeFalse();
});
```

## Notes

- 403 rather than a redirect is deliberate. A redirect would tell an unauthorized user that a different panel exists, and would loop for a user with no panel at all.
- `canAccess()` runs on every request into the panel. A database query in it is a query on every page load; cache it on the user model if it is expensive.
- The closure is never serialized. `toSharedArray()` carries no trace of it, and `panel:cache` stores class names only.
- A user model may implement `PanelUser` and still be refused by the panel predicate, and vice versa. When debugging a 403, check both.

## See also

- [Middleware and Guards](middleware.md)
- [Multi-Panel Applications](multi-panel.md)
- [Panel API Reference](api.md)
- [Authorization](../concepts/authorization.md)
- [Resource Authorization](../resources/authorization.md)
- [Page Authorization](../pages-navigation/authorization.md)
- [Widget Authorization](../widgets/authorization.md)
- [The PanelUser Contract](../authentication/panel-user-contract.md)
- [Debugging a 403](../troubleshooting/authorization-403.md)
