# Custom Actions

Everything the panel ships is built from the same `PandaPanel\Actions\Action` you use for your own operations — there is no separate base class, no interface to implement, and no registration step. You reach for a custom action whenever a record, a selection, or a table needs something done that is not create, read, update, or delete.

This page is the recipe: how to build one, where to put the handler, how to package it as a factory when it is used in more than one place, and what the framework refuses to accept.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Models\Order;
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
            ->columns([TextColumn::make('reference')->searchable()])
            ->recordActions([
                Action::make('sendReceipt')
                    ->label('Send receipt')
                    ->icon('mail')
                    ->variant(ActionVariant::Outline)
                    ->requiresConfirmation(
                        heading: 'Send the receipt again?',
                        description: 'A copy goes to the address on the order.',
                        button: 'Send it',
                    )
                    ->successMessage('Receipt sent.')
                    ->authorize(static fn (?Model $record): bool => $record !== null
                        && auth()->user()?->can('update', $record) === true)
                    ->action(static function (Order $record): void {
                        $record->sendReceipt();
                    }),
            ]);
    }
}
```

## Naming

```php
Action::make(string $name): static
Action::getName(): string
Action::getLabel(): string
```

The name is an identifier, not copy. It travels to the endpoint and is matched there against the schema, so it may contain letters, numbers, dashes, dots, and underscores and nothing else. Anything else throws `PandaPanel\Exceptions\PanelSchemaException::unusableActionName()` at construction — a name the endpoint can be asked for but never matches would render as a button that fails only when pressed.

```php
Action::make('send-receipt');     // fine
Action::make('send.receipt');     // fine
Action::make('send receipt');     // throws
Action::make('');                 // throws — PanelSchemaException::emptyName()
```

Without `label()`, the label is `Str::headline($name)`, so `sendReceipt` reads as "Send Receipt".

Two actions with one name inside one set throw `PanelSchemaException::duplicateActions()`: the endpoint resolves by name, so it would always run the first and never the second.

## Choosing a handler

The handler you give decides which scope the action can live in.

| Handler | Signature | Scope | Runs |
| --- | --- | --- | --- |
| `action()` | `fn (Model $record, array $data): void` | record, infolist, bulk fallback | `execute()` |
| `bulkAction()` | `fn (Collection $records, array $data): void` | bulk | `executeBulk()` |
| `tableAction()` | `fn (array $data): void` | table (header, toolbar, empty state) | `executeWithoutRecord()` |
| `url()` | `fn (Model $record): string` | anywhere | never — the browser navigates |

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

// One record.
Action::make('approve')->action(static fn (Order $record) => $record->approve());

// A selection.
Action::make('approve')->bulkAction(static function (Collection $records): void {
    $records->each->approve();
});

// The table itself.
Action::make('purgeAbandoned')->tableAction(static function (): void {
    Order::query()->where('status', 'abandoned')->delete();
});

// A link.
Action::make('invoice')->url(static fn (Model $record): string => route('invoices.show', $record));
```

The endpoint checks the handler before running: posting a link action to `actions/record` is a 400, and a table action with no `tableAction()` is a 400 on `actions/table`.

`$data` is what the action's own form submitted, already validated and dehydrated. A closure declared with fewer parameters never sees it, which is why adding a form to an existing action changes nothing about how it ran before.

## Hooks

```php
Action::before(Closure $callback): static   // fn (Model $record, array $data): void
Action::after(Closure $callback): static    // fn (Model $record, array $data): void
```

```php
use App\Events\OrderApproved;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->before(static fn (Model $record) => Log::info('approving', ['id' => $record->getKey()]))
    ->action(static fn (Model $record) => $record->approve())
    ->after(static fn (Model $record) => event(new OrderApproved($record)));
```

Both run inside the same transaction as the handler, so an `after` hook that throws undoes the operation rather than leaving it half applied. They fire only on `execute()` — a bulk handler and a table handler do not call them, because there is no single record to hand over.

They live on the action rather than on a page because the action endpoint executes without a page instance: a hook declared on the page would never be called.

## Feedback

```php
Action::successMessage(string $message): static
Action::successMessageUsing(Closure $callback): static   // fn (int $count): string
Action::getSuccessMessage(): string
Action::affectedCount(): int
```

```php
$action->successMessage('Receipt sent.');

$action->successMessageUsing(static fn (int $count): string => $count === 1
    ? '1 receipt sent.'
    : "{$count} receipts sent.");
```

The endpoint redirects back with the message in the `success` flash, which the panel renders as a toast. Without either method the message is `"{Label} completed."`.

There is no return value from a handler and no way to send a different message per outcome. When an operation needs to report more than "it worked", send a notification from inside the handler — see [Notifications](../notifications/toast.md).

## Failing

Throwing aborts the action and, inside a transaction, rolls it back. An `HttpException` becomes its own status; anything else is a 500.

```php
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

Action::make('ship')->action(static function (Model $record): void {
    if ($record->getAttribute('status') !== 'paid') {
        throw new HttpException(422, 'Only a paid order can be shipped.');
    }

    $record->ship();
});
```

