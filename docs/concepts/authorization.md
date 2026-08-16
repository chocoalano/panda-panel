# Authorization

Every panel screen answers two questions before it renders: may this user
enter the panel, and may they do this particular thing. The first is a
predicate on the panel; the second delegates to Laravel's Gate, so your
policies are the whole rule. Reach for this page when something is 403, or
when you want to be sure something ought to be.

## The two checks

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

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, User $record): bool
    {
        return $user->is_admin || $user->is($record);
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, User $record): bool
    {
        return $user->is_admin || $user->is($record);
    }

    public function delete(User $user, User $record): bool
    {
        return $user->is_admin && ! $user->is($record);
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_admin;
    }
}
```

A non-admin now gets 403 on `/admin`, and an admin gets 403 on
`/admin/users` only if the policy says so.

```php
$this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
$this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
```

## Panel access

```php
public function canAccess(Closure $callback): self          // Closure(?Authenticatable): bool
public function isAccessibleTo(?Authenticatable $user): bool
```

`isAccessibleTo()` asks two things, and both must agree:

```php
public function isAccessibleTo(?Authenticatable $user): bool
{
    if ($user instanceof PanelUser && ! $user->canAccessPanel($this)) {
        return false;
    }

    return $this->canAccess === null || ($this->canAccess)($user);
}
```

The panel's closure is a rule about *this panel* — "this one is for
administrators". `PandaPanel\Contracts\PanelUser` is a rule about *the user*:

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
        return $this->suspended_at === null;
    }
}
```

Written on the model it applies to every panel at once and cannot be forgotten
when a new one is added. A panel that says yes cannot overrule a model that
says no. A user model implementing neither contract is refused nothing.

`ResolvePanel` enforces it:

```php
abort_unless($panel->isAccessibleTo($request->user()), 403);
```

**403, never a redirect.** A signed-in user who is refused is not somebody who
needs to sign in again. Guests are redirected earlier, by `auth`.

`PanelManager::firstAccessibleTo(?Authenticatable $user): ?Panel` walks the
panels in id order using the same predicate, and that predicate is also what
`RedirectPanelHome` and the panel switcher use — so a panel a user would be
refused never appears as somewhere to go.

## Resource abilities

Every `can*` method on `PandaPanel\Resources\Resource` delegates to the Gate
through one private path.

| Method | Signature | Ability | Subject |
| --- | --- | --- | --- |
| `canViewAny` | `static canViewAny(): bool` | `viewAny` | the model class |
| `canView` | `static canView(Model $record): bool` | `view` | the record |
| `canCreate` | `static canCreate(): bool` | `create` | the model class |
| `canEdit` | `static canEdit(Model $record): bool` | `update` | the record |
| `canDelete` | `static canDelete(Model $record): bool` | `delete` | the record |
| `canDeleteAny` | `static canDeleteAny(): bool` | `deleteAny` | the model class |
| `canRestore` | `static canRestore(Model $record): bool` | `restore` | the record |
| `canRestoreAny` | `static canRestoreAny(): bool` | `restoreAny` | the model class |
| `canForceDelete` | `static canForceDelete(Model $record): bool` | `forceDelete` | the record |
| `canForceDeleteAny` | `static canForceDeleteAny(): bool` | `forceDeleteAny` | the model class |

```php
use App\Panels\Admin\Resources\Users\UserResource;

$this->actingAs($admin);

UserResource::canViewAny();        // true
UserResource::canDelete($member);  // true
UserResource::canDelete($admin);   // false — the policy refuses self-deletion
```

The `*Any` abilities are what a bulk action asks before it has a record to ask
about. Each record is then authorized individually before any is written, so a
selection containing one forbidden record changes nothing.

Override a `can*` method to state a rule the policy cannot:

```php
final class UserResource extends Resource
{
    public static function canCreate(): bool
    {
        return parent::canCreate() && User::query()->count() < 100;
    }
}
```

Route them back through `authorize()` rather than calling `Gate::allows()`
directly, or the strict-mode guarantee below stops holding for that ability:

```php
protected static function authorize(string $ability, Model|string $argument): bool
{
    return PolicyGate::allows($ability, $argument);
}
```

Where they are enforced: `ListRecords::render()` calls `canViewAny()` before
building anything, the view, create, and edit pages call theirs, and the
action endpoints ask again on execution. The sidebar asks too, but only to
decide what to draw.

