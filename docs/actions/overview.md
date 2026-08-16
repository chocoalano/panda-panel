# Action Basics

`PandaPanel\Actions\Action` is a backend-owned operation the frontend can request by name. You reach for one whenever a record, a selection, or a whole table needs something done to it that is not "open a form and save it" — approving an order, sending a reminder, exporting a list, deleting a batch.

The definition that crosses to the browser carries a label, an icon key, a variant, and confirmation copy. It never carries the handler. The frontend sends an action name, a resource slug, and record keys; the backend looks the action up in the schema that declared it, authorizes it, and runs it. An action that is not declared on the resource being addressed does not exist, no matter what the request says.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Models\Order;
use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('status')->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('check')
                    ->variant(ActionVariant::Outline)
                    ->requiresConfirmation(
                        heading: 'Approve this order?',
                        description: 'The customer is notified immediately.',
                        button: 'Approve',
                    )
                    ->successMessage('Order approved.')
                    ->authorize(static fn (?Model $record): bool => $record !== null
                        && OrderResource::canEdit($record))
                    ->action(static function (Order $record): void {
                        $record->approve();
                    }),
            ]);
    }
}
```

Every row now carries an Approve button, minus the rows the policy refuses. Pressing it opens a confirmation, posts `{"resource": "orders", "action": "approve", "record": 42}` to the panel's action endpoint, and redirects back with `Order approved.` in the `success` flash.

## The three kinds of action

An action's kind is derived from what it was given, never set directly. `Action::type()` reports it as a `PandaPanel\Actions\Enums\ActionType`.

| Given | `type()` | What the frontend does |
| --- | --- | --- |
| `url()` | `ActionType::Link` (`link`) | navigates to the server-produced URL |
| `schema()` or `form()` | `ActionType::Form` (`form`) | opens a dialog and fetches the form when it opens |
| neither | `ActionType::Callback` (`callback`) | posts the action name to the action endpoint |

```php
use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

// Link
Action::make('open')->url(static fn (Model $record): string => OrderResource::url('view', $record));

// Callback
Action::make('approve')->action(static fn (Model $record) => $record->approve());

// Form
Action::make('reject')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('reason')->required(),
    ]))
    ->action(static fn (Model $record, array $data) => $record->reject($data['reason']));
```

`url()` and `schema()` are checked in that order, so an action given both is a link.

## Where an action can live

An action object is the same class wherever it sits. What differs is the schema that declared it, which is the whitelist the endpoint resolves against — and each one is a separate whitelist on purpose.

| Scope | Declared with | Handler it needs | Endpoint |
| --- | --- | --- | --- |
| `record` | `TableSchema::recordActions()`, or `Column::action()` | `action()` | `actions.record` |
| `table` | `TableSchema::headerActions()`, `toolbarActions()`, `emptyStateActions()` | `tableAction()` | `actions.table` |
| `bulk` | `TableSchema::bulkActions()` | `bulkAction()`, or `action()` per record | `actions.bulk` |
| `infolist` | `InfolistSchema::actions()`, `Section::headerActions()`, `Entry::action()` | `action()` | `actions.infolist` |

```php
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Tables\TableSchema;

$table
    ->headerActions([CreateAction::modal(OrderResource::class)])
    ->recordActions([DeleteAction::make(OrderResource::class)])
    ->bulkActions([DeleteBulkAction::make(OrderResource::class)]);
```

`TableSchema::getTableAction()` searches header, toolbar, and empty-state actions together — the endpoint does not care which bar a button was rendered in, only that the table declared it. `TableSchema::getRecordAction()` searches the row actions *and* every column's own action, because a column action names a row, authorizes it, and changes it.

Declaring two actions with one name in the same set throws `PanelSchemaException::duplicateActions()`; declaring one that does nothing throws `PanelSchemaException::inertAction()`. Both fire at the line that declared them.

See [Action scopes](scopes.md), [Row actions](row-actions.md), [Table actions](table-actions.md), [Bulk actions](bulk-actions.md), and [Infolist actions](infolist-actions.md).

## The full API

Every setter is fluent and returns `static`.

### Identity and presentation

| Method | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $name): static` | — |
| `label` | `label(string $label): static` | `Str::headline($name)` |
| `icon` | `icon(string $icon): static` | none |
| `variant` | `variant(ActionVariant $variant): static` | `ActionVariant::Ghost` |
| `requiresConfirmation` | `requiresConfirmation(bool $requires = true, ?string $heading = null, ?string $description = null, ?string $button = null): static` | off |
| `successMessage` | `successMessage(string $message): static` | `"{Label} completed."` |
| `successMessageUsing` | `successMessageUsing(Closure $callback): static` | none — `fn (int $count): string` |

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

Action::make('approve')
    ->label('Approve')
    ->icon('check')
    ->variant(ActionVariant::Default)
    ->requiresConfirmation()
    ->successMessageUsing(static fn (int $count): string => "{$count} approved.");
