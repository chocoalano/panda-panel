# Action Scopes

A scope is the whitelist an action is resolved against. Every action request names one — `record`, `table`, `bulk`, or `infolist` — and the server looks the action up only in the schema that scope belongs to. Reach for this page when you need to know why an action declared in one place cannot be run from another, which endpoint a button posts to, or what the handler will be handed when it runs.

## A minimal example

Three actions, three scopes, on one table:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
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

            // scope: record — one row at a time
            ->recordActions([
                Action::make('approve')
                    ->action(static fn (Model $record) => $record->approve()),
            ])

            // scope: bulk — the selection
            ->bulkActions([
                Action::make('approve')
                    ->label('Approve selected')
                    ->bulkAction(static fn (Collection $records) => $records->each->approve()),
            ])

            // scope: table — no record at all
            ->headerActions([
                Action::make('approveBacklog')
                    ->label('Approve the backlog')
                    ->tableAction(static fn () => Order::approveBacklog()),
            ]);
    }
}
```

Two of them are called `approve`. That is not a collision: they live in different scopes, so `POST actions/record` finds the first and `POST actions/bulk` finds the second, and neither can reach the other's handler.

## The four scopes

| Scope | Declared by | Resolved with | Handler it needs | Authorized with |
| --- | --- | --- | --- | --- |
| `record` | `TableSchema::recordActions()`, and any `Column::action()` | `TableSchema::getRecordAction()` | `action()` | the record |
| `table` | `TableSchema::headerActions()`, `toolbarActions()`, `emptyStateActions()` | `TableSchema::getTableAction()` | `tableAction()` | `null` |
| `bulk` | `TableSchema::bulkActions()` | `TableSchema::getBulkAction()` | `bulkAction()`, or `action()` as a fallback | `null`, then each record |
| `infolist` | `InfolistSchema::actions()`, section header actions, entry actions | `InfolistSchema::getAction()` | `action()` | the record |

The scope is not a property of the `Action` object. The same object put into `recordActions()` and into `bulkActions()` is two entries in two whitelists; what makes it a bulk action is which array it was declared in and which handler it carries.

## Where each scope's actions are declared

```php
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Tables\TableSchema;

TableSchema::recordActions(array $actions): self
TableSchema::bulkActions(array $actions): self
TableSchema::headerActions(array $actions): self
TableSchema::toolbarActions(array $actions): self
TableSchema::emptyStateActions(array $actions): self

InfolistSchema::actions(array $actions): self
```

The lookups that go with them, all returning `?PandaPanel\Actions\Action`:

```php
TableSchema::getRecordAction(string $name): ?Action
TableSchema::getTableAction(string $name): ?Action
TableSchema::getBulkAction(string $name): ?Action
InfolistSchema::getAction(string $name): ?Action

TableSchema::getRecordActions(): array        // list<Action>
TableSchema::getBulkActions(): array          // list<Action>
TableSchema::getHeaderActions(): array        // list<Action>
TableSchema::getToolbarActions(): array       // list<Action>
TableSchema::getEmptyStateActions(): array    // list<Action>
InfolistSchema::allActions(): array           // array<string, Action>
```

Two of these are wider than their declaring method suggests:

- `getRecordAction()` searches the row actions **and** every column's own `Column::action()`. A column action is a record action in every sense that matters, so the endpoint finds it without a second lookup.
- `getTableAction()` searches the header, toolbar and empty-state sets in that order and returns the first match. Three places to put a button, one namespace when it is looked up.

## The endpoints

One set per panel, under the panel's path. `{panel path}` is what `Panel::path()` declared, and `{panelId}` is the panel's id.

| Method and path | Route name | Scope | Controller |
| --- | --- | --- | --- |
| `POST {panel path}/actions/record` | `panel.{panelId}.actions.record` | `record` | `PanelActionController::record()` |
| `POST {panel path}/actions/bulk` | `panel.{panelId}.actions.bulk` | `bulk` | `PanelActionController::bulk()` |
| `POST {panel path}/actions/table` | `panel.{panelId}.actions.table` | `table` | `PanelActionController::table()` |
| `POST {panel path}/actions/infolist` | `panel.{panelId}.actions.infolist` | `infolist` | `PanelActionController::infolist()` |
| `GET {panel path}/actions/form` | `panel.{panelId}.actions.form` | any, named in the query | `PanelActionFormController::show()` |
| `POST {panel path}/actions/form` | `panel.{panelId}.actions.submit` | any, named in the body | `PanelActionFormController::submit()` |

The four execution endpoints each resolve against exactly one whitelist, which is why they are four routes rather than one route with a scope parameter. The two form endpoints take the scope as an explicit value, and validate it against an allowlist before anything else happens.

### What each request carries

```json
POST /admin/actions/record
{ "resource": "orders", "action": "approve", "record": 42 }

