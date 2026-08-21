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

### Measuring is a loop, and `useFrozenColumns` is what stops it closing

Reading layout and writing reactive state are the two halves of a render loop:

1. a render hands every header cell its template ref,
2. the ref callback measures and writes the widths,
3. the write re-renders, which hands out the refs again.

Closed, that is Vue's **"Maximum recursive updates exceeded"** — thrown the moment a column was pinned, with nothing in the stack naming the table. Three things keep it open, and all three have to hold:

| Rule | Why it is load bearing |
| --- | --- |
| **The ref callback is stable per column key.** `measure('name')` returns the same function object every time. | A closure created inside `render` is a *new* ref as far as Vue is concerned, and Vue re-invokes a ref whose identity changed — on every patch, including the patch the last invocation caused. |
| **The callback does nothing when handed the element it already holds.** | Vue re-sending the same cell is the common case. Re-observing and re-measuring it is the loop taking its second step. |
| **A measurement writes only when a width actually moved,** by at least `WIDTH_EPSILON` (0.5px). | Layout is fractional and a `ResizeObserver` fires far below a pixel. Assigning an equal-but-new object is still a reactive write. |

Two more properties follow from measuring the browser rather than a model:

- **Measurement is scheduled with `requestAnimationFrame`.** Reading layout inside the ref callback forces a synchronous reflow per cell, in the middle of the patch that produced the layout.
- **Teardown cancels the pending frame,** disconnects the observer and clears both maps. A frame that runs against a table that has gone measures elements no longer in the document.

If you are writing a component that pins cells of its own, those are the rules to keep. A ref like `:ref="(el) => measure(column.name)(el)"` re-creates the callback per render and puts the loop back.

### What is measured, and against what

The widths come from the **header** cells; every other row takes its offsets from them, which is what keeps the header, the per-column search row, the body and the summary footers lined up.

The width they are compared against is the table's **scroll lane** — the `[data-slot="table-container"]` wrapper `Table` renders — not the bordered container around it. They are different boxes the moment a table sits in a padded shell, and the lane is the one that scrolls.

The container you hand `useFrozenColumns` may be the wrapper element, a `Table` component's instance, or `null` while nothing is mounted. A ref on a component holds the *instance*, and `clientWidth` read off an instance is `undefined` — which reads as "there is no lane to run out of", so the threshold would never trigger on any screen. Both the container and every cell ref go through the same unwrapping: an `Element` is used as it is, anything else is taken from `$el` and only if `$el` is itself an `Element`, since a component with a fragment root has a comment node there.

Every write the observer makes is idempotent. A `ResizeObserver` fires for a scroll, a font swap, a parent resize that changed nothing here — far more often than a width actually moves — and re-assigning an equal map on each of those is the loop again with extra steps.

## Pinning drops itself on a narrow screen — one side at a time

Frozen columns may occupy at most **85% of the scroll lane, measured per side**. Past that, freezing stops being a help and becomes the problem: a pinned group can leave a strip too narrow to read the rest through, and the user cannot scroll out of it because the pinned columns are the ones in the way.

**The sides are evaluated separately, and that is the whole point.** Summing them and comparing the total is what unpinned two identity columns at the leading edge of a phone-width table *because the row's actions were pinned at the trailing edge* — two columns the user needs, dropped on account of a column at the other end of the row. A wide trailing group now drops itself and leaves the leading one pinned.

The old value was 0.6 applied to both sides added together, which was wrong twice over: too low for a phone, and asked of a total no user experiences as one quantity. So on a 360px viewport a `Number` and a `Name` column now stay `position: sticky` through a horizontal scroll, alongside a pinned actions column that may or may not survive on its own account. What still drops is a single side that has all but swallowed the lane.

Above the threshold that side behaves like an ordinary column group. It is checked on every resize, not decided once, so rotating a phone or opening a sidebar restores the pinning when there is room for it again.

## A frozen cell has to be opaque, and the row is what makes it so

A `position: sticky` cell paints *above* the cells scrolling under it. A transparent one has that content pass straight through — which reads as "pinning does not work" rather than as a colour problem, and is the second half of most reports that it does not.

The rendering is three layers, and each is a visible bug when it is missing:

- **The row carries an opaque background.** `bg-background`, plus an opaque `hover:bg-muted` rather than a half-transparent tint. `bg-inherit` can only inherit what the row has, so a row with no background of its own hands the pinned cell transparency.
- **The cell is `bg-inherit`,** not a colour of its own — otherwise it is the one cell in the row that never highlights on hover or selection.
- **`.panel-table-frozen-cell::before` paints an opaque plate under the inherited colour,** inside the cell's own stacking context. Rows that are legitimately tinted — a group band, a summary row — inherit a colour you can see through, and half-visible scrolling text under a pinned column is the same bug in a politer form.

The last frozen cell on each side also carries a hairline and a short gradient, so the seam is something the eye can find instead of a place where columns appear to teleport.

## The stylesheet has to ship the frozen rules

`.panel-table-frozen-cell` and `.panel-table-frozen-edge` are component classes in **`resources/css/panda-panel.css`** — the file that is published into your application, imported by the package's own build, and compiled by your Tailwind. A rule in a CSS file no entrypoint imports is a rule that does not exist, and the symptom is a pinned column with no divider and, in a tinted row, no opacity.

```css
@layer components {
    .panel-table-frozen-cell::before { /* the opaque plate */ }
    .panel-table-frozen-edge::after { /* the seam */ }
    .panel-table-frozen-edge-start::after { /* falling away to the right */ }
    .panel-table-frozen-edge-end::after { /* mirrored */ }
}
```

Two things to keep if you edit them:

- **The side comes from a class the renderer writes,** `panel-table-frozen-edge-start` or `-end`, not from reading the inline `left`/`right` offset back out of the style attribute. A stylesheet that infers what a component already knows is a second source of truth for one fact, and it was wrong for any column that also declared a `width()`.
- **Do not import `panda-panel.css` from a stylesheet that `panda-panel.css` imports.** The published file is a complete Tailwind 4 entrypoint — `@import 'tailwindcss'`, `@theme`, `@source` — and is meant to be built directly *or* imported once by your `app.css`, never both ways round. A circular `@import` is a build error at best and a stylesheet that quietly loses half its rules at worst, which looks exactly like the missing-seam symptom above. The package's own copy imports `tailwindcss` and `tw-animate-css` and nothing else, and a test asserts that list. See [Tailwind 4 issues](../troubleshooting/tailwind.md).

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

The invariants the browser half depends on are asserted about the source: `tests/Feature/Panel/TableFrozenColumnTest.php` reads `DataTable.vue`, `useFrozenColumns.ts` and `panda-panel.css` and fails when the stable ref callback, the epsilon, the per-side flags, the opaque row background or the shipped CSS rules go missing.

The arithmetic over the measured widths — which offset, which side is dropped, which cell carries the seam — is covered by `resources/js/panel/tables/useFrozenColumns.test.ts`, run by `npm run test`. It fakes the DOM, because everything it asserts is arithmetic and a layout engine would decide none of it.

What a layout engine decides is covered by a script rather than by a runner, since the package ships none (see [ADR 001](../contributing/architecture-decisions.md)):

```bash
npm run test:browser
```

It builds the fixture in `frontend/browser`, serves it, drives whatever Chrome is on the machine over the DevTools protocol at a 360×800 mobile viewport, and asserts that the table overflows, that both pinned headers report `position: sticky`, that neither moves when the lane scrolls under them, that a pinned cell's background is not `rgba(0, 0, 0, 0)`, that the last pinned column has an `::after` seam, and that Vue logged no recursive-update warning. `PANDA_CHROME` names a different binary; nothing is installed and it is not part of `npm run ci`.

## Gotchas

- **Freezing reorders the table.** A column pinned to the end is drawn last however the column manager arranged it. That is deliberate, and it is the only way sticky offsets can be correct.
- **`frozen()` is a per-column decision, `frozenActions()` a per-table one.** There is no method that freezes the selection checkbox on its own; it follows the first frozen start column.
- **Freezing many columns is self-defeating.** Past 85% of the scroll lane *that side's* pinning is dropped rather than degraded, so pin the one or two columns that identify a row and nothing else. The other side is unaffected.
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