```

`ActionVariant` is a closed set — `Default`, `Secondary`, `Outline`, `Ghost`, `Destructive` — because each case maps to a shadcn button variant on the frontend; a free-form string would render unstyled.

`requiresConfirmation()` with no copy fills in `"{Label}?"`, `This cannot be undone.`, and `"{Label}"` for the heading, description, and button.

A name may contain letters, numbers, dashes, dots, and underscores and nothing else. It travels to the endpoint as an identifier, so `PanelSchemaException::unusableActionName()` refuses anything else at construction rather than letting a button fail only when pressed.

### Visibility and authorization

| Method | Signature | Asked with |
| --- | --- | --- |
| `visible` | `visible(Closure $callback): static` | `?Model` |
| `authorize` | `authorize(Closure $callback): static` | `?Model` |
| `authorizeEachUsing` | `authorizeEachUsing(Closure $callback): static` | `Model`, per selected record |
| `isVisibleFor` | `isVisibleFor(?Model $record): bool` | — |
| `isAuthorizedFor` | `isAuthorizedFor(?Model $record): bool` | — |
| `isAuthorizedForEach` | `isAuthorizedForEach(Model $record): bool` | — |

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->visible(static fn (?Model $record): bool => $record?->getAttribute('status') === 'pending')
    ->authorize(static fn (?Model $record): bool => $record !== null && Gate::allows('approve', $record));
```

Both are called with `null` when the action is serialized without a record — a header action, a bulk action, or the definition sent for a table. A closure that dereferences `$record` unguarded fails on the first render, which is why every built-in begins with `$record !== null &&`.

`visible()` hides without implying refusal; `authorize()` is the permission and is asked again on execution. See [Action authorization](authorization.md).

### Handlers

| Method | Signature | Runs |
| --- | --- | --- |
| `url` | `url(Closure $callback): static` | never — `fn (Model $record): string` |
| `action` | `action(Closure $callback): static` | `fn (Model $record, array $data): void` |
| `bulkAction` | `bulkAction(Closure $callback): static` | `fn (Collection $records, array $data): void` |
| `tableAction` | `tableAction(Closure $callback): static` | `fn (array $data): void` |
| `before` | `before(Closure $callback): static` | before the record handler, same transaction |
| `after` | `after(Closure $callback): static` | after it, same transaction |

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->before(static fn (Model $record) => Log::info('approving', ['id' => $record->getKey()]))
    ->action(static fn (Model $record) => $record->approve())
    ->after(static fn (Model $record) => event(new App\Events\RecordApproved($record)));

Action::make('approve')->bulkAction(static function (Collection $records): void {
    $records->each->approve();
});

