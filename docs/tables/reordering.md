# Reordering Records

Reordering lets the user drag rows into an order and writes that order back to a column. Reach for it when the order *is* data — menu items, steps in a checklist, priority in a queue — rather than something the reader is choosing to look at.

## A minimal working example

Add an integer column to the table:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::table('menu_items', function (Blueprint $table): void {
    $table->unsignedInteger('position')->default(0);
});
```

Then name it on the schema:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\MenuItems\Tables;

use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class MenuItemsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable(),
            ])
            ->reorderable('position');
    }
}
```

Every row now carries a drag handle, and dropping one posts the new order.

## The API

```php
use PandaPanel\Tables\TableSchema;

TableSchema::reorderable(string $column): self
TableSchema::getReorderColumn(): ?string
TableSchema::isReorderable(): bool
```

| Method | Returns | Default |
| --- | --- | --- |
| `reorderable('position')` | `self` | off — a table is not reorderable until it says so |
| `getReorderColumn()` | `?string` | `null` |
| `isReorderable()` | `bool` | `false` |

`reorderable()` also fixes the sort: it calls `defaultSort($column, SortDirection::Ascending)` for you. An order the user arranged only means something while the table is showing that order, so the two are one decision rather than two that can disagree.

**A reorderable table offers no [card layout](card-layout.md).** `availableLayouts()` returns `['table']` for it however its card face is declared. An order arranged by dragging is a linear one, and dragging a card into place in a grid that wraps is a different interaction needing a different affordance — a layout that cannot do the thing reordering was turned on for is worse than no layout at all. The rule is enforced on the server, so the toggle simply does not render.

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

$schema = TableSchema::make()
    ->columns([TextColumn::make('label')])
    ->reorderable('position');

$schema->getReorderColumn();          // 'position'
$schema->getDefaultSortColumn();      // 'position'
$schema->getDefaultSortDirection();   // SortDirection::Ascending
```

The serialized definition carries one boolean, `reorderable`, which is what makes the handle column appear:

```php
$schema->toArray()['reorderable'];    // true
```

## What the drag sends

The client works out the resulting order and posts the **key order** — position in that list is the order. It never invents a value for a column it knows nothing about.

```text
POST {panel path}/actions/reorder      route name: panel.{panelId}.actions.reorder
```

```json
{ "resource": "menu-items", "records": [7, 3, 12, 4] }
```

A nested resource also sends `parent`, resolved and bound the way route middleware does for the resource's own pages.

The request is validated as `records` being an array of 1 to 500 entries, each required. Then, in order:

1. The resource slug resolves inside the panel resolved for this request, or 404.
2. `TableSchema::getReorderColumn()` is non-null, or **400 — "This table is not reorderable."**
3. `Resource::query()->findMany($keys)` returns exactly as many records as keys, or 404. This one deliberately uses the list query rather than the record lookup: a trashed record has no place in the arrangement.
4. `Resource::canEdit($record)` for **every** record, or 403 — checked before anything is written.
5. The writes run inside one `DB::transaction()`: `$record->forceFill([$column => $position])->save()` for each. A list that half-reordered would be worse than one that did not move.

The response redirects back with a `success` flash of "Order updated."

## Position is the index in the submitted list

The controller flips the submitted keys into positions:

```php
$positions = array_flip(array_map(strval(...), $keys));
```

So the first key becomes `0`, the second `1`, and so on. Two consequences worth planning for:

- **The client sends the rows on screen.** Reordering on page two writes `0…n-1` again for those rows, overlapping page one's values. Give a reorderable table a `perPage` large enough to hold the whole list, or accept that ordering is per page.
- **Whatever the table is currently sorted by is the order that gets written.** If the user sorts by `label` and then drags a row, the positions recorded are the positions of the *displayed* order. Fixing the default sort to the order column is what makes the common case right; `toggleable(false)` on the columns that matter and leaving the other columns unsortable is what keeps it right.

## Authorization

There is no per-action policy here — reordering is not an `Action` and has no name to look up. It is authorized with `Resource::canEdit()` on every record in the payload, before any of them is touched. A resource whose policy refuses even one row refuses the whole arrangement.

```php
use App\Models\MenuItem;
use App\Policies\MenuItemPolicy;
use Illuminate\Support\Facades\Gate;

Gate::policy(MenuItem::class, MenuItemPolicy::class);
```

Without a policy registered, the Gate refuses and the drag answers 403, which is the correct default: an unguarded write endpoint would be worse.

## Notes

- **Reordering is not an action.** There is nothing to confirm and no handler to look up, only a new order to record, so it posts to its own endpoint and never enters the confirmation flow.
- **`reorderable()` arranges rows; `reorderableColumns()` arranges columns.** The names are close and the features are unrelated: one writes a database column, the other is presentation and is never persisted to the database. See [Column manager](column-manager.md).
- **The column must be writable.** The controller uses `forceFill()`, so a `$guarded` list does not block it, but a column that does not exist is a database error rather than a validation message.
- **500 records per request** is the hard limit in the endpoint's validation rules.
- **Relation manager tables do not reorder.** The schema accepts `reorderable()` and the handle is drawn, but the relation table's frontend does not wire the drag to an endpoint, and the reorder endpoint resolves a resource rather than a relation. Reorder related records from their own resource list instead.
- **A reorderable table still paginates, searches, and filters.** Nothing about reordering changes what the query returns.

## See also

- [Sorting](sorting.md)
- [Pagination](pagination.md)
- [Column manager](column-manager.md)
- [Record actions](record-actions.md)
- [Resource authorization](../resources/authorization.md)
- [Nested resources](../resources/nested-resources.md)
- [Tables overview](overview.md)
