# Action Authorization

Every action answers two questions: whether to draw it, and whether to run it. They are separate, they are asked at different times, and only the second one protects anything. You reach for this page when deciding where a permission check belongs and what the endpoint will do with it.

The rule underneath everything here: hiding a button is presentation. The endpoint asks again before running, and that second question is the security boundary.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('reference')->searchable()])
            ->recordActions([
                Action::make('approve')
                    // Presentation: an approved order has nothing to approve.
                    ->visible(static fn (?Model $record): bool => $record?->getAttribute('status') === 'pending')
                    // Permission: asked again by the endpoint before running.
                    ->authorize(static fn (?Model $record): bool => $record !== null
                        && OrderResource::canEdit($record))
                    ->action(static fn (Model $record) => $record->approve()),
            ]);
    }
}
```

A user without the ability never sees the button, and a crafted POST naming `approve` answers 403.

## The three closures

| Method | Signature | Called with | Effect when false |
| --- | --- | --- | --- |
| `visible()` | `visible(Closure $callback): static` | `?Model` | the action is absent from the payload |
| `authorize()` | `authorize(Closure $callback): static` | `?Model` | absent from the payload **and** 403 on execution |
| `authorizeEachUsing()` | `authorizeEachUsing(Closure $callback): static` | `Model` | 403 for the whole bulk run, before any write |

Their readers are `isVisibleFor(?Model $record): bool`, `isAuthorizedFor(?Model $record): bool`, and `isAuthorizedForEach(Model $record): bool`. All three default to `true` when no closure was given.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use PandaPanel\Actions\Action;

$action = Action::make('approve')
    ->visible(static fn (?Model $record): bool => $record?->getAttribute('status') === 'pending')
    ->authorize(static fn (?Model $record): bool => $record !== null && Gate::allows('approve', $record))
    ->authorizeEachUsing(static fn (Model $record): bool => Gate::allows('approve', $record));

$action->isVisibleFor($order);          // bool
$action->isAuthorizedFor($order);       // bool
$action->isAuthorizedForEach($order);   // bool
```

`visible()` hides without implying refusal — a restore button on a live record is not forbidden, it is meaningless. `authorize()` is the permission. Keeping them apart is what lets a table say "this row has nothing to approve" without claiming the user may not approve anything.

## `?Model` means null happens

Both `visible()` and `authorize()` are called with `null` whenever the action is serialized without a record:

- a header, toolbar, or empty-state action, which has no record by definition;
- a bulk action, whose definition is sent before anything is selected;
- `Action::toArray()` called with no argument, which is how `TableSchema` serializes those sets.

```php
// Every built-in starts this way for a reason.
->authorize(static fn (?Model $record): bool => $record !== null && $resource::canDelete($record))
```

A closure that dereferences `$record` unguarded throws while rendering the table, which takes the whole page down rather than one button. For a bulk action the null call is not an accident — it is the collective question:

```php
use App\Panels\Admin\Resources\Orders\OrderResource;

->authorize(static fn (?Model $record): bool => $record === null
    ? OrderResource::canDeleteAny()
    : OrderResource::canDelete($record))
```

That is exactly how `DeleteBulkAction` is written.

## What the serializer does

```php
Action::toArray(?Model $record = null): ?array
```

Returns `null` when the action is not visible or not authorized for that record, and every caller drops it:

- `TableSchema::toRow()` builds each row's `actions` list and omits the refused ones;
- `TableSchema::toArray()` serializes header, toolbar, empty-state, and bulk actions with no record;
- `InfolistSchema::toArray()` and `Entry::toArray()` do the same for a view page;
- `Action::toArray()` recurses into `modalActions`, so a registered dialog action the user may not run is absent from the dialog.

An action the user may not run is never rendered in the first place, which is a better answer than a button that responds to being pressed with a 403.

## Where the endpoints ask

Every action endpoint authorizes before it runs anything, whatever the client sent.

| Endpoint | Resolves the action in | Authorization asked |
| --- | --- | --- |
| `POST actions/record` | `TableSchema::getRecordAction()` | `isAuthorizedFor($record)` |
| `POST actions/table` | `TableSchema::getTableAction()` | `isAuthorizedFor(null)` |
| `POST actions/bulk` | `TableSchema::getBulkAction()` | `isAuthorizedFor(null)`, then `isAuthorizedForEach()` per record |
| `POST actions/infolist` | `InfolistSchema::getAction()` | `isAuthorizedFor($record)` |
| `GET actions/form` | the scope's schema | `isAuthorizedFor($record)` |
| `POST actions/form` | the scope's schema | `isAuthorizedFor($record)`, then the bulk per-record check |
| `POST actions/cell` | `TableSchema::getColumn()` | `Resource::canEdit($record)` and the column's own disabled check |
| `POST actions/reorder` | `TableSchema::getReorderColumn()` | `Resource::canEdit($record)` for every record |

A form action is authorized **twice**: once to describe the dialog and once to submit it. Opening a dialog and performing an operation are two separate permissions in time, and a role revoked while a dialog was open must not still be able to submit it.

## The lookups are the other half

Authorization is asked after the request has already been narrowed by things that are not permissions but behave like them:

1. The panel is resolved for this request. A resource registered in another panel is not in this registry.
2. The resource slug is looked up in that registry, or 404.
3. The action is looked up in the schema that declared it, or 404 — and each scope is a separate whitelist, so a view page's action cannot be run through the table endpoint.
4. Records are loaded through `Resource::findRecord()` / `findRecords()`, which apply the resource's own scope. A key outside it resolves to nothing.
5. For a bulk run, the number of records found is compared against the number of keys asked for. A key that disappeared in the lookup is a 404, not a partial run.
6. Only then is the policy asked.

