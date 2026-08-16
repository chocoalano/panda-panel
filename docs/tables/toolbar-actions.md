# Toolbar Actions

Some actions are about the table rather than about a row: export the list, refresh a cache, seed some demo data, open a create dialog. They are declared in one of three places — the page header, the toolbar beside the search box, or the empty state — and they all work the same way: no record, a handler declared with `tableAction()`, and authorization asked with `null`.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use Illuminate\Support\Facades\Cache;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
            ])
            ->toolbarActions([
                Action::make('refresh')
                    ->label('Refresh totals')
                    ->icon('refresh-cw')
                    ->successMessage('Totals recalculated.')
                    ->tableAction(static function (): void {
                        Cache::forget('orders.totals');
                    }),
            ]);
    }
}
```

The button appears in the toolbar. Pressing it posts the action's name, the server looks it up in this table's schema, authorizes it, and runs it.

## The three places

```php
use PandaPanel\Actions\Action;
use PandaPanel\Tables\TableSchema;

TableSchema::headerActions(array $actions): self       // array<array-key, Action>
TableSchema::toolbarActions(array $actions): self
TableSchema::emptyStateActions(array $actions): self
TableSchema::getHeaderActions(): array                 // list<Action>
TableSchema::getToolbarActions(): array
TableSchema::getEmptyStateActions(): array
TableSchema::getTableAction(string $name): ?Action     // searches all three
```

| Set | Rendered | For |
| --- | --- | --- |
| `headerActions()` | the page header, beside the resource's own "New …" button | the thing the page is *for* — create, import, export |
| `toolbarActions()` | the toolbar, next to search and filters | the *view* of it — refresh, recalculate, switch a mode |
| `emptyStateActions()` | inside the empty state | an empty table is where an action is most useful and least likely to be found |

Three places and one lookup: `getTableAction()` searches header, toolbar, and empty state in that order, because the endpoint that runs them does not care which bar an action was rendered in — only that the table declared it somewhere.

```php
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

$schema = TableSchema::make()
    ->columns([TextColumn::make('name')])
    ->headerActions([Action::make('export')->tableAction(static fn () => null)])
    ->emptyStateActions([Action::make('seed')->tableAction(static fn () => null)]);

$schema->getTableAction('export');    // Action
$schema->getTableAction('seed');      // Action
$schema->getTableAction('invented');  // null
```

Each setter refuses two names in the same set and refuses an action that does nothing, at the line that declared it. Names are only unique *within* a set — a header `export` and a toolbar `export` would both resolve to the header one, so give them different names.

## The handler

```php
use Closure;
use PandaPanel\Actions\Action;

Action::tableAction(Closure(array<string, mixed>): void $callback): static
Action::isTableExecutable(): bool
Action::executeWithoutRecord(array $data = []): void
```

`tableAction()` is what makes an action runnable without a record. The array it receives is what the action's own form submitted, and is empty for an action with no form. The handler runs inside a transaction, exactly as a record action's does, honouring `databaseTransaction()` and the panel's default.

```php
use App\Models\Order;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

Action::make('purgeCancelled')
    ->label('Purge cancelled')
    ->icon('trash-2')
    ->variant(ActionVariant::Ghost)
    ->requiresConfirmation(
        heading: 'Delete every cancelled order?',
        description: 'This cannot be undone.',
        button: 'Delete them',
    )
    ->authorize(static fn (): bool => auth()->user()?->is_admin === true)
    ->successMessage('Cancelled orders removed.')
    ->tableAction(static function (): void {
        Order::query()->where('status', 'cancelled')->delete();
    });
```

Everything else on `Action` works here — `label()`, `icon()`, `variant()`, `requiresConfirmation()`, `successMessage()`, `successMessageUsing()`, `visible()`, `authorize()`, `modal*()`, `slideOver()`, `databaseTransaction()`. See [Record actions](record-actions.md) for the full list.

## Authorization with no record

A table action is resolved with `null`, the way a bulk action's authorization is asked before anything is selected:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('export')
    ->visible(static fn (?Model $record): bool => true)
    ->authorize(static fn (?Model $record): bool => auth()->user()?->can('export', Order::class) === true)
    ->tableAction(static fn () => null);
```

An action the closure refuses is **absent** from the serialized set rather than rendered and then refused. Both closures also run with `null` for record actions when the schema is serialized without a record, so a closure written for one place must tolerate the other.

## Actions with a form

A table action can open a dialog with a form, submit it, and receive the validated data:

```php
use App\Models\Period;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\FormSchema;

Action::make('closePeriod')
    ->label('Close period')
    ->modalHeading('Close an accounting period')
    ->modalSubmitLabel('Close')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        DatePicker::make('until')->label('Close everything up to')->required(),
    ]))
    ->tableAction(static function (array $data): void {
        Period::closeUpTo($data['until']);
    });
```

The schema is fetched when the dialog opens rather than serialized into the page, and the submitted data is validated and dehydrated by that same schema before the handler sees it — a key the form never declared is discarded.

## Built-in table actions

```php
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;

CreateAction::make(string $resource): Action     // a link to the create page
CreateAction::modal(string $resource): Action    // the same form in a dialog
ExportAction::make(string $exporter, string $resource): Action
ImportAction::make(string $importer, string $resource): Action
```