Validation is better expressed as a form on the action, where the message lands on the field that caused it. See [Action forms](forms.md).

## Transactions

```php
Action::databaseTransaction(bool $databaseTransaction = true): static
Action::hasDatabaseTransaction(): ?bool
```

`null` — the default — inherits the panel's setting, which itself defaults to on. `PandaPanel\Support\DatabaseTransaction` resolves the three levels most specific first.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use PandaPanel\Actions\Action;

// Calling an external service: do not hold a connection open across a round trip.
Action::make('sync')->databaseTransaction(false)->action(static function (Model $record): void {
    Http::post('https://example.test/sync', $record->only(['id', 'reference']));
});
```

See [Transactions](transactions.md).

## Packaging one as a factory

An action used by more than one table is worth a class of its own. The built-ins are exactly this shape: a `final class` with a static method returning a configured `Action`. There is nothing to extend.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Actions;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;

final class SendReceiptAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('sendReceipt')
            ->label('Send receipt')
            ->icon('mail')
            ->variant(ActionVariant::Outline)
            ->requiresConfirmation(
                heading: 'Send the receipt again?',
                description: 'A copy goes to the address on the order.',
                button: 'Send it',
            )
            ->successMessage('Receipt sent.')
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $resource::canEdit($record))
            ->action(static function (Order $record): void {
                $record->sendReceipt();
            });
    }
}
```

```php
use App\Panels\Admin\Actions\SendReceiptAction;
use App\Panels\Admin\Resources\Orders\OrderResource;

$table->recordActions([
    SendReceiptAction::make(OrderResource::class),
]);
```

Static, and returning an `Action` rather than extending it, for a practical reason: the caller can keep chaining. `SendReceiptAction::make(OrderResource::class)->icon('send')` is an ordinary action with a different icon.

## Where a custom action can go

```php
use App\Models\Order;
use App\Panels\Admin\Actions\SendReceiptAction;
use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Collection;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

$record = SendReceiptAction::make(OrderResource::class);
$bulk = Action::make('sendReceipts')
    ->label('Send receipts')
    ->bulkAction(static fn (Collection $records) => $records->each->sendReceipt());
$whole = Action::make('purgeAbandoned')
    ->label('Purge abandoned')
    ->tableAction(static fn () => Order::query()->where('status', 'abandoned')->delete());

$table = TableSchema::make()
    ->columns([TextColumn::make('reference')->action($record)])   // a whole cell runs it
    ->headerActions([$whole])          // acts on the table
    ->toolbarActions([$whole])         // acts on the view of it
    ->emptyStateActions([$whole])      // when there is nothing to show
    ->recordActions([$record])         // one per row
    ->bulkActions([$bulk]);            // on a selection

$infolist = InfolistSchema::make()->actions([$record]);   // on a view page
```

And inside another action's dialog:

```php
Action::make('review')
    ->modalContent('Panels/Admin/Modals/ReceiptPreview')
    ->registerModalActions([$record]);
```

Each of those is a separate whitelist, and the endpoint resolves against the one named in the request. See [Action scopes](scopes.md).

## What is refused

`TableSchema::recordActions()`, `bulkActions()`, `headerActions()`, `toolbarActions()`, and `emptyStateActions()` all run the same two checks at the line that declared them:

- **Duplicate names** — `PanelSchemaException::duplicateActions($set, $names)`.
- **An inert action** — `PanelSchemaException::inertAction($name)`, thrown when `url()`, `action()`, `bulkAction()`, `tableAction()`, `schema()`, `form()`, a modal, and registered modal actions are *all* absent. The message names the action and lists what to add.

```php
use PandaPanel\Actions\Action;

$table->recordActions([
    Action::make('approve')->label('Approve'),   // throws: does nothing at all
]);
```

Refusing at definition time is deliberate: an action that leads nowhere is a mistake once, rather than a button drawn for every record that disappoints on click.

## Notes

- **Actions are rebuilt per request.** The schema is re-created on every render and on every endpoint hit, so a closure sees the current user, tenant, and locale. Do not cache an `Action` between requests.
- **The handler runs without a page.** There is no `$this`, no page instance, and no form state beyond what the action's own schema submitted.
- **Type-hint the record however you like.** The endpoint passes an Eloquent model; hinting your own model class is a runtime assertion that the record is what you expected.
- **`visible()` and `authorize()` receive `?Model`.** Both are also called with `null` for a table or bulk action. Guard the null.
- **A custom action on a relation manager posts to the relation endpoint**, not the resource one, and is resolved through `RelationTable::actionFor()`. See [Relation actions](relation-actions.md).
- **`Str::headline()` decides the default label.** A name in camelCase reads well; a name in snake_case reads well too. A name that is an abbreviation usually needs `label()`.

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Row actions](row-actions.md), [Table actions](table-actions.md), [Bulk actions](bulk-actions.md)
- [Action forms](forms.md) and [Action modals](modals.md)
- [Action authorization](authorization.md)
- [Transactions](transactions.md)
- [Built-in actions](built-in-actions.md)
- [Record actions on a table](../tables/record-actions.md)
- [Toast notifications](../notifications/toast.md)
