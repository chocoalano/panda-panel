# Frozen And Pinned Columns

Freezing keeps a column in view while the rest of the table scrolls sideways. You reach for it on any table wide enough to scroll: without it, scrolling out to column fourteen takes the name that identifies the row off the screen, and every cell after that is a value with nothing attached to it.

It lives on `PandaPanel\Tables\Columns\Column`, so it is available on **every** column type.

## A minimal frozen table

```php
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\ColumnPin;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([
        TextColumn::make('reference')->frozen()->toggleable(false),
        TextColumn::make('customer'),
        TextColumn::make('address'),
        TextColumn::make('notes'),
        NumberColumn::make('balance')->frozen(ColumnPin::End),
    ])
    ->frozenActions();
```

`reference` stays at the left edge, `balance` at the right, and the row's action buttons stay with `balance`.

## `Column::frozen()`

```php
frozen(ColumnPin|bool $pin = true): static
```

| Argument | Result |
| --- | --- |
| `true` (default) | pinned to the leading edge — `ColumnPin::Start` |
| `ColumnPin::Start` | the same, said explicitly |
| `ColumnPin::End` | pinned to the trailing edge |
| `false` | not pinned |

```php
TextColumn::make('name')->frozen();                  // to the left edge
TextColumn::make('total')->frozen(ColumnPin::End);   // to the right
TextColumn::make('name')->frozen(false);             // unpin, for a column a shared base schema froze
```

`getFrozen(): ?ColumnPin` reads it back. `PandaPanel\Tables\Enums\ColumnPin` is a closed set of `Start` and `End` — an enum rather than a boolean, because "frozen" on its own is ambiguous the moment a table is wide enough to want it: the identifying columns belong on the left and the row's actions on the right.

## `TableSchema::frozenActions()`

```php
frozenActions(bool $frozen = true): self
hasFrozenActions(): bool
```

Off by default. Pinning the row's buttons costs horizontal room, and a table narrow enough not to scroll gains nothing from it. Turn it on when scrolling out to read a value and then scrolling back to act on the row is the same problem in the other direction.

## What the payload says

Two answers, so the frontend never has to re-derive which side is pinned:

```php
$schema->toArray()['frozen'];      // ['start' => true, 'actions' => false]
$column->toArray()['frozen'];      // 'start' | 'end' | null
```

`hasFrozenStart(): bool` is true when any column is pinned to the leading edge. The reorder handle and the selection checkbox are frozen with it rather than on their own account: they sit to the left of every data column, and letting them scroll while a column beside them stays put would be two elements disagreeing about where the row begins.

## A pinned column is drawn at its edge

Whatever position it was declared in. This is not a stylistic choice. A sticky cell is offset by the total width of the frozen columns before it, so a frozen column left sitting in the middle would be offset over the top of the ones it was declared after. Moving it is what pinning means in every table that offers it, and it is visible — which the alternative, a column that quietly declines to freeze, is not.

The renderer partitions the visible columns into three lists: `frozen === 'start'`, `frozen === null`, `frozen === 'end'`, and draws them in that order.

Freezing a column freezes everything structural on the same side of it: the reorder handle and the selection checkbox to the left, and the row actions to the right when `frozenActions()` asked for them.

## Offsets are measured, not declared

A frozen column does not have to state a `width()`. The browser reads the width each header cell actually took, through a `ResizeObserver`, and re-reads it whenever it changes.

Adding declared widths up in PHP would be wrong exactly when it matters: a column sized to its content is the normal case, and frozen columns drifting a pixel out of line on a long name is worse than never freezing at all.

`width()` still works and is still useful for stability. It is not a requirement of freezing:

```php
TextColumn::make('reference')->frozen()->width('12rem');
```

## Three rendering details

Each is a visible bug when it is missing:

- **A frozen cell is `bg-inherit`,** so it is opaque — a transparent sticky cell has the scrolling content pass underneath it — while still taking the row's own hover and selected background rather than being the one cell that never highlights.
- **The last frozen cell on each side carries a hairline and a short gradient,** so the seam is something the eye can find instead of a place where columns appear to teleport.
- **The header, the per-column search row, and the summary footers are all pinned with the body.** Pinning one and not the others is a table whose columns stop lining up the moment it scrolls.

## Pinning drops itself on a narrow screen

Frozen columns may occupy at most 60% of the visible table width. Past that, freezing stops being a help and becomes the problem: on a phone, three pinned columns can leave a strip too narrow to read the rest through, and the user cannot scroll out of it because the pinned columns are the ones in the way.

Above the threshold the table behaves like an ordinary one. It is checked on every resize, not decided once, so rotating a phone or opening a sidebar restores the pinning when there is room for it again.

## Testing

The server half is assertable directly:

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\ColumnPin;
use PandaPanel\Tables\TableSchema;

expect(TextColumn::make('name')->frozen()->toArray()['frozen'])->toBe('start')
    ->and(TextColumn::make('total')->frozen(ColumnPin::End)->getFrozen())->toBe(ColumnPin::End)
    ->and(TextColumn::make('name')->frozen()->frozen(false)->toArray()['frozen'])->toBeNull();

$table = TableSchema::make()
    ->columns([TextColumn::make('name')->frozen(), TextColumn::make('email')])
    ->frozenActions()
    ->toArray();

expect($table['frozen'])->toBe(['start' => true, 'actions' => true]);
```

The browser half — the measured offsets, the opacity, the threshold — is covered by the frontend contract tests, which read `resources/js/panel/tables/DataTable.vue` and `useFrozenColumns.ts` and assert the invariants are still there.

## Gotchas

- **Freezing reorders the table.** A column pinned to the end is drawn last however the column manager arranged it. That is deliberate, and it is the only way sticky offsets can be correct.
- **`frozen()` is a per-column decision, `frozenActions()` a per-table one.** There is no method that freezes the selection checkbox on its own; it follows the first frozen start column.
- **Freezing many columns is self-defeating.** Past 60% of the table width the pinning is dropped entirely rather than degraded, so pin the one or two columns that identify a row and nothing else.
- **A frozen column with no visible width still measures.** An `ImageColumn` with `label('')` is a legitimate frozen column; its header cell width is what gets measured.
- **Hiding a frozen column through the column manager removes it from the frozen set** for that user, and the offsets are re-measured. Nothing else has to be told.

## See also

- [Columns](columns.md)
- [Column manager](column-manager.md)
- [TableSchema basics](overview.md)
- [Record actions](record-actions.md)
- [Reordering](reordering.md)
- [Frontend contract tests](../testing/frontend-contract-tests.md)
- [Table API reference](api.md)
