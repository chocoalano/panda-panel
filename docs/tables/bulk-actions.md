# Bulk Actions

A bulk action is an operation over the rows a user selected. You reach for one when the same thing has to happen to many records and doing it one row at a time is the wrong shape — deleting a batch, approving a queue, exporting a selection.

Declaring bulk actions is also what turns row selection on: a bulk action with no way to select would be useless.

## A minimal example

```php
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

public static function table(TableSchema $table): TableSchema
{
    return $table
        ->columns([TextColumn::make('reference')->searchable()])
        ->bulkActions([
            DeleteBulkAction::make(OrderResource::class),
        ]);
}
```

The table now has a checkbox column, a select-all in the header, and a bar that appears with the selection.

## Selection

| Method | Signature | Default |
| --- | --- | --- |
| `bulkActions()` | `bulkActions(array $actions): self` | `[]`, sets `selectable(true)` when non-empty |
| `selectable()` | `selectable(bool $selectable = true): self` | `false` |
| `isSelectable()` | `isSelectable(): bool` | — |
| `getBulkActions()` | `getBulkActions(): list<Action>` | — |
| `getBulkAction()` | `getBulkAction(string $name): ?Action` | — |

`selectable()` on its own gives a table checkboxes with no bulk bar, for a frontend that does something else with a selection. In the ordinary case you never call it.

Declaring two bulk actions with one name throws `PanelSchemaException::duplicateActions('bulk actions', …)`; declaring one with no handler at all throws `PanelSchemaException::inertAction()`.

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

| Factory | Name | Authorizes | Confirms |
| --- | --- | --- | --- |
| `DeleteBulkAction::make(string $resource): Action` | `delete` | `canDeleteAny()` then `canDelete()` per record | yes |
| `RestoreBulkAction::make(string $resource): Action` | `restore` | `canRestoreAny()` then `canRestore()` per record | no |
| `ForceDeleteBulkAction::make(string $resource): Action` | `forceDelete` | `canForceDeleteAny()` then `canForceDelete()` per record | yes |
| `ExportAction::bulk(string $exporter, string $resource): Action` | `export` | `canViewAny()` | opens a form |

All three destructive ones follow the same shape: the collective policy answers before there is a record to ask about, then every record is authorized individually, and only then does anything get written — inside one explicit `DB::transaction()` whatever the panel's transaction setting is. A selection containing one forbidden record changes nothing rather than changing the permitted ones and failing halfway.

`RestoreBulkAction` leaves an already-live record alone rather than refusing it. The user selected rows and asked for them to be restored; the ones already live are in the state that was asked for.

## A custom bulk action

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

Action::make('verify')
    ->label('Mark as verified')
    ->icon('check')
    ->variant(ActionVariant::Outline)
    ->requiresConfirmation(
        heading: 'Verify the selected accounts?',
        description: 'Each account will be marked as verified.',
        button: 'Verify',
    )
    ->authorize(static fn (?Model $record): bool => auth()->user()?->is_admin === true)
    ->authorizeEachUsing(static fn (Model $record): bool => $record->email_verified_at === null)
    ->successMessageUsing(static fn (int $count): string => $count === 1
        ? '1 account verified.'
        : "{$count} accounts verified.")
    ->bulkAction(static function (Collection $records): void {
        $records->each(static fn (Model $record) => $record->forceFill([
            'email_verified_at' => now(),
        ])->save());
    });
```

| Method | Signature | Notes |
| --- | --- | --- |
| `bulkAction()` | `bulkAction(Closure $callback): static` | `fn (Collection $records, array $data): void` |
| `authorize()` | `authorize(Closure $callback): static` | `fn (?Model $record): bool`, asked with `null` here |
| `authorizeEachUsing()` | `authorizeEachUsing(Closure $callback): static` | `fn (Model $record): bool`, asked for every selected record |
| `successMessageUsing()` | `successMessageUsing(Closure $callback): static` | `fn (int $count): string` |
| `isBulkExecutable()` | `isBulkExecutable(): bool` | whether a bulk handler was given |
| `affectedCount()` | `affectedCount(): int` | how many records the last run touched |

An action with only `action()` and no `bulkAction()` still works in a bulk set: `executeBulk()` falls back to running `execute()` once per record, inside each record's own transaction.

## Two authorization questions

They are different questions and both are asked.

```php
->authorize(static fn (?Model $record): bool => $user->can('approve orders'))
->authorizeEachUsing(static fn (Model $record): bool => $record->isApprovable())
```

- **`authorize()`** answers for the *action*. On a bulk action it is called with `null`, because the answer has to exist before anything is selected — that is what decides whether the button appears at all, and the endpoint asks it again before running.
- **`authorizeEachUsing()`** answers for each record the action is about to touch. If it is absent, the per-record check falls back to `authorize($record)`. `executeBulk()` walks the whole collection and checks every one **before** the handler runs, whatever that handler does. A refusal throws a 403 and nothing is written.

"May run this" is not "may run this on these", and all-or-nothing has to be decided before the first write rather than discovered halfway through.

## Messages that know the count

```php
$action->successMessageUsing(static fn (int $count): string => "{$count} approved.");
```

`executeBulk()` sets `affected` to the size of the selection before running, so `getSuccessMessage()` can report it. Without `successMessageUsing()`, a plain `successMessage()` is used, and without either the message is `"{label} completed."`.

## Bulk actions with a form

An action that declares a `schema()` opens a dialog, fetches the form from the panel's action-form endpoint when it opens, and submits back to it. `ExportAction::bulk()` is built this way — its dialog asks which columns and which format.

```php
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

