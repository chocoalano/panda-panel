# Bulk Actions

A bulk action is an `Action` that runs over the records a user selected. You reach for one when the same operation has to happen to many rows and doing it one row at a time is the wrong shape — approving a queue, marking a batch verified, exporting a selection.

This page is about the action object: the bulk handler, the second authorization question a selection introduces, and how `executeBulk()` behaves. For declaring them on a table and what that does to row selection, see [Bulk actions on a table](../tables/bulk-actions.md).

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Panels\Admin\Resources\Orders\OrderResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('reference')->searchable()])
            ->bulkActions([
                DeleteBulkAction::make(OrderResource::class),

                Action::make('approve')
                    ->label('Approve selected')
                    ->icon('check')
                    ->variant(ActionVariant::Outline)
                    ->authorize(static fn (?Model $record): bool => auth()->user()?->can('orders.approve') === true)
                    ->authorizeEachUsing(static fn (Model $record): bool => OrderResource::canEdit($record))
                    ->successMessageUsing(static fn (int $count): string => "{$count} orders approved.")
                    ->bulkAction(static function (Collection $records): void {
                        $records->each->approve();
                    }),
            ]);
    }
}
```

Declaring any bulk action turns row selection on, so the table now has checkboxes and a bar that appears with the selection.

## The bulk handler

```php
use Illuminate\Database\Eloquent\Collection;

Action::bulkAction(Closure $callback): static   // fn (Collection $records, array $data): void
Action::isBulkExecutable(): bool
Action::executeBulk(Collection $records, array $data = []): void
```

`$records` is an `Illuminate\Database\Eloquent\Collection` already loaded through the resource's own lookup. `$data` is what the action's form submitted, if it declared one; a handler taking one argument never sees it.

An action with only `action()` and no `bulkAction()` still works in a bulk set. `executeBulk()` falls back to calling `execute()` once per record:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

// Runs per record, each inside its own transaction.
Action::make('touch')->action(static fn (Model $record) => $record->touch());
```

That fallback is why `TableSchema::getBulkAction()` accepts either handler, and why the endpoint's check is `isBulkExecutable() || isExecutable()`.

## Two authorization questions

A selection introduces a question a row action does not have, so there are two closures and both are asked.

| Method | Signature | Asked | Refusal |
| --- | --- | --- | --- |
| `authorize()` | `authorize(Closure $callback): static` | once, with `null` | 403 before anything is loaded |
| `authorizeEachUsing()` | `authorizeEachUsing(Closure $callback): static` | for every selected record | 403 before anything is written |

```php
use Illuminate\Database\Eloquent\Model;

$action
    ->authorize(static fn (?Model $record): bool => auth()->user()?->can('orders.approve') === true)
    ->authorizeEachUsing(static fn (Model $record): bool => $record->getAttribute('status') === 'pending');
```

`authorize()` answers for the *action* — whether the button appears at all, and whether the endpoint will proceed. On a bulk action it is called with `null`, because the answer has to exist before anything is selected.

`authorizeEachUsing()` answers for each record the action is about to touch. `executeBulk()` walks the whole collection and calls `isAuthorizedForEach()` on every record **before** the handler runs, whatever the handler is. A refusal throws:

```php
Symfony\Component\HttpKernel\Exception\HttpException(403, 'You may not approve every selected record.')
```

Built from `Str::lower($action->getLabel())`, so the message names the operation. "May run this" is not "may run this on these", and all-or-nothing has to be decided before the first write rather than discovered halfway through.

## Messages that know the count

```php
Action::successMessageUsing(Closure $callback): static   // fn (int $count): string
Action::affectedCount(): int
```

```php
$action->successMessageUsing(static fn (int $count): string => $count === 1
    ? '1 order approved.'
    : "{$count} orders approved.");
```

`executeBulk()` sets the affected count to `$records->count()` before running, so `getSuccessMessage()` can report it. Without `successMessageUsing()`, a plain `successMessage()` is used; without either, the message is `"{Label} completed."`.

## Transactions

`executeBulk()` does **not** open a transaction of its own. Two cases follow from that:

- With a `bulkAction()` handler, the wrapping is yours. Open one explicitly when the batch must succeed or fail together.
- Without one, the per-record fallback goes through `execute()`, which wraps each record separately — so a failure on the tenth record leaves the first nine written.

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

$action->bulkAction(static function (Collection $records): void {
    DB::transaction(static function () use ($records): void {
        $records->each->approve();
    });
});
```

Every built-in bulk action does exactly this. `->databaseTransaction(false)` on an action that calls an external service keeps a connection from being held open across a network round trip. See [Transactions](transactions.md).

## The built-in bulk actions

```php
use App\Panels\Admin\Resources\Orders\Exports\OrderExporter;
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ForceDeleteBulkAction;
use PandaPanel\Actions\RestoreBulkAction;

