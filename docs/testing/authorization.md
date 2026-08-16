# Testing Authorization

A panel hides what a user may not do. These tests are how you prove the hiding is not what enforces it. Authorization is asked in six places — the panel door, the page, the resource policy, the action, the widget, the relation manager — and each answers a different question with a different failure mode. This page covers how to test each one, at the route level and at the class level, and why the route level is the one that matters.

## A minimal working example

```php
<?php

declare(strict_types=1);

use App\Models\User;

it('lets an administrator in and refuses everybody else', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get('/admin/users')
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertForbidden();

    $this->get('/admin/users')->assertRedirect('/login');
});
```

Three assertions and three different answers. A signed-in user who is refused gets **403**; a guest gets a **redirect** to sign in; a record outside the resource's scope gets **404**. Asserting the right one is part of the test.

## The layers

| Layer | Asked by | Declared as | Refusal |
| --- | --- | --- | --- |
| Panel door | `ResolvePanel` middleware | `Panel::canAccess(Closure)` and `PanelUser::canAccessPanel()` | `abort(403)` before any page is built |
| Page | `Page::render()` | `static canAccess(): bool` | `abort_unless(…, 403)` |
| Resource | `Resource::can*()` → `PolicyGate` → `Gate` | a Laravel policy | 403 from the controller |
| Resource scope | `Resource::query()` | an overridden `query()`, or tenancy | 404 — the record does not exist here |
| Action | `Action::isAuthorizedFor()` / `isAuthorizedForEach()` | `authorize()`, `authorizeEachUsing()` | absent from the row; 403 at the endpoint |
| Widget | `WidgetCollection` | `static canView(): bool` | omitted from the page's widgets |
| Relation manager | `RelationManager::can*()` → `PolicyGate` | the owner's or the related model's policy | 403 at the relation endpoint |

They compose rather than override. The panel door is asked first, so a user refused there never reaches a resource policy — worth knowing when a test asserts a policy and passes for the wrong reason.

## The panel door

Two questions, and both must agree. `Panel::isAccessibleTo()`:

```php
if ($user instanceof PanelUser && ! $user->canAccessPanel($this)) {
    return false;
}

return $this->canAccess === null || ($this->canAccess)($user);
```

A panel that says yes cannot overrule a user model that says no. Test both halves:

```php
use PandaPanel\Core\Panel;

it('refuses a user the panel itself does not admit', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('asks the panel predicate directly', function (): void {
    $panel = panel('admin');

    expect($panel->isAccessibleTo(User::factory()->create(['is_admin' => true])))->toBeTrue()
        ->and($panel->isAccessibleTo(User::factory()->create()))->toBeFalse()
        ->and($panel->isAccessibleTo(null))->toBeFalse();
});
```

And that the middleware is where it happens — the panel route stack is worth asserting once:

```php
use Illuminate\Support\Facades\Route;

$middleware = Route::getRoutes()->getByName('panel.app.dashboard')?->gatherMiddleware() ?? [];

expect($middleware)->toContain('verified');
```

## Resources

Ten static methods, each delegating to `Resource::authorize()`, which delegates to `PolicyGate::allows()`:

| Method | Signature | Policy ability | Argument |
| --- | --- | --- | --- |
| `canViewAny` | `static canViewAny(): bool` | `viewAny` | the model class |
| `canView` | `static canView(Model $record): bool` | `view` | the record |
| `canCreate` | `static canCreate(): bool` | `create` | the model class |
| `canEdit` | `static canEdit(Model $record): bool` | `update` | the record |
| `canDelete` | `static canDelete(Model $record): bool` | `delete` | the record |
| `canDeleteAny` | `static canDeleteAny(): bool` | `deleteAny` | the model class |
| `canRestore` | `static canRestore(Model $record): bool` | `restore` | the record |
| `canForceDelete` | `static canForceDelete(Model $record): bool` | `forceDelete` | the record |
| `canRestoreAny` | `static canRestoreAny(): bool` | `restoreAny` | the model class |
| `canForceDeleteAny` | `static canForceDeleteAny(): bool` | `forceDeleteAny` | the model class |

Note that `canEdit()` asks `update`: the panel's vocabulary and the policy's are not the same word, and a test written against the policy method name would be testing a method nothing calls.

```php
use App\Panels\Admin\Resources\Users\UserResource;

it('delegates every resource ability to the policy', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $member = User::factory()->create();

    $this->actingAs($admin);

    expect(UserResource::canViewAny())->toBeTrue()
        ->and(UserResource::canCreate())->toBeTrue()
        ->and(UserResource::canView($member))->toBeTrue()
        ->and(UserResource::canEdit($member))->toBeTrue()
        ->and(UserResource::canDelete($member))->toBeTrue();

    $this->actingAs($member);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canDelete($admin))->toBeFalse();
});
```

