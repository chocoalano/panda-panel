# Testing Actions

Four helpers, matching the four places a schema declares actions. Each one looks the action up through the same schema the controller resolves against, so an action the helper can find is an action the endpoint can find — and one it cannot find is one a request could not run either. Reach for them when the question is whether an action exists, whether it is offered for a record, whether this user may run it, and what it does when it runs.

## A minimal working example

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($this->admin);
});

it('purges unverified accounts, and only for an administrator', function (): void {
    User::factory()->create(['email_verified_at' => null]);

    panelTableActions(UserResource::class)->call('purgeUnverified');

    expect(User::query()->whereNull('email_verified_at')->count())->toBe(0);

    $this->actingAs(User::factory()->create());

    panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
});
```

## The four scopes

```php
panelRecordActions(UserResource::class)     // one row
panelTableActions(UserResource::class)      // header, toolbar, empty state
panelBulkActions(UserResource::class)       // a selection
panelInfolistActions(UserResource::class)   // a record page
```

They are not interchangeable, and that is the point: a row action called `delete` and a bulk action called `delete` are two different objects behind two different endpoints, and naming them the same is the natural thing to do. What each scope searches:

| Helper | Class method | Looks in |
| --- | --- | --- |
| `panelRecordActions()` | `TestsActions::record()` | `TableSchema::getRecordAction()` — `recordActions()`, then every column's own `action()` |
| `panelTableActions()` | `TestsActions::table()` | `TableSchema::getTableAction()` — `headerActions()`, `toolbarActions()` and `emptyStateActions()`, in that order |
| `panelBulkActions()` | `TestsActions::bulk()` | `TableSchema::getBulkAction()` — `bulkActions()` |
| `panelInfolistActions()` | `TestsActions::infolist()` | `InfolistSchema::getAction()` |

A column action is a record action in every sense that matters — it names a row, authorizes it, and changes it — so `panelRecordActions()` finds it:

```php
// `verifyOne` is declared on the BadgeColumn, not in recordActions().
panelRecordActions(UserResource::class)->assertExists('verifyOne');
```

And the three table bars are one lookup, because the endpoint that runs them does not care which bar an action was rendered in:

```php
panelTableActions(UserResource::class)
    ->assertExists('purgeUnverified')   // toolbar
    ->assertExists('export')            // header
    ->assertExists('import');           // header
```

## Every method

| Method | Signature | Behaviour |
| --- | --- | --- |
| `find` | `find(string $name): ?Action` | the action, or `null` when the schema never declared it |
| `call` | `call(string $name, ?Model $record = null, array $data = []): self` | authorizes, then runs |
| `assertExists` | `assertExists(string $name): self` | fails when the scope has no such action |
| `assertDoesNotExist` | `assertDoesNotExist(string $name): self` | fails when it does |
| `assertVisible` | `assertVisible(string $name, ?Model $record = null): self` | fails when the action is not offered for that record |
| `assertHidden` | `assertHidden(string $name, ?Model $record = null): self` | passes when the action is absent **or** not offered |
| `assertCanRun` | `assertCanRun(string $name, ?Model $record = null): self` | fails when `isAuthorizedFor()` is false |
| `assertCanNotRun` | `assertCanNotRun(string $name, ?Model $record = null): self` | fails when it is true |

Every assertion returns `$this`. Every one except `assertDoesNotExist()` and `assertHidden()` fails with "the record/table/bulk/infolist actions of `[Resource]` do not include `[name]`" when the action is missing, rather than dereferencing null three lines later.

### `find()`

The escape hatch when an assertion does not fit:

```php
use PandaPanel\Actions\Action;

$action = panelRecordActions(UserResource::class)->find('edit');

expect($action)->toBeInstanceOf(Action::class)
    ->and($action->getLabel())->toBe('Edit')
    ->and($action->isExecutable())->toBeFalse();   // a link action has no handler
```

### `assertExists()` / `assertDoesNotExist()`

Existence in that scope, before any record is involved:

```php
panelRecordActions(UserResource::class)
    ->assertExists('edit')
    ->assertExists('delete')
    ->assertDoesNotExist('invented');