A nested resource additionally binds its parent from the `parent` key in the payload, resolved and authorized through the parent resource exactly as route middleware does it. Without that step an action on a nested resource would run against every parent's children at once.

## Resource abilities

The built-in actions authorize through the resource, and every `can*` goes through `PandaPanel\Support\PolicyGate`.

| Resource method | Ability | Argument | Used by |
| --- | --- | --- | --- |
| `canViewAny()` | `viewAny` | model class | `ExportAction` |
| `canView($record)` | `view` | record | `ViewAction`, `ReplicateAction` |
| `canCreate()` | `create` | model class | `CreateAction`, `ImportAction`, `ReplicateAction` |
| `canEdit($record)` | `update` | record | `EditAction`, editable cells, reordering |
| `canDelete($record)` | `delete` | record | `DeleteAction`, `DeleteBulkAction` |
| `canDeleteAny()` | `deleteAny` | model class | `DeleteBulkAction` |
| `canRestore($record)` | `restore` | record | `RestoreAction`, `RestoreBulkAction` |
| `canRestoreAny()` | `restoreAny` | model class | `RestoreBulkAction` |
| `canForceDelete($record)` | `forceDelete` | record | `ForceDeleteAction`, `ForceDeleteBulkAction` |
| `canForceDeleteAny()` | `forceDeleteAny` | model class | `ForceDeleteBulkAction` |

The `*Any` abilities exist because a bulk action has to answer before there is a record to ask about. Add them to the policy alongside their per-record counterparts.

Relation actions ask a manager's abilities instead, some of which resolve against the *owner's* policy:

| Relation manager method | Ability | Policy it lands on |
| --- | --- | --- |
| `canViewAny($owner)` | `viewAny` | the related model |
| `canCreate($owner)` | `create` | the related model |
| `canEdit($owner, $record)` | `update` | the related record |
| `canDelete($owner, $record)` | `delete` | the related record |
| `canRestore($owner, $record)` | `restore` | the related record |
| `canForceDelete($owner, $record)` | `forceDelete` | the related record |
| `canAttach($owner)` | `attachAny` | the owner |
| `canDetach($owner, $record)` | `detach` | the owner, with the related record as a second argument |
| `canAssociate($owner)` | `associateAny` | the owner |
| `canDissociate($owner, $record)` | `dissociate` | the owner, with the related record as a second argument |

Membership is the owner's business — whether this order may have that line item added to it is a question about the order.

## Strict authorization

```php
use PandaPanel\Core\Panel;

Panel::make('admin')->strictAuthorization();
```

With strict authorization on, `PolicyGate` asserts that a policy exists for the model and that it answers the ability being asked, throwing `PandaPanel\Exceptions\PanelAuthorizationException` otherwise. Without it, a missing policy method is an ordinary `Gate` deny — which looks exactly like a working permission check that always says no.

A policy with a `before()` method satisfies the check for every ability, since `before` may answer for all of them.

## Panel-wide rules

`configureActions()` sees each action as it is built, which is enough to enforce a house rule about how destructive work behaves:

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Core\Panel;

Panel::make('admin')
    ->configureActions(static function (Action $action): void {
        if ($action->getVariant() === ActionVariant::Destructive) {
            $action->requiresConfirmation();
        }
    });
```

It cannot be used to *add* a permission that overrides the schema's: the configurator runs first, so a later `authorize()` replaces whatever it set. Panel-wide access control belongs on `Panel::canAccess()`, which runs before any panel route.

## Testing

```php
it('enforces the policy on execution, not on the button being rendered', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->target->id,
    ])->assertForbidden();

    expect(User::find($this->target->id))->not->toBeNull();
});

it('hides an action the policy would refuse for that row', function (): void {
    $rows = $this->get('/admin/users')->viewData('page')['props']['rows'];

    $ownRow = collect($rows)->firstWhere('key', $this->admin->id);

    expect(array_column($ownRow['actions'], 'name'))->not->toContain('delete');
});
```

Both tests matter, and the first one is the one that must never be deleted: it is the assertion that the button was not the protection.

## Gotchas

- **Overriding a built-in's handler keeps its authorization.** `DeleteAction::make(...)->action(...)` still asks `canDelete()`. Say what the new operation needs.
- **`visible()` is not a permission.** It is not re-checked by the record endpoint before running — only `authorize()` is. Put anything that matters in `authorize()`.
- **A bulk action's `authorize()` answers with `null` on the endpoint too**, before the selection is loaded. It cannot see the records.
- **`authorizeEachUsing()` aborts the whole batch.** It is a permission, not a filter. To skip inapplicable rows, filter inside the handler.
- **A link action is authorized twice by two different owners.** The action hides the button; the page it links to authorizes on arrival. Removing the second is a hole the first cannot cover.
- **Registered modal actions are authorized against the same record as their parent** when the parent is serialized, and again by the endpoint when they run.
- **The upload endpoint for an action's form authorizes as the action**, not as the resource — an action a user may not run must not be a way to put a file on a disk.

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Bulk actions](bulk-actions.md)
- [Action forms](forms.md)
- [Relation actions](relation-actions.md)
- [Infolist actions](infolist-actions.md)
- [Authorization](../concepts/authorization.md)
- [Resource authorization](../resources/authorization.md)
- [Panel access](../panels/access.md)
- [Testing authorization](../testing/authorization.md)