```php
$table->headerActions([
    CreateAction::modal(UserResource::class)->label('New user'),
    ImportAction::make(UserImporter::class, UserResource::class),
    ExportAction::make(UserExporter::class, UserResource::class),
]);
```

`CreateAction::modal()` is a table action: it opens the resource's own form in a dialog and writes through `tableAction()`, so a dialog and the create page cannot validate or persist differently. `ExportAction::make()` exports the list as it is currently filtered; `ExportAction::bulk()` is the selection and belongs in [`bulkActions()`](bulk-actions.md). See [Export action](../import-export/export-action.md) and [Import action](../import-export/import-action.md).

`CreateAction::make()` is a **link** action, and a link belongs on a row rather than in one of these bars. A link action serialized without a safe URL is dropped from the payload rather than rendered as a button that posts. Use `CreateAction::modal()` in these bars; the list page already renders a plain "New {label}" link of its own when the resource declares a create page and `canCreate()` allows it.

## The empty state

```php
use PandaPanel\Tables\TableSchema;

TableSchema::emptyState(string $heading, ?string $description = null, ?string $icon = null): self
TableSchema::emptyStateActions(array $actions): self
TableSchema::emptyStateComponent(string $component): self
```

| Piece | Default |
| --- | --- |
| heading | `No records found` |
| description | `null` |
| icon | `null` |
| actions | `[]` |
| component | `null` |

```php
$table
    ->emptyState(
        heading: 'No orders match this view',
        description: 'Adjust the search or filters, or create one.',
        icon: 'shopping-cart',
    )
    ->emptyStateActions([CreateAction::modal(OrderResource::class)]);
```

Offering the same actions in the header and the empty state is the common case and is fine — they are separate sets, so a shared private method that returns both keeps them from drifting.

`emptyStateComponent()` replaces the whole empty state with a component of this application's, named by a build-time registry key under `resources/js/pages/Panels/{Panel}/EmptyStates/`, never markup:

```php
$table->emptyStateComponent('Panels/Admin/EmptyStates/NoOrders');
```

An unknown name falls back to the ordinary empty state rather than rendering nothing. See [Component registries](../concepts/component-registries.md).

## What is serialized

```php
$schema->toArray();
// [
//     'headerActions' => [ /* Action::toArray(null), nulls filtered out */ ],
//     'toolbarActions' => [ ... ],
//     'emptyState' => [
//         'heading' => 'No records found',
//         'description' => null,
//         'icon' => null,
//         'component' => null,
//         'actions' => [ ... ],
//     ],
//     ...
// ]
```

The list page renders its own header actions — the resource's "New {label}" link, when the resource declares a create page and `canCreate()` allows it — followed by the table's `headerActions`.

## The endpoint

```text
POST {panel path}/actions/table        route name: panel.{panelId}.actions.table
```

```json
{ "resource": "orders", "action": "refresh" }
```

A nested resource also sends `parent`. What the controller checks, in order:

1. The resource slug resolves inside the panel resolved for this request, or 404.
2. `TableSchema::getTableAction($name)` finds it, or **404 — "Unknown action."**
3. `Action::isTableExecutable()`, or **400 — "This action cannot be executed."**
4. `Action::isAuthorizedFor(null)`, or 403.

Then `executeWithoutRecord()` runs and the response redirects back with a `success` flash of `Action::getSuccessMessage()`.

An action with a form goes to `POST {panel path}/actions/submit` with `scope: "table"`, which resolves through `getTableAction()` too and applies the schema's validation before running the same handler.

## Notes

- **`tableAction()` is what makes it runnable.** An action carrying only `action()` is a record handler; posting it as a table action answers 400.
- **A link action does not work in these bars.** `url()` is resolved from a record, and there is no record here, so the action is absent from the serialized set. Link to a page from a [record action](record-actions.md) or from the page's own header.
- **A table action cannot see the selection.** That is a [bulk action](bulk-actions.md), and it is authorized per record before anything is touched.
- **`getTableAction()` returns the first match across the three sets.** Two actions sharing a name across sets are indistinguishable to the endpoint, and the per-set uniqueness check will not catch it.
- **An inert action throws at declaration.** `PanelSchemaException::inertAction()` names it and lists what to add, rather than letting a button render that does nothing when pressed.
- **Header actions are also the empty state's most useful actions.** Nothing declares one from the other; pass the same array to both.
- **Relation manager tables do not run table actions.** Their toolbar renders the relation's own header actions — create, attach, associate — and the relation endpoints have no table-action route. See [Relation tables](../relations/relation-tables.md).
- **Panel-wide defaults apply here too.** `Panel::configureActions()` runs as each action is built, so a house style for icons or confirmations reaches toolbar actions without each table repeating it.

## See also

- [Record actions](record-actions.md)
- [Bulk actions](bulk-actions.md)
- [Table actions](../actions/table-actions.md)
- [Action modals](../actions/modals.md)
- [Action forms](../actions/forms.md)
- [Export action](../import-export/export-action.md)
- [Import action](../import-export/import-action.md)
- [Component registries](../concepts/component-registries.md)
- [Tables overview](overview.md)