$table->bulkActions([
    DeleteBulkAction::make(OrderResource::class),
    RestoreBulkAction::make(OrderResource::class),
    ForceDeleteBulkAction::make(OrderResource::class),
    ExportAction::bulk(OrderExporter::class, OrderResource::class),
]);
```

| Factory | Name | Collective ability | Per-record ability |
| --- | --- | --- | --- |
| `DeleteBulkAction::make(string $resource)` | `delete` | `canDeleteAny()` | `canDelete()` |
| `RestoreBulkAction::make(string $resource)` | `restore` | `canRestoreAny()` | `canRestore()` |
| `ForceDeleteBulkAction::make(string $resource)` | `forceDelete` | `canForceDeleteAny()` | `canForceDelete()` |
| `ExportAction::bulk(string $exporter, string $resource)` | `export` | `canViewAny()` | — |

The three destructive ones re-check every record inside the handler and throw a 403 before writing, then run in one explicit `DB::transaction()`. `RestoreBulkAction` leaves an already-live record alone rather than refusing it — the user asked for those rows to be restored, and the ones already live are in the state that was asked for.

A relation manager has its own set: `DetachBulkAction`, `RestoreBulkAction`, and `ForceDeleteBulkAction` under `PandaPanel\Actions\Relations`. See [Relation actions](relation-actions.md).

## Bulk actions with a form

An action that declares `schema()` opens a dialog, fetches the form when it opens, and submits back to the action-form endpoint with `scope: "bulk"`.

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

Action::make('reject')
    ->label('Reject selected')
    ->modalHeading('Reject these orders')
    ->modalSubmitLabel('Reject')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
    ]))
    ->bulkAction(static function (Collection $records, array $data): void {
        $records->each(static fn (Model $record) => $record->reject($data['reason']));
    });
```

The schema closure receives `null` in this scope — there is no one record a bulk dialog is about. `ExportAction::bulk()` is built exactly this way; its dialog asks which columns and which format.

`PanelActionFormController::submit()` resolves the selection from the `records` key in the request body, checks the count against what the resource lookup returned, and then calls `executeBulk()` with the validated data. See [Action forms](forms.md).

## The endpoint

```text
POST {panel}/actions/bulk        route name: panel.{panelId}.actions.bulk
```

```json
{ "resource": "orders", "action": "approve", "records": [1, 2, 3] }
```

| Key | Required | Meaning |
| --- | --- | --- |
| `resource` | yes | resource slug, resolved against this panel's registry |
| `action` | yes | the bulk action's name |
| `records` | yes | array of keys, 1 to 500 |
| `parent` | for a nested resource | the parent record's key |
| `tableState` | no | the query string the list was showing, for actions that use it |

The checks, in order:

| Failure | Status |
| --- | --- |
| unknown resource slug | 404 |
| the action is not in `bulkActions()` | 404 |
| neither `bulkAction()` nor `action()` was given | 400 |
| `authorize()` refuses with `null` | 403 |
| no scalar keys survive filtering | 422 |
| any key outside `Resource::findRecords()` | 404 |
| `authorizeEachUsing()` refuses any record | 403 |

Keys are de-duplicated and the resulting count is compared against the records the lookup returned, so a key outside the resource scope is a visible 404 rather than a partial run.

## Testing

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

it('deletes every selected record', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [$first->id, $second->id],
    ])->assertRedirect();

    expect(User::query()->count())->toBe(0);
});

it('deletes nothing when one selected record is forbidden', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'users',
        'action' => 'delete',
        'records' => [$other->id, $this->admin->id],
    ])->assertForbidden();

    expect(User::query()->count())->toBe(2);
});
```

An action can also be exercised without HTTP:

```php
$action = Action::make('approve')
    ->authorizeEachUsing(static fn (Model $record): bool => $record->name !== 'Two')
    ->bulkAction(static fn (Collection $records) => $records->each->approve());

expect(fn () => $action->executeBulk($records))->toThrow(HttpException::class);
```

## Gotchas

- **500 keys is the ceiling.** The endpoint validates `max:500`. "Select everything matching these filters" over ten thousand rows is a table action that queues a job, not a bulk action.
- **The selection is keys, not a query.** Select-all selects the page. To act on a whole filtered result, use a table action and read the table state.
- **`authorize()` receives `null` here.** A closure that dereferences `$record` fails on the first render.
- **A custom bulk action with no `authorizeEachUsing()` has only the collective check.** The built-ins do their own per-record check inside the handler; a custom one has to say so.
- **`executeBulk()` opens no transaction.** See above — this is the difference between "all or nothing" and "as far as it got".
- **The success message is flashed once**, after the whole run. Per-record feedback is not this shape.
- **A record in the selection the action does not apply to is the handler's problem.** `RestoreBulkAction` filters nothing and restores whatever it was given; a custom action that must skip rows should filter inside the handler rather than refuse in `authorizeEachUsing()`, which aborts the whole batch.

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Action authorization](authorization.md)
- [Action forms](forms.md)
- [Transactions](transactions.md)
- [Built-in actions](built-in-actions.md) and [Restore and force delete](restore-force-delete.md)
- [Import and export actions](import-export.md)
- [Relation actions](relation-actions.md)
- [Bulk actions on a table](../tables/bulk-actions.md)
- [Record actions on a table](../tables/record-actions.md)
