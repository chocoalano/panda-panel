# Table Actions

A table action acts on the table rather than on a row: create a record, import a file, export the list, run a job over everything the filters describe. It carries no record, so it is authorized with `null` and its handler receives no model. Reach for one when the operation has no single subject.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Models\Order;
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
            ])
            ->headerActions([
                CreateAction::make(OrderResource::class),

                Action::make('closeStaleDrafts')
                    ->label('Close stale drafts')
                    ->icon('archive')
                    ->requiresConfirmation()
                    ->successMessage('Stale drafts closed.')
                    ->tableAction(static function (): void {
                        Order::query()
                            ->where('status', 'draft')
                            ->where('created_at', '<', now()->subMonth())
                            ->update(['status' => 'closed']);
                    }),
            ]);
    }
}
```

Both buttons appear above the table. The first navigates to the create page; the second posts its name to the panel's table-action endpoint, which resolves the handler and runs it.

## Three places to put one

```php
use PandaPanel\Tables\TableSchema;

TableSchema::headerActions(array $actions): self       // array<array-key, Action>
TableSchema::toolbarActions(array $actions): self
TableSchema::emptyStateActions(array $actions): self

TableSchema::getHeaderActions(): array                 // list<Action>
TableSchema::getToolbarActions(): array                // list<Action>
TableSchema::getEmptyStateActions(): array             // list<Action>
TableSchema::getTableAction(string $name): ?Action
```

| Method | Where it renders | What it is for |
| --- | --- | --- |
| `headerActions()` | the page header, above the table | the thing the page is *for* — create, import, export |
| `toolbarActions()` | the toolbar, beside the search box | the *view* of it — a bulk correction, a recalculation |
| `emptyStateActions()` | inside the empty state | the one place an action is most useful and least likely to be found |

The three are separate arrays because they read differently, but they share one lookup. `getTableAction()` searches header, toolbar and empty state in that order and returns the first match, so the endpoint does not care which bar a button was drawn in.

Reusing one list in two places is the usual shape for the empty state:

```php
use App\Panels\Admin\Resources\Orders\Exports\OrderExporter;
use App\Panels\Admin\Resources\Orders\Imports\OrderImporter;
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;

/** @return list<\PandaPanel\Actions\Action> */
private static function headerActions(): array
{
    return [
        CreateAction::modal(OrderResource::class),
        ImportAction::make(OrderImporter::class, OrderResource::class),
        ExportAction::make(OrderExporter::class, OrderResource::class),
    ];
}

// ...

$table
    ->headerActions(self::headerActions())
    ->emptyStateActions(self::headerActions())
    ->emptyState(
        heading: 'No orders match this view',
        description: 'Adjust the search or filters, or add one.',
        icon: 'shopping-cart',
    );
```

Each set is checked on its own for duplicate names and for actions that do nothing, and both failures throw `PanelSchemaException` at the line that declared them.

## The handler

```php
use PandaPanel\Actions\Action;

Action::tableAction(Closure(array<string, mixed>): void $callback): static
Action::isTableExecutable(): bool
Action::executeWithoutRecord(array $data = []): void
```

The closure takes one argument and it is not a model — it is the data the action's own form submitted, or `[]` when it has no form:

```php
use App\Models\Order;
use PandaPanel\Actions\Action;

Action::make('recalculateTotals')
    ->label('Recalculate totals')
    ->successMessage('Totals recalculated.')
    ->tableAction(static function (array $data): void {
        Order::query()->each(static fn (Order $order) => $order->recalculateTotal());
    });
```

`executeWithoutRecord()` wraps the call in a transaction on the same three-level rule every other write follows — the action's own `databaseTransaction()`, then the panel's, then on. See [Transactions](transactions.md).

There are no `before()` / `after()` hooks in this scope: those are declared for the record handler and are called by `execute()`, which a table action never reaches.

## Authorization

A table action has no record, so `authorize()` is asked with `null`:

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('closeStaleDrafts')
    ->authorize(static fn (?Model $record): bool => auth()->user()?->can('closeAny', Order::class) === true)
    ->tableAction(static fn () => Order::closeStaleDrafts());
```

An action refused here is absent from the serialized table entirely — `TableSchema::toArray()` builds `headerActions`, `toolbarActions` and `emptyState.actions` through `Action::toArray()` with no record, and a null result is filtered out. `PanelActionController::table()` asks `isAuthorizedFor(null)` again before running anything.

`visible()` works the same way and receives `null` too. Use it for "this does not apply right now" and `authorize()` for "you may not"; a closure written for a record and used in a header will fatal on the first dereference, which is why every built-in tests `$record !== null` first.

`authorizeEachUsing()` has no meaning here. It is asked by `executeBulk()`, and a table action never has a selection to iterate. See [Action authorization](authorization.md).

## Table actions with a form

```php
use App\Models\Order;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

Action::make('closeBefore')
    ->label('Close orders before a date')
    ->modalHeading('Close orders')
    ->modalSubmitLabel('Close them')
    ->schema(static fn (): FormSchema => FormSchema::make()->schema([
        DatePicker::make('before')->label('Created before')->required(),
        Select::make('status')
            ->options(['draft' => 'Draft', 'pending' => 'Pending'])
            ->required(),
    ]))
    ->tableAction(static function (array $data): void {
        Order::query()
            ->where('status', $data['status'])
            ->where('created_at', '<', $data['before'])
            ->update(['status' => 'closed']);
    });
```

