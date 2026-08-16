# 403 responses

Every panel screen, action, widget and endpoint authorizes independently, so a 403 always names one
of a small number of rules. This page is how to find out which one, in order of how often each is
the answer. Reach for it when a signed-in user is refused and the sidebar or the screen does not
say why.

## Start here

A 403 comes from exactly one of five layers. Ask them in this order, from tinker:

```php
use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;

$user = User::query()->where('email', 'ada@example.test')->firstOrFail();

auth()->login($user);

app(PanelManager::class)->get('admin')->isAccessibleTo($user);   // 1. the panel
UserResource::canViewAny();                                      // 2. the resource
UserResource::canEdit($record);                                  // 3. this record
```

| Refused | Layer | Where the rule lives |
| --- | --- | --- |
| Every URL in the panel, including the dashboard | Panel access | `Panel::canAccess()` and `PanelUser::canAccessPanel()` |
| One resource, every route of it | `viewAny` | the model's policy |
| One record | `view` / `update` / `delete` … | the model's policy |
| One page or widget | `Page::canAccess()`, `Widget::canView()` | the class |
| One button | `Action::authorize()` | the action |

A **guest** is never given a 403 for a panel URL — they are redirected to a login. If a guest sees
a 403, the request reached something outside the panel's auth stack; see
[Login redirects](login-redirects.md).

## 1. The panel refuses the user

Two independent questions, and both must agree. A panel that says yes cannot overrule a user model
that says no.

```php
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
            ->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true);
    }
}
```

```php
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

| Member | Signature | Notes |
| --- | --- | --- |
| `canAccess` | `Panel::canAccess(Closure $callback): self` | `fn (?Authenticatable $user): bool` |
| `isAccessibleTo` | `Panel::isAccessibleTo(?Authenticatable $user): bool` | asks the contract first, then the closure |
| `canAccessPanel` | `PanelUser::canAccessPanel(Panel $panel): bool` | on the user model, applies to every panel |

A user model implementing neither contract is refused nothing, which is what every panel written
before the contract existed already assumed.

The console says the same thing at creation time, and names which of the two said no:

```bash
php artisan panel:user --panel=admin
```

```text
  WARN  They cannot reach the Administrator panel yet — the panel's own canAccess() says no.
```

A privilege flag is usually not mass-assignable — deliberately, since a fillable `is_admin` is a
privilege anybody can grant themselves through a form post — so granting it is an explicit write:

```php
$user->forceFill(['is_admin' => true])->save();
```

## 2. The resource has no policy

This is the most common 403 in a new panel, and the one that looks like nothing at all: the resource
is simply absent from the sidebar and its URL is refused.

`Gate::allows()` denies when no policy exists. That is correct — deny is the safe direction — and
indistinguishable from a policy that considered the question and said no.

```bash
php artisan make:policy ProductPolicy --model=Product
```

In development the panel says so once per model, from the navigation builder:

```text
[panel] ProductResource is not in the navigation because Product has no policy, so viewAny()
is denied by default. Create one with `php artisan make:policy ProductPolicy --model=Product`,
or say so on the resource by overriding canViewAny(). Panel::strictAuthorization() turns this
into an exception everywhere the panel asks, which is worth having in development.
```

`PandaPanel\Support\MissingPolicyNotice` writes it:

| Method | Signature | Notes |
| --- | --- | --- |
| `reportIfMissing` | `static reportIfMissing(string $resource, string $model): void` | logs at debug level, once per model |
| `forget` | `static forget(): void` | clears the reported set; for tests that build navigation many times |
| `expectedPolicy` | `static expectedPolicy(string $model): string` | `App\Models\Product` → `App\Policies\ProductPolicy` |

```php
use PandaPanel\Support\MissingPolicyNotice;

MissingPolicyNotice::expectedPolicy(App\Models\Product::class);
// 'App\Policies\ProductPolicy'
```

It is silent in production, and silent when a policy exists at all — a policy that said no is a
decision, not a mistake. It reports only when `app()->hasDebugModeEnabled()` is true or the
environment is `local` or `testing`.

## 3. The abilities each panel operation asks

Every `can*` on `PandaPanel\Resources\Resource` delegates to the Gate through one method, so a
policy is the whole of the answer.

| Method | Signature | Ability |
| --- | --- | --- |
| `canViewAny` | `static canViewAny(): bool` | `viewAny` |
| `canView` | `static canView(Model $record): bool` | `view` |
| `canCreate` | `static canCreate(): bool` | `create` |
| `canEdit` | `static canEdit(Model $record): bool` | `update` |
| `canDelete` | `static canDelete(Model $record): bool` | `delete` |
| `canDeleteAny` | `static canDeleteAny(): bool` | `deleteAny` |
| `canRestore` | `static canRestore(Model $record): bool` | `restore` |
| `canRestoreAny` | `static canRestoreAny(): bool` | `restoreAny` |
| `canForceDelete` | `static canForceDelete(Model $record): bool` | `forceDelete` |
| `canForceDeleteAny` | `static canForceDeleteAny(): bool` | `forceDeleteAny` |

The `*Any` abilities are what a bulk action asks before it has a record to ask about. Each record is
then authorized individually before any is written, so a selection containing one forbidden record
changes nothing — which is why a bulk delete can answer 403 while every row looks editable.

Override a `can*` method through the same funnel, or strict mode stops covering it:

```php
use Illuminate\Database\Eloquent\Model;