## Relation abilities

`PandaPanel\Resources\RelationManager` asks two different families. Record
abilities resolve on the *related* model's policy; membership abilities
resolve on the *owner's*, with the related record as a second argument.

| Method | Signature | Ability | Policy |
| --- | --- | --- | --- |
| `canViewAny` | `static canViewAny(Model $owner): bool` | `viewAny` | related |
| `canView` | `static canView(Model $owner, Model $record): bool` | `view` | related |
| `canCreate` | `static canCreate(Model $owner): bool` | `create` | related |
| `canEdit` | `static canEdit(Model $owner, Model $record): bool` | `update` | related |
| `canDelete` | `static canDelete(Model $owner, Model $record): bool` | `delete` | related |
| `canRestore` | `static canRestore(Model $owner, Model $record): bool` | `restore` | related |
| `canForceDelete` | `static canForceDelete(Model $owner, Model $record): bool` | `forceDelete` | related |
| `canAttach` | `static canAttach(Model $owner): bool` | `attachAny` | owner |
| `canDetach` | `static canDetach(Model $owner, Model $record): bool` | `detach` | owner, `[$record]` |
| `canAssociate` | `static canAssociate(Model $owner): bool` | `associateAny` | owner |
| `canDissociate` | `static canDissociate(Model $owner, Model $record): bool` | `dissociate` | owner, `[$record]` |

```php
final class UserPolicy
{
    public function attachAny(User $user, User $owner): bool
    {
        return $user->is_admin;
    }

    public function detach(User $user, User $owner, Role $role): bool
    {
        return $user->is_admin && ! $role->is_system;
    }
}
```

## Pages and widgets

```php
public static function canAccess(): bool   // PandaPanel\Pages\Page, default true
public static function canView(): bool     // PandaPanel\Widgets\Widget, default true
```

```php
use PandaPanel\Pages\Page;

final class Settings extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()?->is_admin === true;
    }
}
```

`Page::render()` starts with `abort_unless(static::canAccess(), 403)`, so the
route enforces it, not only the sidebar. A widget's `canView()` is checked
before `toArray()` runs, so an unauthorized widget never executes its queries.

For a concern that needs a redirect rather than a yes-or-no — password
confirmation, a signed URL — use page middleware instead:

```php
use Illuminate\Auth\Middleware\RequirePassword;

protected static array $middleware = [RequirePassword::class];
```

## Actions

Actions carry their own checks, independent of the resource's.

| Method | Signature | Asked |
| --- | --- | --- |
| `authorize` | `authorize(Closure $callback): static` — `Closure(?Model): bool` | on render and on execution |
| `authorizeEachUsing` | `authorizeEachUsing(Closure $callback): static` — `Closure(Model): bool` | for every record of a bulk run |
| `visible` | `visible(Closure $callback): static` — `Closure(?Model): bool` | on render only |
| `isAuthorizedFor` | `isAuthorizedFor(?Model $record): bool` | |
| `isAuthorizedForEach` | `isAuthorizedForEach(Model $record): bool` | |
| `isVisibleFor` | `isVisibleFor(?Model $record): bool` | |

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->authorize(fn (?Model $record): bool => $record !== null && auth()->user()->can('approve', $record))
    ->authorizeEachUsing(fn (Model $record): bool => auth()->user()->can('approve', $record))
    ->visible(fn (?Model $record): bool => $record?->status === 'pending')
    ->action(fn (Model $record) => $record->update(['status' => 'approved']));
```

`visible()` hides without implying refusal; `authorize()` is the control.
`Action::toArray()` returns `null` for a record the action is hidden or
unauthorized for, so a button the user may not press is never rendered — and
`PanelActionController` asks again before executing, because a rendered button
is not a permission.

`executeBulk()` authorizes every selected record before touching any:

```php
foreach ($records as $record) {
    if (! $this->isAuthorizedForEach($record)) {
        throw new HttpException(403, …);
    }
}
```

All-or-nothing has to be decided before the first write, not discovered
halfway through.

## PolicyGate

Every ability the panel asks goes through one class.

```php
use PandaPanel\Support\PolicyGate;

/**
 * @param  Model|class-string  $subject
 * @param  list<mixed>  $arguments
 *
 * @throws PandaPanel\Exceptions\PanelAuthorizationException
 */