The `schema()` closure still receives `?Model`, and for a table action it is always `null`. The dialog is described by `GET {panel path}/actions/form?scope=table` and submitted to `POST {panel path}/actions/form` with `scope: "table"`; the submit validates and dehydrates through that schema, then calls `executeWithoutRecord($data)` — and answers 400 `This action cannot be executed.` if the action never declared `tableAction()`.

Everything about the dialog itself — width, slide-over, sticky header, custom content — is the same as for any other action. See [Action modals](modals.md) and [Action forms](forms.md).

## Built-in table actions

```php
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;

CreateAction::make(string $resource): Action     // a link to the create page
CreateAction::modal(string $resource): Action    // the same form in a dialog
ImportAction::make(string $importer, string $resource): Action
ExportAction::make(string $exporter, string $resource): Action
```

| Factory | Name | Label | Icon | Variant | Type | Authorized by |
| --- | --- | --- | --- | --- | --- | --- |
| `CreateAction::make` | `create` | `New {resource label}` | `plus` | default | link | `canCreate()` |
| `CreateAction::modal` | `create` | `New {resource label}` | `plus` | default | form | `canCreate()` |
| `ImportAction::make` | `import` | Import | `upload` | outline | form | `canCreate()` |
| `ExportAction::make` | `export` | Export | `download` | outline | form | `canViewAny()` |

`CreateAction::make()` is hidden when the resource declares no `create` page, so a link to a route that does not exist is never drawn. `CreateAction::modal()` has no such condition: it opens `Resource::form()` in a dialog and writes through `tableAction()`, so it works on a resource that has no create page at all. Both go through the same `Resource::form()`, so the page and the dialog cannot validate or persist differently.

```php
CreateAction::modal(OrderResource::class)->label('New order');
```

Override the write when creating is not a plain insert — `tableAction()` replaces the handler and keeps the dialog:

```php
use App\Orders\PlaceOrder;
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\CreateAction;

CreateAction::modal(OrderResource::class)
    ->tableAction(static function (array $data): void {
        app(PlaceOrder::class)->handle($data);
    });
```

`ImportAction` and `ExportAction` are both form actions: the dialog asks which file and which mapping, or which columns and which format, and the submit runs it. `ExportAction::bulk()` is the same dialog in the bulk scope, exporting the selection instead of the list. See [Import and export actions](import-export.md).

## The list the action is about

A table action posts to an endpoint of its own, so the search, filters and sort that were on screen are not in the request unless they are sent. The client sends them as `tableState`, and the server reads them back through:

```php
use PandaPanel\Support\TableState;

TableState::fromRequest(?Request $request = null): array   // array<string, mixed>
```

This is what makes an export of "the list" mean the list the user was looking at. Taking it from the request is safe because it is not a permission: every value goes back through the table's own schema, which is the whitelist, so a filter the table never declared is ignored there exactly as it is when it arrives in a URL. The worst a crafted payload can describe is a list the user could have navigated to.

`TableState::fromRequest()` keeps string keys whose values are scalars or one level of array, which is every shape a query string can hold. Anything deeper did not come from one.

## The endpoint

```text
POST {panel path}/actions/table       route name: panel.{panelId}.actions.table
```

```json
{ "resource": "orders", "action": "closeStaleDrafts", "tableState": { "filters": { "status": "draft" } } }
```

What `PanelActionController::table()` checks, in order:

1. `resource` and `action` are present strings, or 422.
2. The resource slug resolves inside the panel resolved for this request, or 404 `Unknown resource.`
3. A nested resource's `parent` is resolved and bound, or 422/404.
4. `TableSchema::getTableAction($name)` finds the action, or 404 `Unknown action.`
5. `Action::isTableExecutable()`, or 400 `This action cannot be executed.`
6. `Action::isAuthorizedFor(null)`, or 403.

Then `executeWithoutRecord()` runs with no data and the response is a redirect back with `Action::getSuccessMessage()` flashed under `success`.

Note the ordering: unlike the record endpoint, authorization here is asked before any record work, because there is none to do.

## Notes

- **A table action with only `action()` is unreachable.** The endpoint requires `isTableExecutable()`, which only `tableAction()` sets. An action declared in `headerActions()` with a record handler renders and then answers 400.
- **`getTableAction()` spans all three sets.** Two actions named `export`, one in the header and one in the toolbar, both render and only the header one ever runs. Names have to be unique across the three, even though the duplicate check is per set.
- **The empty state gets its own array.** Header actions are not shown there. Passing the same list to both is deliberate and normal.
- **Declaring header actions does not enable row selection** — only `bulkActions()` does that. A header action never sees a selection.
- **`tableState` is advisory, not authority.** The endpoint does not use it directly; only actions that read it do, and they read it through the table schema. A table action that ignores it acts on everything the resource query can reach.
- **The success message is flashed, not returned.** The handler returns `void`; the redirect carries the message and the panel renders it as a toast. See [Toast notifications](../notifications/toast.md).
- **On a relation manager's table there is no table scope.** Relation endpoints cover a related record (`relations/action`) and a selection (`relations/bulk`). See [Relation actions](relation-actions.md).

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Row actions](row-actions.md)
- [Bulk actions](bulk-actions.md)
- [Action forms](forms.md)
- [Action modals](modals.md)
- [Action authorization](authorization.md)
- [Transactions](transactions.md)
- [Import and export actions](import-export.md)
- [Create, edit, view, and delete actions](crud-actions.md)
- [Header and toolbar actions](../tables/toolbar-actions.md)