public static function canDelete(Model $record): bool
{
    return ! $record->is_locked && parent::canDelete($record);
}
```

### Relation managers ask two policies

Reading and writing the related *record* are abilities on that record's policy. Attaching and
detaching are abilities on the **owner's** policy, because whether a tag may be pinned to a post is
the post's business, not the tag's.

| Method | Signature | Ability | Asked of |
| --- | --- | --- | --- |
| `canViewAny` | `static canViewAny(Model $owner): bool` | `viewAny` | related model |
| `canView` | `static canView(Model $owner, Model $record): bool` | `view` | related record |
| `canCreate` | `static canCreate(Model $owner): bool` | `create` | related model |
| `canEdit` | `static canEdit(Model $owner, Model $record): bool` | `update` | related record |
| `canDelete` | `static canDelete(Model $owner, Model $record): bool` | `delete` | related record |
| `canRestore` | `static canRestore(Model $owner, Model $record): bool` | `restore` | related record |
| `canForceDelete` | `static canForceDelete(Model $owner, Model $record): bool` | `forceDelete` | related record |
| `canAttach` | `static canAttach(Model $owner): bool` | `attachAny` | **owner** |
| `canDetach` | `static canDetach(Model $owner, Model $record): bool` | `detach` | **owner**, with the record as a second argument |
| `canAssociate` | `static canAssociate(Model $owner): bool` | `associateAny` | **owner** |
| `canDissociate` | `static canDissociate(Model $owner, Model $record): bool` | `dissociate` | **owner**, with the record |

A `PostPolicy` therefore needs `attachAny(User $user, Post $post)` and
`detach(User $user, Post $post, Tag $tag)` for an attach/detach relation manager to work. Missing
them is the usual cause of "the table renders and the Attach button 403s".

## 4. Turn a missing policy into an exception

```php
$panel->strictAuthorization();          // off by default
$panel->hasStrictAuthorization();       // bool
```

Under strict mode `PandaPanel\Support\PolicyGate` asserts that a policy can actually answer before
asking it:

```php
use PandaPanel\Support\PolicyGate;

PolicyGate::allows('update', $record);              // ability, subject
PolicyGate::allows('detach', $post, [$tag]);        // extra policy arguments
```

| Method | Signature |
| --- | --- |
| `allows` | `static allows(string $ability, Model\|string $subject, array $arguments = []): bool` |

Two failures, both `PandaPanel\Exceptions\PanelAuthorizationException`:

```text
No policy is registered for [App\Models\Product], so the ability [viewAny] can only ever be
denied. Register one, or turn off strictAuthorization() for this panel.
```

```text
The policy [App\Policies\ProductPolicy] for [App\Models\Product] does not define [deleteAny],
so that ability can only ever be denied. Add the method, or turn off strictAuthorization()
for this panel.
```

A policy defining `before()` is exempt from the second check, since it can answer for every ability.

This is one check rather than one per caller: `Resource::authorize()` and
`RelationManager::authorize()` both delegate to `PolicyGate`, which is what keeps the guarantee true
for the relation abilities that have no `can*` method on a resource.

Turn it on in development and leave it off in production, or turn it on everywhere and accept that a
missing policy is a 500 rather than a 403 — both are defensible; the point is that a forgotten
policy stops reading as a working authorization rule.

## 5. Pages, widgets and actions

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

```php
use PandaPanel\Widgets\StatsWidget;

final class RevenueStats extends StatsWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->can('view-revenue') === true;
    }
}
```

| Member | Signature | Default |
| --- | --- | --- |
| `Page::canAccess` | `static canAccess(): bool` | `true` |
| `Widget::canView` | `static canView(): bool` | `true` |
| `Action::authorize` | `authorize(Closure $callback): static` | `fn (?Model $record): bool` |
| `Action::authorizeEachUsing` | `authorizeEachUsing(Closure $callback): static` | `fn (Model $record): bool`, per record of a bulk run |
| `Action::isAuthorizedForEach` | `isAuthorizedForEach(Model $record): bool` | true when no per-record callback was set |
| `Action::visible` | `visible(Closure $callback): static` | hides without implying forbidden |

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->authorize(static fn (?Model $record): bool => auth()->user()?->can('approve') === true)
    ->authorizeEachUsing(static fn (Model $record): bool => $record->status === 'pending')
    ->action(static fn (Model $record) => $record->update(['status' => 'approved']));
```