public static function allows(string $ability, Model|string $subject, array $arguments = []): bool
```

```php
PolicyGate::allows('update', $record);
PolicyGate::allows('detach', $owner, [$related]);
```

`Resource::authorize()` and `RelationManager::authorize()` both delegate here.
Neither calls `Gate::allows()` directly, which is what keeps the strict-mode
guarantee true for every ability the panel asks about — including the relation
abilities that have no `can*` method on a resource.

## Strict authorization

```php
$panel->strictAuthorization();          // off by default
$panel->hasStrictAuthorization();       // bool
```

Off, a missing policy is a 403: `Gate::allows()` denies when nothing answers,
which is the safe direction and indistinguishable from a policy that
considered the question and said no. On, both cases throw
`PandaPanel\Exceptions\PanelAuthorizationException`:

| Situation | Factory | Message |
| --- | --- | --- |
| No policy registered for the model | `missingPolicy($model, $ability)` | "No policy is registered for […], so the ability […] can only ever be denied." |
| Policy exists but lacks the method | `missingPolicyMethod($policy, $model, $ability)` | "The policy […] does not define […]" |

A policy defining `before()` is exempt, since it can answer for every ability.

It changes a 403 into a 500, which is why it is off by default. Turn it on in
development and in the test suite, where a forgotten policy reading as a
working authorization rule is the expensive failure.

### The quieter version

Without strict mode, `PandaPanel\Support\MissingPolicyNotice` logs the reason
once per model when a resource is dropped from navigation because its model
has no policy at all:

```
[panel] UserResource is not in the navigation because User has no policy, so
viewAny() is denied by default. Create one with `php artisan make:policy
UserPolicy --model=User`, or say so on the resource by overriding canViewAny().
```

Development only — `local`, `testing`, or debug mode — and never for a policy
that exists and said no, because that is a decision rather than a mistake.

## Tenancy

A tenant-scoped panel adds a third check, in `ResolveTenant`, before anything
is queried:

```php
abort_if($tenant === null, 404, 'No such tenant.');
abort_unless(Tenancy::allows($user, $tenant, $panel), 403);
```

```php
public static function allows(?Authenticatable $user, Model $tenant, Panel $panel): bool
{
    return $user instanceof HasPanelTenants
        && $user->canAccessPanelTenant($tenant, $panel);
}
```

Asked directly on the user model, on every request. Never derived from
`getPanelTenants()`: that list is built for a dropdown, and a security answer
must not change when a display decision does. A user model that does not
implement `HasPanelTenants` is refused every tenant, which is the correct
failure and a loud one.

## Nested resources

`ResolveParentRecord` resolves the parent through the *parent* resource's
`query()` and authorizes it with the parent's `canView()`:

```php
$record = $parentResource::query()->find($key);

if ($record === null || ! $parentResource::canView($record)) {
    return null;   // → 404
}
```

Without that, `/users/9/posts` would be a way to read user 9's children while
`/users/9` itself was refused.

## Notes

- **Navigation visibility is not access control.** The sidebar asks
  `canViewAny()` and `canAccess()` to decide what to draw. Routes, actions,
  pages, and widgets each authorize independently, and a hidden item reached
  by URL is refused by the route.
- **Every lookup goes through `Resource::query()`.** A key outside that scope
  resolves to nothing rather than to a record from elsewhere, so a tenant or
  permission scope narrows authorization as well as listing.
- **Actions resolve from the schema that declared them.** A table action is
  looked up in the table schema, an infolist action in the infolist. An action
  the resource never declared does not exist, whatever the request names.
- A resource missing from the sidebar has four possible causes and they look
  identical: no policy, a policy that says no, registration in a different
  panel, and a stale manifest. `strictAuthorization()` eliminates the first,
  `panel:clear` the last.
- The `verified` middleware a panel adds through `auth()` is inert until the
  user model implements `MustVerifyEmail`. It is declared on the route either
  way.

## See also

- [Panels](panels.md)
- [Panel Context](panel-context.md)
- [Request Lifecycle](request-lifecycle.md)
- [Routing](routing.md)
- [Resource Authorization](../resources/authorization.md)
- [Action Authorization](../actions/authorization.md)
- [Widget Authorization](../widgets/authorization.md)
- [Relation Policies](../relations/policies.md)
- [Troubleshooting 403s](../troubleshooting/authorization-403.md)
