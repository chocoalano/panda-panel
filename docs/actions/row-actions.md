# Row Actions

A row action is an operation on one record, reachable from the row that shows it: view, edit, delete, approve, print. It is declared on the table schema, resolved separately for every record the table serializes, and executed by name through the panel's action endpoint. Reach for one whenever the thing a user wants to do concerns a single record.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;
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
            ->recordActions([
                ViewAction::make(OrderResource::class),
                EditAction::make(OrderResource::class),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('check')
                    ->successMessage('Order approved.')
                    ->action(static fn (Model $record) => $record->approve()),
                DeleteAction::make(OrderResource::class),
            ]);
    }
}
```

Every row now carries those four buttons, minus any the policy refuses for that particular record.

## Declaring them

```php
use PandaPanel\Actions\Action;
use PandaPanel\Tables\TableSchema;

TableSchema::recordActions(array $actions): self      // array<array-key, Action>
TableSchema::getRecordActions(): array                // list<Action>
TableSchema::getRecordAction(string $name): ?Action
```

`recordActions()` refuses two things at the line that declared them, rather than at render time:

- **Two actions with the same name.** `PanelSchemaException::duplicateActions()` — the endpoint resolves by name, so the second could never run.
- **An action that does nothing.** `PanelSchemaException::inertAction()` — no `url()`, `action()`, `bulkAction()`, `tableAction()`, `schema()`, `form()`, `modal()`, or registered modal actions. The message names the action and lists what to add.

An action name may contain letters, numbers, dots, dashes and underscores and nothing else. It travels to the endpoint as an identifier, so `PanelSchemaException::unusableActionName()` refuses anything else in the constructor rather than letting it render as a button that only fails when pressed.

## The three kinds

`Action::type()` is derived from what the action carries; you never set it.

```php
use PandaPanel\Actions\Enums\ActionType;

Action::type(): ActionType
```

| Kind | Declared with | `type()` | What the client does |
| --- | --- | --- | --- |
| Link | `url()` | `ActionType::Link` (`link`) | navigates to the server-produced URL |
| Form | `schema()` or `form()` | `ActionType::Form` (`form`) | opens a dialog, fetches the schema, submits it |
| Callback | `action()` | `ActionType::Callback` (`callback`) | posts the action name to the record endpoint |

`url()` wins over the other two, and `schema()`/`form()` win over `action()`. An action declaring both a URL and a handler therefore renders as a link, and its handler is never reached from the table — though the endpoint would still run it for a request that named it, because `isExecutable()` is true. Declare one kind per action.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::url(Closure(Model): string $callback): static
Action::form(Closure(?Model): string $callback): static
Action::action(Closure(Model, array<string, mixed>): void $callback): static
```

`url()` is called with the record, so a link can point at that record's page:

```php
Action::make('invoice')
    ->label('Invoice')
    ->icon('file-text')
    ->url(static fn (Model $record): string => route('invoices.show', $record));
```

The serialized URL is kept only when it is relative or uses `http`, `https`, `mailto`, or `tel`.
Unsafe schemes make the link action disappear from the row payload, and the Vue button checks again
before rendering an anchor.

`form()` is the external-form variant: it returns a URL the dialog fetches its schema from, and is what a relation action uses because that one names an owner and an operation the panel's action-form endpoint knows nothing about. For a form of the action's own, use `schema()` — see below.

## Every method a row action uses

All fluent, all returning `static`.

