# Transactions

Every write a panel performs runs inside a database transaction unless something says otherwise. That is the default because a half-applied write is worse than a slow one. This page is where you find out what exactly is wrapped, who decides, and how to turn it off for the one action that must not hold a connection open.

## A minimal working example

An action that calls an external service, opting out of the transaction it would otherwise inherit:

```php
<?php

declare(strict_types=1);

use App\Billing\Gateway;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('refund')
    ->label('Refund')
    ->requiresConfirmation()
    ->successMessage('Refund requested.')
    // The handler waits on a network round trip. Holding a transaction
    // open across it would keep a connection and its locks for the length
    // of somebody else's outage.
    ->databaseTransaction(false)
    ->action(static function (Order $record): void {
        app(Gateway::class)->refund($record);

        $record->forceFill(['refunded_at' => now()])->save();
    });
```

Everything else in the panel keeps its transaction; only this action runs without one.

## Who decides

```php
use PandaPanel\Support\DatabaseTransaction;

DatabaseTransaction::run(?bool $override, Closure $callback): mixed
DatabaseTransaction::enabled(?bool $override): bool
```

`enabled()` is the whole rule, and it is three lines:

```php
return $override ?? panel()?->hasDatabaseTransactions() ?? true;
```

Three levels, most specific first:

| Level | Set with | Type | Meaning of `null` |
| --- | --- | --- | --- |
| The action or page | `Action::databaseTransaction()`, `ResourcePage::$hasDatabaseTransactions` | `?bool` | did not decide — ask the panel |
| The panel | `Panel::databaseTransactions()` | `bool` | — (always decided) |
| No panel at all | — | — | on |

`null` means "did not decide" rather than "off". That is what lets a page inherit the panel while still being able to override it in either direction. Outside a panel — a page controller called directly in a test, a queued job — there is nothing to ask and the answer is on, because a write that silently stopped being atomic outside the request cycle would be the worst possible default.

## The panel setting

```php
use PandaPanel\Core\Panel;

Panel::databaseTransactions(bool $databaseTransactions = true): self
Panel::hasDatabaseTransactions(): bool
```

```php
Panel::make('admin')
    ->path('admin')
    // Off for a panel whose writes reach something a transaction cannot
    // cover. Every page and every action then takes responsibility itself.
    ->databaseTransactions(false);
```

On by default. It covers resource create, resource update, and each action's hooks and handler.

## The action setting

```php
use PandaPanel\Actions\Action;

Action::databaseTransaction(bool $databaseTransaction = true): static
Action::hasDatabaseTransaction(): ?bool
```

It overrides the panel for this action alone, in both directions:

```php
use App\Warehouse\Warehouse;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

// Off inside a panel that has them on.
Action::make('sync')
    ->databaseTransaction(false)
    ->action(static fn (Model $record) => app(Warehouse::class)->push($record));

// On inside a panel that has them off.
Action::make('settle')
    ->databaseTransaction()
    ->action(static fn (Model $record) => $record->settle());
```

`hasDatabaseTransaction()` answers `null` for an action that never called it, which is the value `DatabaseTransaction::run()` reads as "inherit".

## The page setting

```php
use PandaPanel\Resources\Pages\ResourcePage;

protected static ?bool $hasDatabaseTransactions = null;

ResourcePage::hasDatabaseTransactions(): ?bool
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Pages;

use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Resources\Pages\CreateRecord;

final class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    // This create also calls the payment gateway, and a transaction held
    // across that call is worse than not having one.
    protected static ?bool $hasDatabaseTransactions = false;
}
```

`CreateRecord` and `EditRecord` are the two pages that read it. On create, the record, its relations, and the `afterCreate` / `afterSave` hooks are all inside the one transaction — related records need a key that did not exist before the insert, and a record that survived without the relations it was submitted with would be a worse outcome than a failed create. See [Resource pages](../resources/resource-pages.md) and [Lifecycle hooks](../resources/lifecycle-hooks.md).

## What is inside the transaction

For a record action, `Action::execute()` wraps three things together:

```php
public function execute(Model $record, array $data = []): void
{
    if ($this->handleUsing === null) {
        return;
    }

    $this->affected = max($this->affected, 1);

    DatabaseTransaction::run($this->databaseTransaction, function () use ($record, $data): void {
        if ($this->beforeUsing !== null) {
            ($this->beforeUsing)($record, $data);
        }

        ($this->handleUsing)($record, $data);

        if ($this->afterUsing !== null) {
            ($this->afterUsing)($record, $data);
        }
    });
}
```

So an `after()` hook that throws undoes the operation rather than leaving it half applied:

```php
use App\Events\OrderApproved;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;

Action::make('approve')
    ->before(static function (Model $record): void {
        // Throwing here aborts the action and nothing is written.
        abort_if($record->getAttribute('status') !== 'pending', 422, 'Not pending.');
    })
    ->action(static fn (Model $record) => $record->approve())
    ->after(static fn (Model $record) => event(new OrderApproved($record)));
```

`executeWithoutRecord()` — the table scope — wraps the handler the same way, on the same three-level rule.

## A bulk action is one transaction

`Action::executeBulk()` opens **one** transaction for the whole selection, on the same three-level rule as everything else:

```php
public function executeBulk(Collection $records, array $data = []): void
{
    foreach ($records as $record) {
        if (! $this->isAuthorizedForEach($record)) {
            throw new HttpException(403, /* ... */);
        }
    }

    $this->affected = $records->count();

    DatabaseTransaction::run($this->databaseTransaction, function () use ($records, $data): void {
        if ($this->handleBulkUsing !== null) {
            ($this->handleBulkUsing)($records, $data);

            return;
        }

        foreach ($records as $record) {
            $this->execute($record, $data);
        }
    });
}
```