Action::make('reject')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('reason')->required()->maxLength(500),
    ]))
    ->bulkAction(static function (Collection $records, array $data): void {
        $records->each(static fn (Model $r) => $r->reject($data['reason']));
    });
```

The schema is fetched rather than serialized into the page, because a table of twenty records would otherwise ship twenty copies of a form to open at most one. `$data` is validated and dehydrated by the action's own schema, so the handler never sees a key the schema did not declare.

## The endpoint

```
POST {panel}/actions/bulk
```

Route name `panel.{panel_id}.actions.bulk`. The payload:

| Key | Required | Meaning |
| --- | --- | --- |
| `resource` | yes | resource slug, resolved against this panel's registry |
| `action` | yes | the bulk action's name |
| `records` | yes | array of keys, 1 to 500 |
| `parent` | for a nested resource | the parent record's key |

The checks, in order:

| Failure | Status |
| --- | --- |
| unknown resource slug | 404 |
| action not declared in `bulkActions()` | 404 |
| action with neither a bulk handler nor a record handler | 400 |
| `authorize()` refuses with `null` | 403 |
| no usable keys after filtering to scalars | 422 |
| any key outside `Resource::findRecords()` | 404 |
| the per-record check refuses any record | 403 |

Keys are de-duplicated and compared by count, so a key outside the resource scope is a visible failure rather than a partial run. An action carrying a form submits to `POST {panel}/actions/form` with `scope=bulk` instead, and lands on the same `executeBulk()`.

## Testing

```php
it('deletes the selection', function (): void {
    $this->post('/admin/actions/bulk', [
        'resource' => 'orders',
        'action' => 'delete',
        'records' => [$first->getKey(), $second->getKey()],
    ])->assertRedirect();

    expect(Order::query()->count())->toBe(0);
});

it('authorizes every record before touching any', function (): void {
    $action = Action::make('approve')
        ->authorizeEachUsing(static fn (Model $record): bool => $record->name !== 'Two')
        ->bulkAction(static fn (Collection $records) => $records->each->approve());

    expect(fn () => $action->executeBulk($records))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});
```

## Gotchas

- **500 records is the ceiling.** The endpoint validates `max:500` on the array. A "select all matching filters" over ten thousand rows is a table action that queues a job, not a bulk action.
- **The selection is keys, not a query.** Selecting every row on a page selects that page. Filtering the table and running a table action is the way to act on a whole result.
- **`authorize()` receives `null` on a bulk action.** A closure that dereferences `$record` without a null check fails on the very first render.
- **A bulk action with no `authorizeEachUsing()` falls back to `authorize($record)`.** Add `authorizeEachUsing()` when the collective answer and the row-level answer are deliberately different.
- **Falling back to `execute()` per record means one transaction per record.** If the whole batch must succeed or fail together, write a `bulkAction()` and wrap it yourself.
- **The success message is flashed once**, after the whole run. Per-record feedback is not a bulk action's shape.
- **The endpoint carries no parent segment.** A nested resource must send `parent`, which the table does automatically.

## See also

- [TableSchema basics](overview.md)
- [Record actions](record-actions.md)
- [Header and toolbar actions](toolbar-actions.md)
- [Actions overview](../actions/overview.md)
- [Action authorization](../actions/authorization.md)
- [Action forms](../actions/forms.md)
- [Restore and force delete](../actions/restore-force-delete.md)
- [Export action](../import-export/export-action.md)
- [Table API reference](api.md)