| Method | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $name): static` | — |
| `label` | `label(string $label): static` | `Str::headline($name)` |
| `icon` | `icon(string $icon): static` | none |
| `variant` | `variant(ActionVariant $variant): static` | `ActionVariant::Ghost` |
| `requiresConfirmation` | `requiresConfirmation(bool $requires = true, ?string $heading = null, ?string $description = null, ?string $button = null): static` | off |
| `successMessage` | `successMessage(string $message): static` | `"{Label} completed."` |
| `successMessageUsing` | `successMessageUsing(Closure(int): string $callback): static` | none |
| `visible` | `visible(Closure(?Model): bool $callback): static` | visible |
| `authorize` | `authorize(Closure(?Model): bool $callback): static` | allowed |
| `url` | `url(Closure(Model): string $callback): static` | none |
| `form` | `form(Closure(?Model): string $callback): static` | none |
| `action` | `action(Closure(Model, array): void $callback): static` | none |
| `before` | `before(Closure(Model, array): void $callback): static` | none |
| `after` | `after(Closure(Model, array): void $callback): static` | none |
| `schema` | `schema(Closure(?Model): FormSchema $callback): static` | none |
| `modal` | `modal(Closure(Modal): void $callback): static` | none |
| `modalWidth` | `modalWidth(ModalWidth $width): static` | `ModalWidth::Medium` |
| `slideOver` | `slideOver(bool $slideOver = true): static` | off |
| `modalHeading` | `modalHeading(string $heading): static` | the action label |
| `modalDescription` | `modalDescription(string $description): static` | none |
| `modalSubmitLabel` | `modalSubmitLabel(string $label): static` | the action label |
| `modalContent` | `modalContent(string $component, array $config = []): static` | none |
| `registerModalActions` | `registerModalActions(array $actions): static` | `[]` |
| `databaseTransaction` | `databaseTransaction(bool $databaseTransaction = true): static` | `null`, inheriting the panel |

The readers, for anything that has to ask an action about itself:

```php
Action::getName(): string
Action::getLabel(): string
Action::getIcon(): ?string
Action::getVariant(): ActionVariant
Action::getSuccessMessage(): string
Action::affectedCount(): int
Action::type(): ActionType
Action::isInert(): bool
Action::isExecutable(): bool
Action::isVisibleFor(?Model $record): bool
Action::isAuthorizedFor(?Model $record): bool
Action::hasForm(): bool
Action::hasModal(): bool
Action::getModal(): Modal
Action::toArray(?Model $record = null): ?array
```

`getIcon()` and `getVariant()` answer what the action *declares*, whatever a record would make of its visibility — `toArray()` returns `null` in that case and is useless for asking.

## Presentation

```php
use PandaPanel\Actions\Enums\ActionVariant;

Action::make('approve')
    ->label('Approve')
    ->icon('check')
    ->variant(ActionVariant::Outline);
```

`ActionVariant` is a closed set, because each case maps to a shadcn button variant on the frontend and a free-form string would render unstyled:

| Case | Value |
| --- | --- |
| `ActionVariant::Default` | `default` |
| `ActionVariant::Secondary` | `secondary` |
| `ActionVariant::Outline` | `outline` |
| `ActionVariant::Ghost` | `ghost` (the default) |
| `ActionVariant::Destructive` | `destructive` |

`icon()` takes a registry key, not markup. The label falls back to `Str::headline($name)`, so `Action::make('approveOrder')` reads as "Approve Order" until you say otherwise.

## Confirmation

```php
Action::make('approve')
    ->requiresConfirmation(
        heading: 'Approve this order?',
        description: 'The customer is notified immediately.',
        button: 'Approve',
    );
```

All three arguments are optional. Left out, they default to `"{Label}?"`, `"This cannot be undone."` and the label. Passing `false` as the first argument turns confirmation back off, which is what a panel-wide configurator needs to be able to do.

A confirming action is held on the client until the dialog is accepted, so the request is made once, after the user agreed — the server sees an ordinary action request either way.

## Visibility and authorization

```php
use Illuminate\Database\Eloquent\Model;

Action::make('approve')
    ->visible(static fn (?Model $record): bool => $record?->getAttribute('status') === 'pending')
    ->authorize(static fn (?Model $record): bool => $record !== null
        && auth()->user()?->can('approve', $record) === true);
```

Both receive `?Model` and both are called once per record as the table serializes it. They mean different things: `visible()` hides an action that does not apply to this record, `authorize()` refuses one the user may not run. Either returning false makes `toArray()` return `null` and the row drops the action entirely.

Hiding the button is never what protects the record. `PanelActionController::record()` asks `isAuthorizedFor()` again before running anything, and a request naming an action the row did not draw is answered on its merits, not on what was rendered.

```php
it('enforces the policy on execution, not on the button being rendered', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->target->id,
    ])->assertForbidden();
});
```

## The handler and its hooks

```php
use App\Events\OrderApproved;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->before(static fn (Model $record) => Log::info('approving', ['id' => $record->getKey()]))
    ->action(static fn (Order $record) => $record->approve())
    ->after(static fn (Model $record) => event(new OrderApproved($record)));