```

`assertDoesNotExist()` is the assertion that a name is not addressable. It is worth writing for an action you deliberately removed: the endpoint resolves by name against this same schema, so an action absent here cannot be run by a hand-written POST either.

### `assertVisible()` / `assertHidden()`

These ask the question the row asks: `Action::toArray($record)` returns `null` when the action is hidden **or** unauthorized for that record, so an action refused for a record is absent from the row rather than a button that answers 403.

```php
// The example policy refuses self-deletion, so the button is not on the
// administrator's own row.
panelRecordActions(UserResource::class)
    ->assertHidden('delete', $this->admin)
    ->assertVisible('edit', $this->admin);
```

`assertHidden()` also passes when the action does not exist at all, which makes it the weaker of the pair. Pair it with `assertExists()` when you mean "declared, but not offered here":

```php
panelRecordActions(UserResource::class)
    ->assertExists('delete')
    ->assertHidden('delete', $this->admin);
```

### `assertCanRun()` / `assertCanNotRun()`

Authorization alone — `Action::isAuthorizedFor($record)`, which is the `authorize()` closure and nothing else:

```php
panelTableActions(UserResource::class)->assertCanRun('purgeUnverified');

$this->actingAs(User::factory()->create());

panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
```

The distinction from `assertHidden()` matters: `visible()` hides an action without implying it is forbidden, and authorization is asked again on execution regardless. A test that only asserts an action is hidden has not asserted it cannot be run.

### `call()`

Runs the action exactly as the endpoint would, after asserting `isAuthorizedFor($record)`:

```php
// A table action: no record, so `Action::executeWithoutRecord($data)`.
panelTableActions(UserResource::class)->call('purgeUnverified');

// A record action: `Action::execute($record, $data)`, which runs the
// before hook, the handler and the after hook in one transaction.
panelRecordActions(OrderResource::class)->call('approve', $order);

// With the data the action's own form submitted.
panelRecordActions(OrderResource::class)->call('reject', $order, [
    'reason' => 'Out of stock',
]);
```

Authorization is checked first and **fails the test rather than skipping**. That is deliberate: a helper that ran an action the user may not run would prove the handler works and nothing about whether it is reachable.

`call()` is a no-op for an action with no matching handler — a link action, or a record action called without a record — rather than an error. Assert the effect, not the call:

```php
panelRecordActions(UserResource::class)->call('delete', $target);

expect(User::find($target->id))->toBeNull();
```

There is no `callBulk()`. A bulk run takes a collection and authorizes every record before writing any, which is a property worth testing through the endpoint — see below.

## Testing through the endpoints

One action endpoint per panel, resolving the resource against that panel's registry. The complement to the helper: the helper proves the schema declares it, the endpoint proves a request can reach it and that a request that should not, cannot.

| Route | Payload |
| --- | --- |
| `POST /{panel}/actions/record` | `resource`, `action`, `record` |
| `POST /{panel}/actions/bulk` | `resource`, `action`, `records[]` |
| `POST /{panel}/actions/table` | `resource`, `action` |
| `POST /{panel}/actions/infolist` | `resource`, `action`, `record` |
| `POST /{panel}/actions/cell` | `resource`, `column`, `record`, `value` |
| `POST /{panel}/actions/reorder` | `resource`, the new order |
| `GET`/`POST /{panel}/actions/form` | an action's own form: fetch, then submit |

Route names follow `panel.{id}.actions.{name}` — `panel.admin.actions.record`.

```php
it('deletes a record through the action endpoint', function (): void {
    $this->from('/admin/users')
        ->post('/admin/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $this->target->id,
        ])
        ->assertRedirect('/admin/users');

    expect(User::find($this->target->id))->toBeNull();
});
```

The refusals are the tests worth having, and each has its own status:

```php
// An action the resource never declared.
$this->post('/admin/actions/record', [
    'resource' => 'users', 'action' => 'nuke', 'record' => $this->target->id,
])->assertNotFound();