Action::make('purge')->tableAction(static function (): void {
    App\Models\Order::query()->where('status', 'abandoned')->delete();
});
```

`$data` is what the action's own form submitted, already validated and dehydrated. A handler declared with one argument never sees it, which is why adding a form to an existing action is additive.

`before()` and `after()` live on the action rather than on a page because the action endpoint executes without a page instance — a hook declared on the page would never be called.

### Execution

| Method | Signature | Notes |
| --- | --- | --- |
| `execute` | `execute(Model $record, array $data = []): void` | `before`, handler, `after` in one transaction |
| `executeBulk` | `executeBulk(Collection $records, array $data = []): void` | authorizes every record first |
| `executeWithoutRecord` | `executeWithoutRecord(array $data = []): void` | the table handler, in one transaction |
| `databaseTransaction` | `databaseTransaction(bool $databaseTransaction = true): static` | `null` inherits the panel |
| `hasDatabaseTransaction` | `hasDatabaseTransaction(): ?bool` | — |
| `affectedCount` | `affectedCount(): int` | 1 for a record run, the selection size for a bulk one |

`executeBulk()` walks the collection and calls `isAuthorizedForEach()` on every record before touching any. A refusal throws `Symfony\Component\HttpKernel\Exception\HttpException` with status 403 and the message `You may not {label} every selected record.` With no `bulkAction()` handler it falls back to `execute()` once per record. See [Transactions](transactions.md).

### Introspection

`getName()`, `getLabel()`, `getIcon()`, `getVariant()`, `getSuccessMessage()`, `type()`, `hasForm()`, `hasModal()`, `getModal()`, `resolveSchema(?Model $record): ?FormSchema`, `getModalActions()`, `getModalAction(string $name): ?Action`, `isExecutable()`, `isBulkExecutable()`, `isTableExecutable()`, `isInert()`.

`getIcon()` and `getVariant()` answer what the action *declares*, whatever a record would make of its visibility — which is what lets a panel-wide configurator decide something from them.

## What crosses the wire

`Action::toArray(?Model $record = null): ?array` returns `null` when the action is hidden or unauthorized for that record, and the caller drops it. Otherwise:

```php
[
    'name' => 'approve',
    'label' => 'Approve',
    'icon' => 'check',
    'variant' => 'outline',
    'type' => 'callback',
    'url' => null,          // only for a link action with a record
    'formUrl' => null,      // only when form() gave an external URL
    'hasForm' => false,     // true when schema() was given
    'modal' => null,        // Modal::toArray(), or null when none was configured
    'modalActions' => [],
    'confirmation' => ['heading' => '…', 'description' => '…', 'button' => '…'],
]
```

Nothing executable is in there. A callback action carries its name; the handler stays on the server. The TypeScript mirror is `resources/js/panel/types/action.ts`.

## How execution stays safe

The request carries an action name, a resource slug, and record keys. Nothing else. `PandaPanel\Http\Controllers\PanelActionController` then:

1. resolves the panel for this request;
2. looks the resource up in **that panel's** registry — a resource from another panel does not exist here;
3. finds the action in the schema that declared it — one the resource never declared does not exist;
4. loads records through `Resource::findRecord()` / `findRecords()` — a key outside the resource scope resolves to nothing;
5. authorizes;
6. runs the handler inside a transaction.

A row action the policy refuses is absent from that row, and the endpoint re-checks anyway. A bulk action authorizes every record before touching any of them, so a selection containing one forbidden record changes nothing.

## Endpoints

All are registered per panel under the panel's path, named `panel.{panelId}.actions.*`.

| Route name | Method and path | Controller |
| --- | --- | --- |
| `actions.record` | `POST {panel}/actions/record` | `PanelActionController::record()` |
| `actions.bulk` | `POST {panel}/actions/bulk` | `PanelActionController::bulk()` |
| `actions.table` | `POST {panel}/actions/table` | `PanelActionController::table()` |
| `actions.infolist` | `POST {panel}/actions/infolist` | `PanelActionController::infolist()` |
| `actions.reorder` | `POST {panel}/actions/reorder` | `PanelActionController::reorder()` |
| `actions.cell` | `POST {panel}/actions/cell` | `PanelActionController::cell()` |
| `actions.form` | `GET {panel}/actions/form` | `PanelActionFormController::show()` |
| `actions.submit` | `POST {panel}/actions/form` | `PanelActionFormController::submit()` |

One endpoint set per panel rather than per resource: the resource is part of the payload and is resolved against this panel's registry.

A nested resource also sends `parent`, which both action controllers resolve and bind exactly as route middleware does for that resource's own pages. Without it an action on a nested resource would run against every parent's children at once.

## Panel-wide defaults

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

`Panel::configureActions(Closure $callback): self` runs inside `Action::make()`, so anything the schema then sets still wins. It is read through the current panel rather than a static registry, so two panels can differ and nothing leaks between requests.

## Gotchas

- **An action with no way to do anything is refused at declaration.** `isInert()` is true only when `url()`, `action()`, `bulkAction()`, `tableAction()`, `schema()`, `form()`, a modal, and registered modal actions are all absent. Configuring a modal is enough to pass the check, so `Action::make('x')->modalHeading('…')` is accepted and still 400s when pressed.
- **The scope decides which handler is looked for.** A record action needs `action()`; posting a link action to `actions.record` is a 400, and a bulk action with neither `bulkAction()` nor `action()` is a 400 too.
- **`authorize()` runs twice for a form action.** Once to describe the dialog and once to submit it — opening a dialog and performing an operation are two separate permissions in time.
- **Success messages are flashed, not returned.** The handler returns nothing; the endpoint redirects back with `success` and the panel renders it as a toast.
- **`executeBulk()` is not itself wrapped in a transaction.** With a `bulkAction()` handler the wrapping is yours; falling back to `execute()` per record gives one transaction per record. The built-in bulk actions open an explicit `DB::transaction()` for exactly this reason.
- **The bulk and reorder endpoints cap at 500 keys.** Anything larger is a table action that queues a job.
- **Actions are rebuilt per request.** The schema is re-created on every render and on every endpoint hit, so a closure inside an action sees the current user, tenant, and locale — and holding an `Action` instance between requests is not supported.

## See also

- [Action scopes](scopes.md)
- [Row actions](row-actions.md), [Table actions](table-actions.md), [Bulk actions](bulk-actions.md), [Infolist actions](infolist-actions.md)
- [Action authorization](authorization.md)
- [Action modals](modals.md) and [Action forms](forms.md)
- [Transactions](transactions.md)
- [Built-in actions](built-in-actions.md), [CRUD actions](crud-actions.md), [Replicate](replicate.md), [Restore and force delete](restore-force-delete.md)
- [Import and export actions](import-export.md), [Relation actions](relation-actions.md), [Custom actions](custom-actions.md)
- [Record actions on a table](../tables/record-actions.md)
- [TableSchema basics](../tables/overview.md)
- [Authorization](../concepts/authorization.md)
