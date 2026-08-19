# Card Layout

A card layout lets the same table be drawn as a grid of cards instead of rows. You reach for it when a record is a *thing* rather than a row of figures — a person, a product, an asset — and a picture and a name say more than eight columns do.

It is a second renderer over one `TableSchema`, not a second page. The query, the filters, the search, the tabs and the pagination are the identical ones the row table uses; only the template differs. A card face is an arrangement of the columns the table already declares, never a second set of them.

## A minimal card table

```php
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\ImageColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([
        ImageColumn::make('avatar')->circular(),
        TextColumn::make('name'),
        TextColumn::make('email'),
        BadgeColumn::make('status'),
        TextColumn::make('team'),
    ])
    ->cards();
```

`cards()` takes no required argument, and that is the whole opt-in. The face is inferred: `avatar` is the picture, `name` is the heading, `status` is a chip, and `email` and `team` become the value rows. A layout toggle appears in the toolbar, and the table opens as rows until somebody presses it.

## `TableSchema`

| Method | Signature | Default | Meaning |
| --- | --- | --- | --- |
| `cards()` | `cards(?CardLayout $layout = null): self` | not declared | Declares a card face. Bare, the face is inferred. |
| `defaultLayout()` | `defaultLayout(TableLayout $layout): self` | `TableLayout::Table` | Which layout the table opens in. |

Read-side: `getDefaultLayout(): TableLayout`, `availableLayouts(): list<TableLayout>`.

`defaultLayout(TableLayout::Grid)` on its own **counts as declaring a card face**. A table that says it opens as cards has said it has cards, and the inferred face is exactly what `cards()` would have produced — the alternative is a line of configuration that silently does nothing.

## `CardLayout`

Five slots, and each holds the *name* of a column the table already declares.

```php
use PandaPanel\Tables\CardLayout;

->cards(
    CardLayout::make()
        ->image('avatar')
        ->title('name')
        ->description('email')
        ->badges(['status', 'verified'])
        ->details(['team', 'plan', 'created_at'])
        ->columns(3),
)
```

| Method | Signature | Default | Meaning |
| --- | --- | --- | --- |
| `image()` | `image(?string $column): self` | inferred | The picture. |
| `title()` | `title(?string $column): self` | inferred | The heading. |
| `description()` | `description(?string $column): self` | **empty** | A line under the heading. |
| `badges()` | `badges(?array $columns): self` | inferred | Chips beside the heading. |
| `details()` | `details(?array $columns): self` | inferred | Label and value rows in the body. |
| `columns()` | `columns(int $count): self` | `3` | Cards per row, clamped to `ColumnCount::MAX`. |

Passing `null` — or `[]` to the list slots — declares the slot **empty** rather than asking for inference. `->image(null)` means "no picture", not "pick one for me".

There is no footer slot. The record actions are the footer, and they are already resolved per row with authorization applied, so an action the user may not run is simply absent from the card.

## The fallback rule

Inference runs over the columns in declared order, considering only those the table starts with visible. Explicit slots are taken first, and inference never reuses a column an explicit slot claimed — so naming one thing does not silently duplicate it elsewhere on the same card.

1. **image** — the first remaining column of type `image`. None, no picture.
2. **title** — the first remaining column that is not an editable type (`toggle`, `checkbox`, `text_input`, `select`); a `Switch` is not a heading. If that yields nothing, the first remaining column whatever its type — a card with an odd heading beats a card with none.
3. **description** — **never inferred.** Empty unless declared.
4. **badges** — every remaining column of type `badge`, `boolean` or `icon`, in declared order.
5. **details** — everything still remaining, **capped at four**.

Two of those deserve their reasons stated.

**A description is never inferred** while every other slot is. There is no rule for "which column is the subtitle" that is right more often than it is wrong, and two lines of near-identical text under each other reads worse than one line. Guessing here costs more than leaving it empty.

**The detail cap applies to inference only.** An explicit `details([...])` is taken as written however long it is — somebody who wrote six meant six. Without the cap a thirty-column table would infer a thirty-row card, which is a table with rounded corners rather than a card.

A slot naming a column the table does not declare throws `PanelSchemaException`. It is a mistake in a resource file, only fixable there, and the symptom otherwise is a blank slot with nothing anywhere to say why.

## What the frontend receives

Two keys in the table definition:

```php
'layouts' => ['table', 'grid'],
'cards' => [
    'columns' => 3,
    'image' => 'avatar',
    'title' => 'name',
    'description' => 'email',
    'badges' => ['status', 'verified'],
    'details' => ['team', 'plan', 'created_at'],
],
```

`cards` is `null` for a table that declared no face, and `layouts` is then `['table']` alone — which is what makes the toggle render nothing at all.

