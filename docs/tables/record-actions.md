# Record Actions

Record actions are the buttons at the end of a row: view, edit, delete, and whatever else a single record can be asked to do. They are declared on the table schema, resolved per record on the server, and executed through the panel's action endpoint — the button is never what authorizes the operation.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Tables;

use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class PostsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(PostResource::class),
                EditAction::make(PostResource::class),
                DeleteAction::make(PostResource::class),
            ]);
    }
}
```

Each row now carries the three buttons, minus any the policy refuses for that particular record.

## Declaring them

```php
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Enums\RecordActionsPosition;
use PandaPanel\Tables\TableSchema;

TableSchema::recordActions(array $actions): self             // array<array-key, Action>
TableSchema::recordActionsPosition(RecordActionsPosition $position): self
TableSchema::recordActionsLabel(string $label): self
TableSchema::frozenActions(bool $frozen = true): self
TableSchema::getRecordActions(): array                       // list<Action>
TableSchema::getRecordAction(string $name): ?Action
```

`recordActions()` refuses two sets outright, at the line that declared them rather than at render time:

- Two actions with the same name — the endpoint resolves by name, so it would always run the first.
- An action that does nothing: no `url()`, no `action()`, no `bulkAction()`, no `tableAction()`, no `schema()`, no `form()`, no `modal()`. `PanelSchemaException::inertAction()` says which one and what to add.

## Built-in record actions

Each is a static factory returning a configured `PandaPanel\Actions\Action`, so anything below can still be chained onto it.

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

`ViewAction` and `EditAction` are also hidden when the resource declares no `view` or `edit` page, so a link to a route that does not exist is never drawn. `RestoreAction` and `ForceDeleteAction` are hidden for a record that is not trashed — a row shows either restore or delete, never both.

`DeleteAction` and `ForceDeleteAction` confirm by default. `ReplicateAction` confirms too, and takes the columns a copy must not carry over:

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\ReplicateAction;

ReplicateAction::make(
    PostResource::class,
    except: ['slug', 'published_at'],
    using: static function (Model $copy, Model $original): void {
        $copy->forceFill([
            'title' => $original->getAttribute('title').' (copy)',
            'slug' => Str::uuid()->toString(),
        ]);
    },
);
```

Eloquent's own `replicate()` already drops the key and the timestamps; `except` is for the columns *this* model must not duplicate — a unique slug, an invoice number, an API token.

## Writing your own

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

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
    ->visible(static fn (?Model $record): bool => $record?->getAttribute('status') === 'pending')
    ->authorize(static fn (?Model $record): bool => $record !== null && auth()->user()?->can('approve', $record))
    ->action(static function (Order $record): void {
        $record->approve();
    });
```

The methods a record action uses, all fluent and all returning `static`:

| Method | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $name): static` | — |
| `label` | `label(string $label): static` | `Str::headline($name)` |
| `icon` | `icon(string $icon): static` | none |
| `variant` | `variant(ActionVariant $variant): static` | `ActionVariant::Ghost` |
| `requiresConfirmation` | `requiresConfirmation(bool $requires = true, ?string $heading = null, ?string $description = null, ?string $button = null): static` | off |
| `successMessage` | `successMessage(string $message): static` | `"{Label} completed."` |
| `successMessageUsing` | `successMessageUsing(Closure(int): string $callback): static` | none |
| `visible` | `visible(Closure(?Model): bool $callback): static` | always visible |
| `authorize` | `authorize(Closure(?Model): bool $callback): static` | always allowed |
| `url` | `url(Closure(Model): string $callback): static` | none — makes it a link |
| `action` | `action(Closure(Model, array): void $callback): static` | none — makes it a callback |
| `before` | `before(Closure(Model, array): void $callback): static` | none |
| `after` | `after(Closure(Model, array): void $callback): static` | none |
| `schema` | `schema(Closure(?Model): FormSchema $callback): static` | none — makes it a form |
| `form` | `form(Closure(?Model): string $callback): static` | none — an external form URL |
| `modal` | `modal(Closure(Modal): void $callback): static` | none |
| `modalWidth` | `modalWidth(ModalWidth $width): static` | the modal default |
| `slideOver` | `slideOver(bool $slideOver = true): static` | off |
| `modalHeading` | `modalHeading(string $heading): static` | the action label |
| `modalDescription` | `modalDescription(string $description): static` | none |
| `modalSubmitLabel` | `modalSubmitLabel(string $label): static` | the action label |
| `modalContent` | `modalContent(string $component, array $config = []): static` | none |
| `registerModalActions` | `registerModalActions(array $actions): static` | `[]` |
| `databaseTransaction` | `databaseTransaction(bool $enabled = true): static` | `null`, inheriting the panel |

`ActionVariant` is `Default`, `Secondary`, `Outline`, `Ghost`, `Destructive`. `ActionType` — reported by `type()`, never set directly — is `Link` when `url()` was given, `Form` when `schema()` or `form()` was, and `Callback` otherwise.

`before()` and `after()` run inside the same transaction as the handler, so an `after` hook that throws undoes the operation rather than leaving it half applied. They live on the action rather than on a page because the action endpoint executes without a page instance.

An action name may contain letters, numbers, dashes, dots and underscores and nothing else. It travels to the endpoint as an identifier, and a name that cannot be matched there renders as a button that fails only when pressed, so `PanelSchemaException::unusableActionName()` refuses it at construction.

### Actions with a form

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
        Textarea::make('reason')->label('Reason')->required(),
    ]))
    ->action(static function (Order $record, array $data): void {
        $record->reject($data['reason']);
    });