POST /admin/actions/bulk
{ "resource": "orders", "action": "approve", "records": [42, 43], "tableState": { } }

POST /admin/actions/table
{ "resource": "orders", "action": "approveBacklog", "tableState": { } }

POST /admin/actions/infolist
{ "resource": "orders", "action": "approve", "record": 42 }
```

Validation, per endpoint:

| Field | `record` | `bulk` | `table` | `infolist` |
| --- | --- | --- | --- | --- |
| `resource` | required string | required string | required string | required string |
| `action` | required string | required string | required string | required string |
| `record` | required, scalar or 422 | — | — | required, scalar or 422 |
| `records` | — | required array, 1–500 | — | — |

`tableState` is the query string the list was showing, sent with the table and bulk scopes only. It is read back through `PandaPanel\Support\TableState::fromRequest()` and put through the table's own schema, which is the whitelist — a filter the table never declared is ignored there exactly as it is when it arrives in a URL. A record action never sends it, because what the list was filtered to has no bearing on one record.

## What the handler receives

Each scope calls a different method on the action, and each of those has its own signature:

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

Action::execute(Model $record, array $data = []): void              // record, infolist
Action::executeBulk(Collection $records, array $data = []): void    // bulk
Action::executeWithoutRecord(array $data = []): void                // table
```

Which in turn calls the closure the action declared:

```php
Action::action(Closure(Model, array<string, mixed>): void $callback): static
Action::bulkAction(Closure(Collection<int, Model>, array<string, mixed>): void $callback): static
Action::tableAction(Closure(array<string, mixed>): void $callback): static
```

`$data` is what the action's own form submitted, and is `[]` for an action without one. A handler that declares one argument never sees the second, so adding a form to an existing action does not change how it is called.

Before running anything, each endpoint asks whether the action can be run in that scope at all:

```php
Action::isExecutable(): bool          // has action()
Action::isBulkExecutable(): bool      // has bulkAction()
Action::isTableExecutable(): bool     // has tableAction()
```

A mismatch is a 400 with a message, not a silent no-op: posting a link action to `actions/record` answers `400 This action cannot be executed.`

The bulk endpoint accepts `isBulkExecutable() || isExecutable()`. An action with only `action()` declared in `bulkActions()` runs that handler once per selected record — see [Bulk actions](bulk-actions.md).

## Authorization per scope

```php
Action::isAuthorizedFor(?Model $record): bool
Action::isAuthorizedForEach(Model $record): bool
```

| Scope | `isAuthorizedFor()` is asked with | Also asked |
| --- | --- | --- |
| `record` | the resolved record | — |
| `infolist` | the resolved record | — |
| `table` | `null` | — |
| `bulk` | `null`, before the selection is loaded | `isAuthorizedForEach()` for every record, before any is written |

This is why the closure passed to `authorize()` takes `?Model` rather than `Model`: the same action object is authorized with a record on a row and with `null` in a header or a bulk bar. Every built-in action begins its closure with `$record !== null &&` or with an explicit `$record === null ? ... : ...` for that reason.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->authorize(static fn (?Model $record): bool => $record === null
        ? auth()->user()?->can('approveAny', Order::class) === true
        : auth()->user()?->can('approve', $record) === true)
    ->authorizeEachUsing(static fn (Model $record): bool => $record->getAttribute('status') === 'pending')
    ->bulkAction(static fn (Collection $records) => $records->each->approve());
```

See [Action authorization](authorization.md).

## The scope on the form endpoints

An action that carries a form fetches it when the dialog opens and posts it back. Both requests name the scope explicitly, because neither is tied to a single whitelist:

```bash
curl "https://example.test/admin/actions/form?resource=orders&action=reject&scope=record&record=42"
```

```php
// PanelActionFormController
private const SCOPES = ['record', 'table', 'bulk', 'infolist'];