`viewAny` and `view` are different questions, and answering the second with the first would list every account to everybody:

```php
it('refuses a member the index even though they may read themselves', function (): void {
    $member = User::factory()->create();

    $this->actingAs($member);

    expect(UserResource::canView($member))->toBeTrue();

    $this->get('/admin/users')->assertForbidden();
});
```

### Every route, including the write verbs

The static methods prove the policy is wired. The routes prove nothing bypasses it. A new resource is worth this whole set:

```php
$this->actingAs($member);

$this->get("/admin/users/{$other->id}")->assertForbidden();
$this->get("/admin/users/{$other->id}/edit")->assertForbidden();

$this->put("/admin/users/{$other->id}/edit", [
    'name' => 'Renamed by a stranger',
    'email' => $other->email,
])->assertForbidden();

$this->post('/admin/actions/record', [
    'resource' => 'users', 'action' => 'delete', 'record' => $other->id,
])->assertForbidden();

$this->post('/admin/actions/cell', [
    'resource' => 'users', 'column' => 'name', 'record' => $other->id, 'value' => 'x',
])->assertForbidden();

expect($other->fresh()->name)->not->toBe('Renamed by a stranger');
```

The final `expect()` is not decoration. A status code says the request was refused; the database says nothing was written.

## Actions

Two closures, asked at different times:

| Method | Signature | Asked |
| --- | --- | --- |
| `authorize` | `authorize(Closure $callback): static` — `Closure(?Model): bool` | before running, and when serializing a row |
| `authorizeEachUsing` | `authorizeEachUsing(Closure $callback): static` — `Closure(Model): bool` | for every record of a bulk run, before any is written |
| `visible` | `visible(Closure $callback): static` — `Closure(?Model): bool` | when serializing only; it hides without forbidding |

`Action::toArray($record)` returns `null` when either the visibility or the authorization closure says no, so a refused action is absent from the row rather than a button that answers 403. The helpers ask exactly these:

```php
panelRecordActions(UserResource::class)
    ->assertExists('delete')            // declared
    ->assertHidden('delete', $admin)    // not offered on the admin's own row
    ->assertCanNotRun('delete', $admin);

panelTableActions(UserResource::class)->assertCanRun('purgeUnverified');
```

`assertCanNotRun()` and `assertHidden()` are different assertions. `visible()` hides an action without implying it is forbidden, and authorization is asked again on execution regardless, so a test that only asserts hidden has not asserted it cannot be run.

For a bulk action, the property is all-or-nothing:

```php
it('writes nothing when a selection contains one record the user may not touch', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [$mine->id, $theirs->id],
    ]);

    expect(User::find($mine->id))->not->toBeNull()
        ->and(User::find($theirs->id))->not->toBeNull();
});
```

See [Testing actions](actions.md) for the full helper surface.

## Pages

```php
use Symfony\Component\HttpKernel\Exception\HttpException;

it('refuses a page the user cannot access', function (): void {
    // The route, which is what a user actually hits.
    $this->actingAs(User::factory()->create())
        ->get('/admin/settings')
        ->assertForbidden();

    // And the page itself, independently of the panel around it.
    expect(fn () => (new ForbiddenPage)->render())->toThrow(HttpException::class);
});
```

`Page::render()` calls `abort_unless(static::canAccess(), 403)` before it builds anything. Hiding a page from navigation is a separate, weaker thing — the route still exists and still authorizes:

```php
expect(ForbiddenPage::canAccess())->toBeFalse()
    ->and(ForbiddenPage::slug())->toBe('restricted');
```

Assert the navigation too, because a visible link to a 403 is a bug of its own:

```php
use PandaPanel\Support\NavigationBuilder;

$labels = collect(app(NavigationBuilder::class)->for($panel, '/nav-host'))
    ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
    ->all();

expect($labels)->toContain('Settings')
    ->and($labels)->not->toContain('Restricted');
```

## Widgets

`Widget::canView(): bool` defaults to true; a widget restricts itself by overriding it. `WidgetCollection` filters before resolving data, which is the ordering to prove rather than assume:

```php
use PandaPanel\Pages\WidgetCollection;

it('never resolves data for an unauthorized widget', function (): void {
    // ForbiddenStatsWidget::stats() throws. Reaching it at all fails this test.
    $collection = WidgetCollection::for([ForbiddenStatsWidget::class]);

    expect($collection->definitions())->toBe([])
        ->and($collection->deferred())->toBeNull();
});

it('omits a widget the user may not view', function (): void {
    $ids = array_column(
        WidgetCollection::for([CountingStatsWidget::class, ForbiddenStatsWidget::class])->definitions(),
        'id',
    );

    expect($ids)->toBe([CountingStatsWidget::id()]);
});
```