```

The schema is fetched when the dialog opens rather than serialized into every row: a table of twenty records would otherwise ship twenty copies of the same form to open at most one. The submitted data is validated and dehydrated by that schema before the handler sees it, so a key the form never declared is discarded. A handler that only takes `Model $record` simply never sees the second argument.

## Column actions

A whole cell can run an action:

```php
use App\Models\Order;
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('reference')->action(
    Action::make('approve')->action(static fn (Order $record) => $record->approve()),
);
```

```php
use PandaPanel\Actions\Action;
use PandaPanel\Tables\Columns\Column;

Column::action(Action $action): static
Column::getAction(): ?Action
```

The action is resolved per record, so a cell the user may not act on renders as an ordinary value rather than as a button that answers 403. `TableSchema::getRecordAction()` searches the row actions *and* every column's action, so the endpoint finds it without a second lookup — a column action is a record action in every sense that matters.

A column may also carry `url()`. Declare one or the other: the renderer puts the action's button inside the link's anchor, and a cell that both navigates and does something is a coin toss.

## Where the buttons sit

```php
use PandaPanel\Tables\Enums\RecordActionsPosition;

$table
    ->recordActionsPosition(RecordActionsPosition::AfterColumns)   // the default
    ->recordActionsLabel('Manage')
    ->frozenActions();
```

| Case | Value | Effect |
| --- | --- | --- |
| `RecordActionsPosition::AfterColumns` | `after_columns` | a column of its own after the data columns |
| `RecordActionsPosition::BeforeColumns` | `before_columns` | a column of its own before them |
| `RecordActionsPosition::AfterCells` | `after_cells` | no column at all; the buttons are appended inside the last visible cell |

`AfterCells` is for a table narrow enough that a column of its own would be most of it. `recordActionsLabel()` names the actions column in the header; it defaults to "Actions". `frozenActions()` keeps that column in view while the table scrolls sideways — off by default, because pinning costs horizontal room. See [Pinned columns](pinned-columns.md).

## What each row carries

`TableSchema::toRow()` resolves the actions for the record it is serializing:

```php
[
    'key' => 42,
    'group' => null,
    'cells' => ['title' => 'Hello'],
    'cellMeta' => ['title' => ['action' => ['name' => 'approve', /* ... */]]],
    'actions' => [
        ['name' => 'edit', 'label' => 'Edit', 'icon' => 'pencil', 'variant' => 'ghost',
         'type' => 'link', 'url' => '/admin/posts/42/edit', 'formUrl' => null,
         'hasForm' => false, 'modal' => null, 'modalActions' => [], 'confirmation' => null],
    ],
]
```

`Action::toArray(?Model $record)` returns `null` when the action is hidden or unauthorized for that record, and the row drops it. Nothing executable ever crosses: a callback action carries its name, not its handler.

## The endpoint

The frontend posts a record action to one endpoint per panel:

```text
POST {panel path}/actions/record       route name: panel.{panelId}.actions.record
```

```json
{ "resource": "posts", "action": "approve", "record": 42 }
```

A nested resource also sends `parent`, which is resolved and bound the way route middleware does it for the resource's own pages.

What the controller checks, in order:

1. The resource slug resolves inside the panel resolved for this request — a resource from another panel does not exist here.
2. `TableSchema::getRecordAction($name)` finds the action, or 404. An action the resource never declared cannot be addressed however the request spells it.
3. `Action::isExecutable()` — a link action has no handler, so posting one is 400.
4. The key is a string or an int, or 422.
5. `Resource::findRecord($key)` resolves it, or 404. This is the record lookup rather than the list query, because a restore legitimately addresses a record the list hides.
6. `Action::isAuthorizedFor($record)`, or 403.

Then the action runs and the response is a redirect back with a `success` flash carrying `Action::getSuccessMessage()`.

An action with a form goes to `POST {panel path}/actions/submit` with `scope: "record"` instead, and is checked the same way with the schema's validation in front of the handler.

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

The configurator runs as each action is built, so anything the schema then sets still wins. It is read through the current panel rather than a static registry, so two panels can differ and nothing leaks between requests.

## Notes

- **Hiding a button is never what protects a record.** `visible()` and `authorize()` decide whether it is drawn; the endpoint asks `isAuthorizedFor()` again before running anything.
- **`visible()` and `authorize()` receive `?Model`.** They are also called with `null` when the same action object is serialized without a record, so a closure must handle both — the built-in actions all begin with `$record !== null &&`.
- **`RestoreAction` needs two other things to be reachable.** The resource must declare soft deletes, or a trashed record cannot be resolved, and the table needs a `PandaPanel\Tables\Filters\TrashedFilter`, or no trashed row ever appears for the action to sit on. See [Soft deletes](../resources/soft-deletes.md).
- **Record actions run on a relation manager's table too.** They are declared on the same schema and posted to the relation action endpoint instead. See [Relation tables](../relations/relation-tables.md).
- **Success messages are flashed, not returned.** The handler returns nothing; the endpoint redirects back and the panel renders the flash as a toast. See [Toast notifications](../notifications/toast.md).
- **A record action cannot see the selection.** That is a [bulk action](bulk-actions.md), and it is authorized differently.

## See also

- [Bulk actions](bulk-actions.md)
- [Toolbar actions](toolbar-actions.md)
- [Editable columns](editable-columns.md)
- [Pinned columns](pinned-columns.md)
- [Actions overview](../actions/overview.md)
- [Row actions](../actions/row-actions.md)
- [Action modals](../actions/modals.md)
- [Action authorization](../actions/authorization.md)
- [Tables overview](overview.md)