'scope' => ['required', 'string', 'in:record,table,bulk,infolist'],
```

Anything else is a 422 before a resource is even resolved. The response describes the dialog:

```json
{
  "title": "Reject this order",
  "submitLabel": "Reject",
  "form": { },
  "submitUrl": "/admin/actions/form",
  "uploadUrl": "/admin/uploads?resource=orders&action=reject&scope=record&record=42",
  "context": { "resource": "orders", "action": "reject", "scope": "record" },
  "modal": { }
}
```

`submitUrl` and `uploadUrl` are built by the server, so the browser never assembles a panel URL. `context` is echoed back on submit, which is how the POST knows which whitelist to resolve against.

The submit then dispatches by scope: `bulk` runs `executeBulk()` over the `records` it was sent, a request with no record runs `executeWithoutRecord()` (and is 400 unless `isTableExecutable()`), and otherwise it runs `execute()` on the resolved record. Authorization is asked twice across the two requests — once to describe the dialog and again to run it — because opening a dialog and performing an operation are two separate permissions in time.

See [Action forms](forms.md).

## Uploads carry the scope too

A file field on an action's form uploads to the panel's upload endpoint with the same four-value allowlist:

```php
// PanelUploadController
private const ACTION_SCOPES = ['record', 'table', 'bulk', 'infolist'];
```

The URL is built by `PandaPanel\Support\FormEndpoints::uploadForAction()`:

```php
FormEndpoints::uploadForAction(
    string $resource,
    string $action,
    string $scope,
    Model|int|string|null $record = null,
): string
```

The upload is authorized by the action, not by the resource: an action the user may not run must not be a way to put a file on a disk.

## How the client decides the scope

The scope is derived, never typed by hand. In `resources/js/panel/composables/useActions.ts`:

```ts
function scopeOf(request: PendingAction): 'record' | 'table' | 'bulk' {
    if (request.table) {
        return 'table';
    }

    return request.records.length > 0 ? 'bulk' : 'record';
}
```

`runRecord()`, `runBulk()` and `runTable()` are what a table's buttons call, and each one produces the matching scope and posts to the matching endpoint. A view page uses `useInfolistActions.ts` instead, which always sends `scope: 'infolist'` and always posts to the infolist endpoint. The two composables are deliberately separate rather than one with a flag: folding them together would mean an action shown on one page could be run from the other.

## Nested resources add a parent

The action endpoints are one set per panel and carry no `{parentRecord}` path segment, so a nested resource sends its parent key in the payload:

```json
{ "resource": "posts", "action": "delete", "record": 7, "parent": 3 }
```

Both action controllers call the same `bindParentRecord()`: the parent is resolved and authorized through the parent resource exactly as route middleware does it for the resource's own pages, then bound with `PandaPanel\Support\ParentRecord::bind()`. Without it, an action on a nested resource would run against every parent's children at once. A missing or non-scalar `parent` on a nested resource is a 422 or a 404, never an unscoped run.

See [Nested resources](../resources/nested-resources.md).

## Relation actions are not one of these scopes

A relation manager's table has its own endpoints and its own whitelist:

| Method and path | Route name | Resolved with |
| --- | --- | --- |
| `POST {panel path}/relations/action` | `panel.{panelId}.relations.action` | `RelationTable::actionFor($manager, $owner, $name)` |
| `POST {panel path}/relations/bulk` | `panel.{panelId}.relations.bulk` | `RelationTable::bulkActionFor($manager, $owner, $name)` |

Both build the manager's table with the owner in hand — `$manager::table(TableSchema::make(), $owner)` — because whether a row may be detached is a question with two subjects. The request names a resource, a record (the owner), a relation, and a related key. Sending a relation action's name to `actions/record` finds nothing, because the resource's own table never declared it.

See [Relation actions](relation-actions.md).

## Gotchas

- **The same name in two scopes is normal, not a mistake.** `delete` as a row action and `delete` as a bulk action are the shape every resource uses. Duplicate names *within one set* throw `PanelSchemaException::duplicateActions()` at the line that declared them, because the endpoint resolves by name and would always run the first.
- **Header, toolbar and empty-state share one lookup.** Each set is checked for duplicates on its own, but `getTableAction()` searches all three in order and returns the first match. Two actions named `export`, one in the header and one in the toolbar, will both render and only the header one will ever run.
- **A modal action is reachable only through its parent in the table scopes.** `registerModalActions()` does not add anything to `getRecordAction()`, `getTableAction()` or `getBulkAction()`. `InfolistSchema::allActions()` is the exception: it folds each action's modal actions into the whitelist, so on a view page a dialog's action is addressable by name.
- **404, 400 and 403 mean three different things.** 404 is "this resource or this action does not exist in this scope", 400 is "it exists but carries no handler for this scope", and 403 is "it exists, it can run, and you may not". None of them leak whether an action exists in another scope.
- **A bulk selection is all-or-nothing about existence too.** `Resource::findRecords()` silently drops keys outside the resource scope, so the endpoint compares the count it got back with the count it was sent and 404s the whole request on a mismatch, rather than running a partial operation.
- **The record scope resolves through `Resource::findRecord()`, not `Resource::query()`.** That lookup lifts `SoftDeletingScope` for a resource that declares soft deletes, which is the only reason a restore can address a record the list hides. Every other scope of the query — tenant, panel narrowing, nested parent — still applies.

## See also

- [Action basics](overview.md)
- [Row actions](row-actions.md)
- [Table actions](table-actions.md)
- [Bulk actions](bulk-actions.md)
- [Infolist actions](infolist-actions.md)
- [Action forms](forms.md)
- [Action authorization](authorization.md)
- [Transactions](transactions.md)
- [Relation actions](relation-actions.md)
- [Record actions](../tables/record-actions.md)
- [Routing](../concepts/routing.md)