```

`before()`, the handler, and `after()` share one transaction, so an `after` hook that throws undoes the operation rather than leaving it half applied. They live on the action rather than on a page because the action endpoint executes without a page instance — a hook declared on `EditRecord` would never be called for a row action.

The handler returns nothing. Success is a flash: the endpoint redirects `back()` with `Action::getSuccessMessage()` under the `success` key, and the panel renders it as a toast.

```php
Action::make('approve')->successMessage('Order approved.');
Action::make('approve')->successMessageUsing(static fn (int $count): string => "{$count} approved.");
```

`successMessageUsing()` receives `affectedCount()`, which is 1 after a record action and the size of the selection after a bulk one. Without either, the message is `"{Label} completed."`

See [Transactions](transactions.md) for how the wrapping is decided and how to turn it off for an action that calls an external service.

## Row actions with a form

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

Action::make('reject')
    ->label('Reject')
    ->modalHeading('Reject this order')
    ->modalSubmitLabel('Reject')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('reason')->required()->maxLength(1000),
    ]))
    ->action(static fn (Order $record, array $data) => $record->reject($data['reason']));
```

```php
Action::schema(Closure(?Model): FormSchema $callback): static
Action::hasForm(): bool
Action::resolveSchema(?Model $record): ?FormSchema
```

The schema is built per record and fetched when the dialog opens rather than serialized into the row: a table of twenty records would otherwise ship twenty copies of a form to open at most one. What reaches the handler has been validated and dehydrated by that schema, so a key the form never declared is discarded — the same rule a resource form follows.

A handler that takes only `Model $record` never sees `$data`, which is why adding a form to an existing action is additive.

See [Action forms](forms.md) and [Action modals](modals.md).

## Column actions

A cell can carry an action of its own:

```php
use App\Models\Order;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Columns\TextColumn;

Column::action(Action $action): static
Column::getAction(): ?Action

TextColumn::make('reference')->action(
    Action::make('approve')->action(static fn (Order $record) => $record->approve()),
);
```

It is a row action in every sense that matters: `TableSchema::getRecordAction()` searches the row actions **and** every column's action, so the endpoint finds it without a second lookup and it is authorized the same way. A cell whose action the user may not run renders as an ordinary value rather than as a button that answers 403.

See [Editable columns](../tables/editable-columns.md) for the other way a cell writes.

## Where the buttons sit

```php
use PandaPanel\Tables\Enums\RecordActionsPosition;

$table
    ->recordActionsPosition(RecordActionsPosition::AfterColumns)
    ->recordActionsLabel('Manage')
    ->frozenActions();
```

```php
TableSchema::recordActionsPosition(RecordActionsPosition $position): self
TableSchema::recordActionsLabel(string $label): self
TableSchema::frozenActions(bool $frozen = true): self
TableSchema::hasFrozenActions(): bool
```

| Case | Value | Effect |
| --- | --- | --- |
| `RecordActionsPosition::AfterColumns` | `after_columns` | a column of its own after the data columns (the default) |
| `RecordActionsPosition::BeforeColumns` | `before_columns` | a column of its own before them |
| `RecordActionsPosition::AfterCells` | `after_cells` | no column at all; folded into the first column's cell |

`frozenActions()` keeps that column in view while a wide table scrolls sideways, and is off by default because pinning costs horizontal room. See [Pinned columns](../tables/pinned-columns.md).

## Built-in row actions

Each is a static factory returning a configured `PandaPanel\Actions\Action`, so anything above can still be chained onto it.

```php
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\ReplicateAction;
use PandaPanel\Actions\RestoreAction;
use PandaPanel\Actions\ViewAction;

ViewAction::make(string $resource): Action
EditAction::make(string $resource): Action
DeleteAction::make(string $resource): Action
RestoreAction::make(string $resource): Action
ForceDeleteAction::make(string $resource): Action
ReplicateAction::make(string $resource, array $except = [], ?Closure $using = null): Action
```

| Factory | Name | Label | Icon | Variant | Type | Authorized by |
| --- | --- | --- | --- | --- | --- | --- |
| `ViewAction` | `view` | View | `eye` | ghost | link | `canView($record)` |
| `EditAction` | `edit` | Edit | `pencil` | ghost | link | `canEdit($record)` |
| `DeleteAction` | `delete` | Delete | `trash-2` | destructive | callback | `canDelete($record)` |
| `RestoreAction` | `restore` | Restore | `rotate-ccw` | outline | callback | `canRestore($record)` |
| `ForceDeleteAction` | `forceDelete` | Delete permanently | `trash-2` | destructive | callback | `canForceDelete($record)` |
| `ReplicateAction` | `replicate` | Replicate | `copy` | outline | callback | `canCreate()` and `canView($record)` |

`ViewAction` and `EditAction` are hidden when the resource declares no `view` or `edit` page, so a link to a route that does not exist is never drawn. `RestoreAction` and `ForceDeleteAction` are hidden for a record that is not trashed. `DeleteAction`, `ForceDeleteAction` and `ReplicateAction` confirm by default.

Overriding one is ordinary chaining:

```php
DeleteAction::make(OrderResource::class)
    ->icon('trash')
    ->requiresConfirmation(
        heading: 'Delete this order?',
        description: 'The customer keeps their copy of the invoice.',
        button: 'Delete it',
    );
```

See [Create, edit, view, and delete actions](crud-actions.md), [Replicate](replicate.md), and [Restore and force delete](restore-force-delete.md).

## What a row carries

`TableSchema::toRow()` resolves the actions for the record it is serializing, and `Action::toArray(?Model $record)` returns `null` for one that is hidden or refused:

```php
[
    'key' => 42,
    'group' => null,
    'cells' => ['reference' => 'ORD-42'],
    'cellMeta' => ['reference' => ['action' => ['name' => 'approve', /* ... */]]],
    'actions' => [
        [
            'name' => 'edit',
            'label' => 'Edit',
            'icon' => 'pencil',
            'variant' => 'ghost',
            'type' => 'link',
            'url' => '/admin/orders/42/edit',
            'formUrl' => null,
            'hasForm' => false,
            'modal' => null,
            'modalActions' => [],
            'confirmation' => null,
        ],
    ],
]
```

Nothing executable ever crosses. A callback action carries its name; the handler stays on the server, which is what makes "an action the resource never declared does not exist" true rather than aspirational.

## The endpoint

```text
POST {panel path}/actions/record      route name: panel.{panelId}.actions.record
```

```json
{ "resource": "orders", "action": "approve", "record": 42 }
```

A nested resource also sends `parent`, which is resolved and authorized through the parent resource exactly as route middleware does it for that resource's own pages.

What `PanelActionController::record()` checks, in order:

1. The panel is the one resolved for this request, and the resource slug resolves inside **that panel's** registry, or 404.
2. `TableSchema::getRecordAction($name)` finds the action, or 404 `Unknown action.`
3. `Action::isExecutable()`, or 400 `This action cannot be executed.` — posting a link action lands here.
4. The key is a string or an int, or 422 `Invalid record key.` An array key would turn `find()` into a collection lookup and quietly change the meaning of the request.
5. `Resource::findRecord($key)` resolves it, or 404. This is the record lookup rather than the list query, because a restore legitimately addresses a record the list hides.
6. `Action::isAuthorizedFor($record)`, or 403.

Then `Action::execute($record)` runs and the response is a redirect back with the success flash.

A row action carrying a form goes to `GET {panel path}/actions/form` and `POST {panel path}/actions/form` with `scope: "record"` instead, and is checked the same way with the schema's validation in front of the handler. See [Action scopes](scopes.md).

## Panel-wide defaults

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Core\Panel;

Panel::make('admin')
    ->path('admin')
    ->configureActions(static function (Action $action): void {
        if ($action->getVariant() === ActionVariant::Destructive) {
            $action->requiresConfirmation();
        }
    });
```

`Action::make()` calls the current panel's configurator as the action is built, so anything the schema then sets still wins. It is read through the current panel rather than a static registry, so two panels can differ and nothing leaks between requests. Outside a panel — a unit test constructing an action directly — there is no configurator and the action is left alone.

## Notes

- **`visible()` and `authorize()` are also called with `null`.** The same action object is serialized without a record in other contexts, so a closure that dereferences `$record` unguarded will fatal. Every built-in begins with `$record !== null &&`.
- **A row action cannot see the selection or the filters.** The record endpoint sends neither; `tableState` travels only with the table and bulk scopes. An operation that needs the selection is a [bulk action](bulk-actions.md).
- **A column may carry both `url()` and `action()` — do not.** The renderer puts the action's button inside the link's anchor, and a cell that both navigates and does something is a coin toss.
- **Row actions work identically on a relation manager's table.** They are declared on the same `TableSchema`, resolved with `RelationTable::actionFor()`, and posted to `POST {panel path}/relations/action` instead. See [Relation actions](relation-actions.md).
- **Deletion has no page lifecycle hooks.** The action endpoint runs without a page instance, so `Action::before()` and `Action::after()` are the hooks — and they share the action's transaction, which page hooks would not.
- **`affectedCount()` is per action object, per request.** It is set by `execute()` and `executeBulk()`, so reading it before a run answers 0.

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Table actions](table-actions.md)
- [Bulk actions](bulk-actions.md)
- [Action forms](forms.md)
- [Action modals](modals.md)
- [Action authorization](authorization.md)
- [Transactions](transactions.md)
- [Built-in actions](built-in-actions.md)
- [Record actions](../tables/record-actions.md)
- [Editable columns](../tables/editable-columns.md)
