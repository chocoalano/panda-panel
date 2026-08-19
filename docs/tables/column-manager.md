# Column Manager

The column manager lets a user choose which columns a table shows and, when the table allows it, what order they sit in. You reach for it on any table wide enough that not everybody wants every column — which in practice is most tables with more than about six.

Visibility and order are server state. The request asks, the schema validates, and `state()['columns']` reports what was actually applied.

## A minimal manageable table

```php
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([
        TextColumn::make('reference')->toggleable(false),
        TextColumn::make('customer'),
        TextColumn::make('status'),
        TextColumn::make('internal_notes')->visible(false),
        DateTimeColumn::make('created_at'),
    ])
    ->reorderableColumns()
    ->persistColumnsInSession();
```

That table opens showing `reference`, `customer`, `status`, and `created_at`. `internal_notes` is available in the manager but hidden until somebody asks for it, and `reference` can never be hidden at all.

## What a column declares

| Method | Signature | Default | Meaning |
| --- | --- | --- | --- |
| `visible()` | `visible(bool $visible = true): static` | `true` | whether the column is shown before anybody changes anything |
| `toggleable()` | `toggleable(bool $toggleable = true): static` | `true` | whether the user may hide it |

The two are independent. `visible(false)` is a starting position; `toggleable(false)` is a rule. A column that is not toggleable stays visible however the request asks — it is the one that identifies the record, and a table without it is a list of anonymous rows.

```php
$schema->defaultVisibleColumnNames();   // ['reference', 'customer', 'status', 'created_at']
$schema->toggleableColumnNames();       // ['customer', 'status', 'internal_notes', 'created_at']
$schema->columnNames();                 // every column, in declared order
```

## How the state is read

The manager writes into the query string:

```
?columns[visible][]=customer&columns[visible][]=status
&columns[order][]=status&columns[order][]=customer
```

`TableQuery` resolves that into `{visible: string[], order: string[]}` with four rules:

1. **An unknown name is dropped.** `columns[visible][]=password` on a table with no such column contributes nothing.
2. **A duplicate in the order is ignored.** The first occurrence wins.
3. **Anything the arrangement did not mention keeps its declared place**, appended after the names that were mentioned. Adding a column to a table therefore does not leave it invisible for everyone who had already arranged the old ones.
4. **A non-toggleable column is added back to `visible`** whatever the request said.

`visible` is then reported in the arranged order, so the frontend can render straight from it.

```php
$state = $tableQuery->state();

$state['columns']['visible'];   // list<string>, in the order they are drawn
$state['columns']['order'];     // list<string>, every column
```

When the request says nothing about `columns` at all, `visible` falls back to `defaultVisibleColumnNames()` and `order` to the declared order.

## Behaviour

```php
return $table
    ->reorderableColumns()
    ->columnManagerInModal()
    ->columnManagerTrigger('Layout', 'settings')
    ->deferColumnManager()
    ->showColumnManagerReset(false)
    ->persistColumnsInSession();
```

| Method | Signature | Default |
| --- | --- | --- |
| `reorderableColumns()` | `reorderableColumns(bool $reorderable = true): self` | `false` |
| `deferColumnManager()` | `deferColumnManager(bool $defer = true): self` | `false` |
| `columnManagerTrigger()` | `columnManagerTrigger(string $label, ?string $icon = null): self` | `Columns`, no icon |
| `columnManagerInModal()` | `columnManagerInModal(bool $inModal = true): self` | `false` |
| `showColumnManagerReset()` | `showColumnManagerReset(bool $show = true): self` | `true` |
| `persistColumnsInSession()` | `persistColumnsInSession(bool $persist = true): self` | `false` |

Read-side: `hasReorderableColumns(): bool` and `persistsColumnsInSession(): bool`.

**`reorderableColumns()`** is about *columns*, and it is never persisted to the database: which columns somebody wants to see is theirs, not the record's. The method that arranges *rows* and writes an order column is `reorderable()` — see [Reordering](reordering.md).

**`columnManagerInModal()`** opens the manager as a dialog rather than a popover. A long column list is easier to work in with the page dimmed behind it, and dragging to reorder needs the room.

**`deferColumnManager()`** holds changes until an apply action is used, for a table where each render is expensive enough that toggling six columns should not be six requests.

## What the frontend receives

`toArray()['columnManager']`:

| Key | Source |
| --- | --- |
| `reorderable` | `reorderableColumns()` |
| `deferred` | `deferColumnManager()` |
| `triggerLabel` | `columnManagerTrigger()`, default `Columns` |
| `triggerIcon` | `columnManagerTrigger()`, default `null` |
| `resetLabel` | always `Reset` |
| `showReset` | `showColumnManagerReset()` |
| `modal` | `columnManagerInModal()` |
| `toggleable` | `toggleableColumnNames()` |

There is no setter for `resetLabel`; only whether the reset action appears is configurable.

## Persistence

```php
$table->persistColumnsInSession();
```

The arrangement is remembered per user, under a session key built from the panel id and the resource slug (plus the relation key for a relation table). The rules are the same ones sort, search, and filters follow:

- The request wins whenever it says anything at all, **including** that the arrangement is now empty. `?columns=` resets to the declared layout.
- Absence is the only case that falls back to what was stored.
- A remembered arrangement goes through the same validation a fresh one does, so a stale session naming a column the table no longer has is ignored.
- A table rendered without session middleware remembers nothing rather than failing.

See [Persisted table state](persisted-state.md).

## Testing

```php
use Illuminate\Http\Request;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

$request = Request::create('/', 'GET', [
    'columns' => ['visible' => ['status'], 'order' => ['status', 'customer']],
]);

$request->setLaravelSession(app('session.store'));

$state = (new TableQuery($schema, $request, null, 'panel.admin.table.orders'))->state();

expect($state['columns']['order'])->toBe(['status', 'customer', 'reference', 'internal_notes', 'created_at'])
    ->and($state['columns']['visible'])->toBe(['status', 'reference']);
```

The `reference` column is in `visible` because it is not toggleable, and it sits where the arrangement left it.

## Gotchas

- **Hiding a column does not remove it from the payload.** Rows still carry every declared column's cell; visibility is presentation. A column that is expensive to compute and rarely wanted belongs behind a different table, not behind `visible(false)`.
- **The manager cannot hide a non-toggleable column**, and the frontend does not show one as a toggle either — `columnManager.toggleable` is the list it renders from.
- **Order and visibility are one decision each, remembered as a whole.** Sending `columns[visible]` without `columns[order]` leaves the order at its declared value, and vice versa.
- **TanStack keeps no copy of this.** The server is the single source of truth for which columns are shown; a second copy in the client would be a second answer to the same question.
- **A pinned column is drawn at its edge regardless of the arrangement.** Freezing wins over order — see [Frozen and pinned columns](pinned-columns.md).

## See also

- [TableSchema basics](overview.md)
- [Columns](columns.md)
- [Frozen and pinned columns](pinned-columns.md)
- [Persisted table state](persisted-state.md)
- [Reordering](reordering.md)
- [Table API reference](api.md)