So a `bulkAction()` closure that fails halfway leaves nothing behind, and a bulk action falling back to `action()` rolls the whole batch back rather than the record it was on. The inner per-record transaction becomes a savepoint, which is Laravel's behaviour and changes nothing about the outcome.

**This changed.** It used to be neither: a `bulkAction()` closure ran unwrapped, and the fallback opened one transaction per record — ten records were ten transactions, and a failure on the seventh left six committed. That surprised, and the surprise was expensive: a bulk operation the user was told had failed had changed six rows. Every built-in bulk action already opened its own `DB::transaction()` to avoid it, which is the clearest possible sign that it was the wrong default.

An action that genuinely wants partial application says so, with the same switch every other write has:

```php
Action::make('notify')
    ->databaseTransaction(false)
    ->bulkAction(static fn (Collection $records) => $records->each->notify());
```

That is the right shape for work where each record is independent and a failure on one should not undo the others — sending a message, calling an API, anything that was not going to roll back anyway.

**Authorization stays outside the transaction.** `executeBulk()` walks the whole selection through `isAuthorizedForEach()` before the first write, so a selection containing one forbidden record throws 403 and changes nothing. "All or nothing" has to be decided before the first write, not discovered halfway through — and there is nothing to roll back when nothing has been written.

## What the built-ins do

| Action | Transaction |
| --- | --- |
| `DeleteAction`, `RestoreAction`, `ForceDeleteAction`, `ReplicateAction`, and any `action()` handler | one per record, on the three-level rule |
| Any `bulkAction()` handler, and the per-record fallback | one for the whole selection, on the three-level rule |
| `DeleteBulkAction`, `RestoreBulkAction`, `ForceDeleteBulkAction` | an explicit `DB::transaction()` over the whole selection, whatever the panel says |
| `Actions\Relations\RestoreBulkAction`, `Actions\Relations\ForceDeleteBulkAction`, `Actions\Relations\DetachBulkAction` | the same, over the related records |
| `CreateAction::modal()`, `ImportAction`, `ExportAction` | `tableAction()` handlers, on the three-level rule |

The bulk deletes are unconditional on purpose. Each of them authorizes every record before writing any, and "all or nothing" is the guarantee they advertise, not a default they inherit from a panel that could turn it off. Now that `executeBulk()` wraps as well, their own `DB::transaction()` is a savepoint inside it — the same outcome, and kept because the guarantee is theirs to make rather than one they borrow.

## Other panel writes

Not every write is an action, and the rest follow the same rule from different starting points:

| Write | Where | Override |
| --- | --- | --- |
| Resource create | `CreateRecord::create()`, reached from `handle()` | the page's `$hasDatabaseTransactions` |
| Resource update | `EditRecord::save()`, reached from `handle()` | the page's `$hasDatabaseTransactions` |
| Editable cell | `PanelActionController::cell()` | none — `DatabaseTransaction::run(null, ...)`, so the panel decides |
| Relation form save | `PanelRelationController::save()` | none — the panel decides |
| Row reordering | `PanelActionController::reorder()` | none — always `DB::transaction()` |
| One imported row | `ImportRun` | none — always `DB::transaction()`, one per row |

Reordering is unconditional because a list that half-reordered would be worse than one that did not move: every record is authorized for editing first, then the whole arrangement is written together.

An import is the opposite decision, made deliberately. Each row is its own transaction, so one bad row undoes neither the good rows before it nor the related record it had just created. A partial import is the intended outcome — a thousand-row file with a bad date in row four hundred imports nine hundred and ninety-nine and writes the rest to a failure report. See [Import and export actions](import-export.md).

## Testing the setting

`DatabaseTransaction::enabled()` is a plain static, so a test can assert the resolution without performing a write:

```php
use PandaPanel\Actions\Action;
use PandaPanel\Support\DatabaseTransaction;

expect(DatabaseTransaction::enabled(null))->toBeTrue();     // no panel — on
expect(DatabaseTransaction::enabled(false))->toBeFalse();   // an explicit opt-out

$action = Action::make('refund')->databaseTransaction(false);

expect($action->hasDatabaseTransaction())->toBeFalse();
```

## Notes

- **`false` and `null` are not the same value.** `null` inherits; `false` refuses. An action that never called `databaseTransaction()` reports `null`, which is why the setter has a default of `true` rather than being a nullable pass-through.
- **Turning transactions off does not make writes safe to interleave.** It removes the guarantee; it adds nothing. An action that opts out and then performs two writes has to handle the case where the second fails.
- **A queued export or import runs outside the request.** There is no current panel in the job, so `panel()` is null and `DatabaseTransaction::enabled(null)` answers on. An action's own `databaseTransaction(false)` does not travel into a queued job, because the job runs the exporter or importer, not the action.
- **Nested transactions are Laravel's, not this framework's.** A built-in bulk action's explicit `DB::transaction()` inside a panel that also wraps would be a savepoint; the outcome is the same, and nothing here tries to detect it.
- **A `before()` hook is the place to refuse.** It runs inside the transaction, so aborting there leaves nothing behind. Validating after the write and throwing works too, but it costs a rollback.
- **Transactions do not cover files.** A `FileUpload` on an action's form stores its file in a separate request before the submit; a rolled-back action leaves that file on the disk. See [Action forms](forms.md).

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Row actions](row-actions.md)
- [Table actions](table-actions.md)
- [Bulk actions](bulk-actions.md)
- [Import and export actions](import-export.md)
- [Lifecycle hooks](../resources/lifecycle-hooks.md)
- [Resource pages](../resources/resource-pages.md)
- [Defining panels](../panels/defining-panels.md)