Column **names**, never definitions. The definitions are already in `columns`; sending them twice would be two places for the same column to disagree with itself.

`layouts` is the *answer* rather than the rule. A reorderable table offers only the table however its face is declared, and that is decided here so nothing on the frontend has to re-derive it.

One key in the applied state:

```php
'layout' => 'grid',
```

Always present, always a member of `layouts`. The resolved layout rather than the requested one, so a rejected `?layout=` leaves the toggle pointing at what is actually on screen.

## Sorting without headers

In the row table, sorting *is* the header: clicking one sorts by it and clicking it again reverses. A card grid has no header, so in grid layout the toolbar grows a sort menu listing every `sortable()` column.

It applies the same rule the header does — a different column sorts ascending, the active column reverses — because it emits the same event into the same handler. There is deliberately no separate Ascending/Descending pair: two controls with different interaction models for one piece of state is worse than one control with fewer affordances, and the arrow already says which way the next click goes.

The menu's label falls back to `defaultSort`'s own label, the same string the toolbar shows as plain text in table layout.

## Persistence

The layout travels as `?layout=`, whitelisted against `layouts` exactly as `perPage` is whitelisted against `perPageOptions`. An unknown value, or `grid` on a table with no face, is ignored rather than reaching a renderer with nothing to draw with.

It is remembered by **`persistColumnsInSession()`**, not a flag of its own:

```php
->cards()
->persistColumnsInSession()
```

How a list is drawn and which columns are drawn are one decision. Both are presentation, both belong to the user rather than to the record — the same reasoning that has the active group ride along with `persistSortInSession()`. Sorting is a question about the data; layout is not.

Because the session is read back through the same whitelist a fresh request is, a remembered `grid` on a table that has since dropped `cards()` is discarded exactly as a hand-typed one would be.

## What the grid does not do

| Feature | In grid layout |
| --- | --- |
| Frozen columns, `Column::width()`, `headerAlignment()`, `wrapHeader()`, `headerTooltip()` | **Inert.** A grid does not scroll sideways and has no header to apply them to. |
| `Column::alignment()` | **Applied**, to the value rows only. A heading is start-aligned. |
| Per-column search | **Hidden.** It is a second header row. The toolbar's table-wide search is unaffected. |
| Table summaries | **Kept**, as a strip under the grid. |
| Group summaries | **Kept**, as a strip closing the run of cards each band heads. |
| Group bands | **Kept**, as a full-width heading between card runs. |
| Row reordering | **Not offered.** A reorderable table has no grid layout at all — see below. |
| Selection and bulk actions | **Work.** The checkbox sits in the card's corner. |
| Column manager | **Works, and matters more** — it now decides which badge and detail slots appear. |
| `recordActions()` position | **Inert.** All three positions collapse to the card footer. |
| Tabs, filters, pagination, empty state, cell URLs, cell actions, editable columns | **Work unchanged.** |

## Testing

```php
$definition = UsersTable::configure(TableSchema::make())->toArray();

expect($definition['layouts'])->toBe(['table', 'grid'])
    ->and($definition['cards']['title'])->toBe('name');
```

```php
$request = Request::create('/', 'GET', ['layout' => 'grid']);
$request->setLaravelSession(app('session.store'));

$state = (new TableQuery($schema, $request, null, 'panel.admin.table.users'))->state();

expect($state['layout'])->toBe('grid');
```

## Gotchas

- **A reorderable table has no grid layout.** `reorderable()` and `cards()` together resolve to `['table']`. An order the user arranges by dragging is a linear one, and dragging a card into place in a grid that wraps is a different interaction needing a different affordance — offering a layout that cannot do the thing reordering was turned on for is worse than not offering it. This is enforced on the server, so the frontend never reasons about it.
- **`image` and `title` ignore column visibility; the other three respect it.** A card with no heading is not a card. The body slots are the card's equivalent of a row's cells, and the column manager is exactly the control for those.
- **The column manager can empty a card's body.** Hiding every detail column leaves a card of headings. That is the user's choice and reads as one, but a table whose card face is mostly optional columns is worth a second look.
- **`->cards()` alone is a complete opt-in.** No column has to be touched, and no existing table changes until one is added.
- **The layout is not a per-panel setting.** It is per user, per table, in their session — two people looking at the same resource can be looking at different layouts.

## See also

- [Tables overview](overview.md) — what a table is made of
- [Columns](columns.md) — the column types a card face arranges
- [Sorting](sorting.md) — including the toolbar menu grid layout uses
- [Column manager](column-manager.md) — which slots a card actually draws
- [Persisted table state](persisted-state.md) — what `persistColumnsInSession()` remembers
- [Summaries](summaries.md) and [Reordering](reordering.md) — the two features grid layout changes
- [Table API reference](api.md)