`authorize()` answers for the action; `authorizeEachUsing()` answers for each record it is about to
touch. Without the second, a bulk action inherits only the collective check — and "may run this" is
not "may run this on these".

`visible()` is not authorization. It hides a button; the endpoint asks `authorize()` again on
execution, whatever the row said.

## 6. Endpoints that answer 403 for their own reasons

| Endpoint | Rule |
| --- | --- |
| `panel.{id}.uploads` | The form the field belongs to. `page=create` asks `create`; `page=edit` asks `update` on the named record; a relation form asks the relation manager's abilities; an action's form asks the action. `page` is an allowlist — an unrecognised value is a 422, not the create form. |
| `panel.{id}.actions.*` | The action's own `authorize()`, then the record ability behind whatever it does |
| Integrations screen | Three things: the resource opted in with `integrations()->isEnabled(true)`, the user passes the resource's `viewAny`, and the user passes the `manage-panel-integrations` gate — which **denies when no gate is defined**, so an application that has not decided who may do this has decided nobody may |
| Any tenant-scoped route | `ResolveTenant`: a tenant that cannot be identified is a **404**; a tenant the user may not enter is a **403** |
| `panel.{id}.notifications.*` | Scoped to `$request->user()`'s own rows, so another user's id matches nothing rather than 403ing |

```php
use Illuminate\Support\Facades\Gate;

// AppServiceProvider::boot()
Gate::define('manage-panel-integrations', static fn ($user): bool => $user->is_admin === true);
```

## Testing a 403

Assert the refusal rather than the status code alone — a route that 403s because it does not exist
proves nothing.

```php
use PandaPanel\Exceptions\PanelAuthorizationException;

it('refuses every route of the resource to a user without the policy', function (): void {
    $this->actingAs($editor)->get('/admin/users')->assertForbidden();
    $this->actingAs($editor)->post('/admin/users/create')->assertForbidden();
});

it('names the missing policy under strict authorization', function (): void {
    panel('admin')->strictAuthorization();

    expect(fn () => ProductResource::canViewAny())
        ->toThrow(PanelAuthorizationException::class);
});
```

The action helpers ask the same question the row does — visible *and* authorized:

```php
panelRecordActions(UserResource::class)->assertExists('edit');
panelBulkActions(UserResource::class)->assertCanNotRun('delete');
panelInfolistActions(UserResource::class)->assertVisible('impersonate', $user);
panelTableActions(UserResource::class)->call('purgeUnverified');
```

`call()` checks authorization first and fails the test rather than skipping it: running an action
the user may not run would prove the handler works and nothing about whether it is reachable.

## Notes

- **Hiding a navigation item is not an access control.** Every route, action, page and widget
  authorizes independently, and the sidebar is a convenience built from the same answers.
- **A record from outside `Resource::query()` is a 404, not a 403.** Record lookups go through the
  resource's own query, so an out-of-scope key does not resolve — including a key belonging to
  another tenant.
- **`canViewAny()` guards global search too.** A resource the user cannot view is never queried by
  the palette.
- **A missing policy method is a 403 without strict mode, at every layer.** Laravel's gate denies an
  ability the policy does not implement, and the panel has no way to distinguish that from a refusal.
- **`before()` on a policy answers for every ability**, which is why strict mode exempts it. That
  also means a `before()` returning `false` refuses everything, including `viewAny`, and the
  resource disappears from the sidebar with no log line.
- **The panel's own `canAccess()` runs on every request into the panel**, so keep it cheap; a query
  per request per user is a query per page view.
- **Boot callbacks run after the access check.** A user refused the panel never triggers its
  `bootUsing()` work.

## See also

- [Authorization concepts](../concepts/authorization.md)
- [Resource authorization](../resources/authorization.md), [action authorization](../actions/authorization.md)
- [Widget authorization](../widgets/authorization.md), [page authorization](../pages-navigation/authorization.md)
- [Relation policies](../relations/policies.md)
- [Panel access](../panels/access.md), [`panel:user`](../cli/panel-user.md)
- [Testing authorization](../testing/authorization.md), [negative security tests](../testing/negative-security-tests.md)
- [Tenancy scope leaks](tenancy-scope-leaks.md), [login redirects](login-redirects.md)
- [Common install problems](../getting-started/common-install-problems.md)