## Relation managers

A relation manager asks abilities a resource has no method for, and they go through the same `PolicyGate`:

| Method | Ability | Resolved from |
| --- | --- | --- |
| `canAttach(Model $owner)` | `attachAny` | the owner |
| `canDetach(Model $owner, Model $record)` | `detach` | the owner, with the record as a second argument |
| `canAssociate(Model $owner)` | `associateAny` | the owner |
| `canDissociate(Model $owner, Model $record)` | `dissociate` | the owner, with the record |
| `canRestore(Model $owner, Model $record)` | `restore` | the record |
| `canForceDelete(Model $owner, Model $record)` | `forceDelete` | the record |

The endpoint also refuses an operation the relation shape cannot perform — `attach` on a one-to-many, `associate` on a many-to-many — which is a 403 rather than a schema error.

## Strict authorization

A model with no policy answers "no" to every ability, which is safe and silent — and silence is the problem: it looks exactly like a permission that was deliberately withheld. `Panel::strictAuthorization()` turns that into an exception.

```php
use PandaPanel\Core\Panel;
use PandaPanel\Exceptions\PanelAuthorizationException;

$panel = app(PanelManager::class)->register(
    Panel::make('strict-host')
        ->path('strict-host')
        ->settings(false)
        ->strictAuthorization()
        ->resources([UnpolicedFixtureResource::class]),
);

app(PanelManager::class)->setCurrentPanel($panel);

expect(fn (): bool => UnpolicedFixtureResource::canViewAny())
    ->toThrow(PanelAuthorizationException::class);
```

The check lives in `PolicyGate` rather than in `Resource`, so it covers every ability the panel asks — including the relation abilities that have no `can*` method. Two failures, two messages:

- `PanelAuthorizationException::missingPolicy($model, $ability)` — no policy is registered for the model.
- `PanelAuthorizationException::missingPolicyMethod($policy, $model, $ability)` — a policy exists but has neither that method nor a `before()`.

A policy with a `before()` is accepted for every ability, because `before` may answer for all of them.

## The missing-policy notice

Outside strict mode, a resource with no policy disappears from navigation with nothing to say why. `MissingPolicyNotice` logs it once:

| Method | Signature |
| --- | --- |
| `reportIfMissing` | `static reportIfMissing(string $resource, string $model): void` |
| `forget` | `static forget(): void` |
| `expectedPolicy` | `static expectedPolicy(string $model): string` |

```php
use Illuminate\Support\Facades\Log;
use PandaPanel\Support\MissingPolicyNotice;

beforeEach(fn () => MissingPolicyNotice::forget());

it('says why a resource left the navigation when it has no policy', function (): void {
    Log::shouldReceive('debug')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'has no policy')
            && str_contains($message, 'make:policy')
            && str_contains($message, 'strictAuthorization'));

    MissingPolicyNotice::reportIfMissing('App\\Resources\\OrderResource', UnpolicedModel::class);
});

it('suggests where the policy would live', function (): void {
    expect(MissingPolicyNotice::expectedPolicy('App\\Models\\Order'))
        ->toBe('App\\Policies\\OrderPolicy');
});
```

It says it once however many times the navigation is built — hence `forget()` in `beforeEach` — and stays quiet when a policy exists and answered no, because a policy that considered the question is a decision rather than a mistake.

## Gotchas

- **403, redirect, 404 mean different things.** Signed in and refused is 403. Not signed in is a redirect. Out of the resource's scope is 404, and asserting 403 there would be asserting a leak: the record's existence.
- **A status code is half a test.** Follow every refusal with an assertion that nothing changed.
- **The panel door short-circuits.** A member refused at `/admin` never reaches the users policy, so a policy test that signs in as a member is testing the door. Use a user the panel admits when the policy is the subject.
- **`canEdit()` asks `update`.** So do the routes. A policy method named `edit` is never called.
- **`canViewAny()` hides the navigation entry; it does not register or unregister the route.** The route exists and authorizes independently, which is exactly why a direct-URL test is worth writing.
- **Strict mode is per panel.** `PolicyGate` reads `panel()?->hasStrictAuthorization()`, so a test asserting the throw must have that panel current.

## See also

- [Testing helpers](helpers.md) and [test setup](setup.md)
- [Authorization concepts](../concepts/authorization.md)
- [Resource authorization](../resources/authorization.md), [action authorization](../actions/authorization.md)
- [Widget authorization](../widgets/authorization.md), [page authorization](../pages-navigation/authorization.md)
- [Relation policies](../relations/policies.md)
- [Negative security tests](negative-security-tests.md)