// A resource that is not registered in this panel.
$this->post('/admin/actions/record', [
    'resource' => 'invoices', 'action' => 'delete', 'record' => $this->target->id,
])->assertNotFound();

// A key `Resource::query()` cannot reach.
$this->post('/admin/actions/record', [
    'resource' => 'users', 'action' => 'delete', 'record' => 999_999,
])->assertNotFound();

// A non-scalar key.
$this->post('/admin/actions/record', [
    'resource' => 'users', 'action' => 'delete', 'record' => ['a', 'b'],
])->assertStatus(422);

// A link action, which has no handler.
$this->post('/admin/actions/record', [
    'resource' => 'users', 'action' => 'edit', 'record' => $this->target->id,
])->assertStatus(400);

// The policy, enforced on execution rather than on the button being drawn.
$this->actingAs(User::factory()->create())
    ->post('/admin/actions/record', [
        'resource' => 'users', 'action' => 'delete', 'record' => $this->target->id,
    ])->assertForbidden();
```

### Bulk

The property to assert is all-or-nothing. Every record is authorized before any is written, so one refused row is not "most of it went through":

```php
it('deletes nothing when one selected record is forbidden', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        // The administrator's own record cannot be deleted.
        'records' => [$this->target->id, $this->admin->id],
    ])->assertForbidden();

    expect(User::count())->toBe(2);
});
```

An empty selection is a validation error rather than a silent success:

```php
$this->post('/admin/actions/bulk', [
    'resource' => 'users', 'action' => 'delete', 'records' => [],
])->assertStatus(302)->assertSessionHasErrors('records');
```

`authorizeEachUsing()` is the custom per-record half. Without it, a bulk action falls back to
`authorize($record)` for every selected record, so a row-level authorization closure still applies.
Use `authorizeEachUsing()` when "may run this" and "may run this on these" are intentionally
different questions.

### The serialized row

An action crosses to the browser as data. Assert that it carries nothing it should not:

```php
$rows = $this->get('/admin/users')->viewData('page')['props']['rows'];
$action = collect($rows)->firstWhere('key', $this->target->id)['actions'][0];

expect($action)->toHaveKeys(['name', 'label', 'icon', 'variant', 'type', 'url', 'confirmation'])
    ->and(json_encode($action))->not->toContain('Closure');
```

And that a refused action is absent rather than present-and-disabled:

```php
$ownRow = collect($rows)->firstWhere('key', $this->admin->id);

expect(array_column($ownRow['actions'], 'name'))->not->toContain('delete');
```

## Gotchas

- **Scope is part of the lookup.** `panelBulkActions(...)->assertExists('edit')` fails for a resource whose `edit` is a row action. That is the helper telling the truth about the endpoint.
- **`assertHidden()` passes for a missing action.** Pair it with `assertExists()` when the distinction matters.
- **`call()` runs the handler, not the endpoint.** No route, no middleware, no flash message, no redirect. It does run the before/after hooks and the transaction, because those live in `Action::execute()`.
- **A table action with no `tableAction()` handler does nothing.** `call()` returns silently. A schema that declares an action with no handler at all is refused at build time — `PanelSchemaException: The action [approve] does nothing` — so the silent case is only the mismatched-scope one.
- **Action names travel as identifiers.** `Action::make('send invoice')` throws at declaration with a suggestion (`try [send-invoice]`), so a name that could never be matched is not something a test has to catch.
- **Two actions with one name in one set throw.** `PanelSchemaException`, naming the set: "The bulk actions declare more than one action named [delete]".

## See also

- [Testing helpers](helpers.md) and [test setup](setup.md)
- [Actions overview](../actions/overview.md), [scopes](../actions/scopes.md), [authorization](../actions/authorization.md)
- [Bulk actions](../actions/bulk-actions.md), [row actions](../tables/record-actions.md), [toolbar actions](../tables/toolbar-actions.md)
- [Action forms](../actions/forms.md) and [modals](../actions/modals.md)
- [Testing authorization](authorization.md)
