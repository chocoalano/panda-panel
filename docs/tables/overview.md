# TableSchema Basics

`PandaPanel\Tables\TableSchema` is the declarative description of a resource's index table: its columns, its filters, its actions, and how it sorts, searches, and pages. You reach for it whenever a resource needs a list — and for the same list inside a relation manager, a table widget, or over data that never went near a database.

The schema is also the whitelist. Sorting, searching, filtering, and column visibility read only what a column or a filter declared here; everything else in the URL is ignored rather than passed through to the query builder.

## A minimal table

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->toggleable(false),
                TextColumn::make('status')->sortable(),
                DateTimeColumn::make('created_at')->label('Published')->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->searchPlaceholder('Search posts...');
    }

    // form() and pages() as usual
}
```

`ListRecords` calls `Resource::table(TableSchema::make())` on every render, so the schema is rebuilt per request and a closure inside it sees the current user, tenant, and locale.

## How a request becomes rows

Four objects, in this order:

1. `Resource::query()` produces the builder. The table layer never starts a query of its own, so tenant, module, and permission scopes stay applied.
2. `PandaPanel\Tables\TableQuery` reads the URL — `page`, `perPage`, `search`, `columnSearch`, `sort`, `direction`, `filters`, `columns`, `group` — validates every value against the schema, and applies what survives.
3. `TableSchema::toRow()` serializes each record into cells, per-row cell metadata, and the record actions that user may run.
4. `ListRecords::render()` sends `table` (the definition), `state` (what the server *applied*), `rows`, `summaries`, `pagination`, and `tabs` to Vue.

```php
use Illuminate\Http\Request;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

$schema = PostResource::table(TableSchema::make());

$query = new TableQuery($schema, request(), namespace: null, sessionKey: null);

$records = $query->paginate(PostResource::query());

$rows = array_map(
    static fn ($record): array => $schema->toRow($record),
    $records->items(),
);

$state = $query->state();
```

`state()` is what the server applied, not what was requested. A rejected sort column comes back as `null` and a rejected filter is absent, so a control is never rendered active for something the query ignored.

## Columns

Columns are the only required part of a schema. Every type extends `PandaPanel\Tables\Columns\Column`, so `sortable()`, `searchable()`, `frozen()`, `tooltip()`, `url()`, `width()`, and `summarize()` are available on all of them.

```php
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\BadgeColor;

$table->columns([
    TextColumn::make('reference')->searchable(individually: true)->toggleable(false),
    BadgeColumn::make('status')->colors(['open' => BadgeColor::Info])->sortable(),
    NumberColumn::make('total')->prefix('$')->decimals(2)->sortable(),
]);
```

Declaring the same name twice throws `PandaPanel\Exceptions\PanelSchemaException` at the setter, because a column name is the key its cell, its visibility, its search term, and its sort all live under. See [Columns](columns.md).

## Filters

```php
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Filters\TernaryFilter;

$table->filters([
    SelectFilter::make('status')->options(['open' => 'Open', 'done' => 'Done']),
    TernaryFilter::make('published_at')->nullable()->labels('Published', 'Draft', 'Anyone'),
]);
```

Filter names must be unique for the same reason column names must: filter state travels in the query string keyed by name. See [Filters](filters.md) and [Query builder filters](query-builder.md).

## Sorting and searching

```php
use PandaPanel\Tables\Enums\SortDirection;

$table
    ->defaultSort('created_at', SortDirection::Descending)
    ->defaultSortOptionLabel('Newest first')
    ->searchPlaceholder('Search by name or reference...')
    ->searchDebounce(500)
    ->searchOnBlur()
    ->splitSearchTerms(false);
```

`defaultSort()` is checked against the declared columns when the schema is serialized, not when it is called — `defaultSort()` may run before `columns()`. Naming a column the table does not have throws `PanelSchemaException::unknownDefaultSort()`. See [Sorting](sorting.md) and [Search](search.md).

## Selection and actions

```php
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Enums\RecordActionsPosition;

$table
    ->recordActions([
        ViewAction::make(PostResource::class),
        EditAction::make(PostResource::class),
        DeleteAction::make(PostResource::class),
    ])
    ->recordActionsPosition(RecordActionsPosition::AfterColumns)
    ->recordActionsLabel('Manage')
    ->bulkActions([DeleteBulkAction::make(PostResource::class)]);
```

Passing a non-empty array to `bulkActions()` sets `selectable(true)`, because a bulk action with no way to select would be useless. `selectable()` exists on its own for a table that wants checkboxes without bulk actions. Every action set rejects duplicate names, and rejects an action with no handler, no URL, no form and no modal — `PanelSchemaException::inertAction()`.

See [Record actions](record-actions.md), [Bulk actions](bulk-actions.md), and [Header and toolbar actions](toolbar-actions.md).

## Rows per page

```php
$table->perPageOptions([10, 25, 50, 100])->defaultPerPage(25);
```

`perPageOptions()` drops anything that is not positive, de-duplicates, and sorts. A `?perPage=` outside the list is ignored, and `defaultPerPage()` falls back to the first option if it is not one of them. See [Pagination](pagination.md).

## Grouping and tabs

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Group;

$table
    ->groups([
        Group::make('status')->titleUsing(static fn (Model $r): string => ucfirst($r->status)),
    ])
    ->defaultGroup('status');
```

Grouping bands rows under headings; it does not change which records the query returns. Tabs are declared on the list page rather than the schema, because a tab is a named scope on the resource's own query. See [Grouping](grouping.md) and [Tabs](tabs.md).

## The empty state

```php
use PandaPanel\Actions\CreateAction;

$table
    ->emptyState(
        heading: 'No posts match this view',
        description: 'Adjust the search or filters, or write one.',
        icon: 'file-text',
    )
    ->emptyStateActions([CreateAction::modal(PostResource::class)])
    ->emptyStateComponent('Panels/Admin/EmptyStates/NoPosts');
```

The heading defaults to `No records found`. `emptyStateComponent()` names a build-time registry key under `resources/js/pages/Panels/{Panel}/EmptyStates/`, never markup.

## Column manager, persistence, reordering, freezing

```php
$table
    ->reorderableColumns()
    ->columnManagerInModal()
    ->persistColumnsInSession()
    ->persistSearchInSession()
    ->persistSortInSession()
    ->persistFiltersInSession()
    ->reorderable('position')
    ->frozenActions();
```

`reorderableColumns()` lets the user rearrange *columns*; `reorderable('position')` lets the user drag *rows* and writes the arranged order to that column, fixing the default sort to it ascending. See [Column manager](column-manager.md), [Persisted table state](persisted-state.md), [Reordering](reordering.md), and [Frozen and pinned columns](pinned-columns.md).

## Card layout

```php
$table->cards();
```

The same records, drawn as a grid of cards instead of rows — one schema and two renderers, sharing the query, the filters, the search and the pagination. The face is an arrangement of the columns the table already declares, inferred when nothing is said and declarable slot by slot when it matters. A toggle appears in the toolbar, and the choice is remembered alongside the column arrangement. See [Card layout](card-layout.md).

## Every `TableSchema` method

Builder methods all return `self`.

| Method | Default | Page |
| --- | --- | --- |
| `make(): self` | — | this page |
| `columns(array $columns)` | `[]` | [Columns](columns.md) |
| `filters(array $filters)` | `[]` | [Filters](filters.md) |
| `groups(array $groups)` | `[]` | [Grouping](grouping.md) |
| `defaultGroup(string $name)` | `null` | [Grouping](grouping.md) |
| `defaultSort(string $column, SortDirection $direction = SortDirection::Descending)` | none, `Descending` | [Sorting](sorting.md) |
| `defaultSortOptionLabel(string $label)` | `null` | [Sorting](sorting.md) |
| `perPageOptions(array $options)` | `[10, 25, 50, 100]` | [Pagination](pagination.md) |
| `defaultPerPage(int $perPage)` | `25` | [Pagination](pagination.md) |
| `searchPlaceholder(string $placeholder)` | `Search...` | [Search](search.md) |
| `searchDebounce(int $milliseconds)` | `300`, clamped to `>= 0` | [Search](search.md) |
| `searchOnBlur(bool $onBlur = true)` | `false` | [Search](search.md) |
| `splitSearchTerms(bool $split = true)` | `true` | [Search](search.md) |
| `persistSearchInSession(bool $persist = true)` | `false` | [Persisted state](persisted-state.md) |
| `persistSortInSession(bool $persist = true)` | `false` | [Persisted state](persisted-state.md) |
| `persistFiltersInSession(bool $persist = true)` | `false` | [Persisted state](persisted-state.md) |
| `persistColumnsInSession(bool $persist = true)` | `false` | [Column manager](column-manager.md) |
| `deferFilters(bool $defer = true)` | `false` | [Filters](filters.md) |
| `filtersTrigger(string $label, ?string $icon = null)` | `Filters`, `null` | [Filters](filters.md) |
| `filtersApplyLabel(string $label)` | `Apply filters` | [Filters](filters.md) |
| `filtersResetLabel(string $label)` | `Clear` | [Filters](filters.md) |
| `showFiltersResetAction(bool $show = true)` | `true` | [Filters](filters.md) |
| `reorderableColumns(bool $reorderable = true)` | `false` | [Column manager](column-manager.md) |
| `deferColumnManager(bool $defer = true)` | `false` | [Column manager](column-manager.md) |
| `columnManagerTrigger(string $label, ?string $icon = null)` | `Columns`, `null` | [Column manager](column-manager.md) |
| `columnManagerInModal(bool $inModal = true)` | `false` | [Column manager](column-manager.md) |
| `showColumnManagerReset(bool $show = true)` | `true` | [Column manager](column-manager.md) |
| `emptyState(string $heading, ?string $description = null, ?string $icon = null)` | `No records found` | this page |
| `emptyStateComponent(string $component)` | `null` | this page |
| `selectable(bool $selectable = true)` | `false` | [Bulk actions](bulk-actions.md) |
| `reorderable(string $column)` | off | [Reordering](reordering.md) |
| `cards(?CardLayout $layout = null)` | not declared | [Card layout](card-layout.md) |
| `defaultLayout(TableLayout $layout)` | `TableLayout::Table` | [Card layout](card-layout.md) |
| `recordActions(array $actions)` | `[]` | [Record actions](record-actions.md) |
| `recordActionsPosition(RecordActionsPosition $position)` | `AfterColumns` | [Record actions](record-actions.md) |
| `recordActionsLabel(string $label)` | `null` | [Record actions](record-actions.md) |
| `bulkActions(array $actions)` | `[]`, turns selection on | [Bulk actions](bulk-actions.md) |
| `headerActions(array $actions)` | `[]` | [Toolbar actions](toolbar-actions.md) |
| `toolbarActions(array $actions)` | `[]` | [Toolbar actions](toolbar-actions.md) |
| `emptyStateActions(array $actions)` | `[]` | [Toolbar actions](toolbar-actions.md) |
| `frozenActions(bool $frozen = true)` | `false` | [Pinned columns](pinned-columns.md) |

Read-side methods, for code that inspects a schema rather than builds one:

```php
$schema->getColumns();                    // list<Column>
$schema->getColumn('title');              // ?Column
$schema->getSortableColumns();            // list<Column>
$schema->getIndividuallySearchableColumns();
$schema->getIndividuallySearchableColumn('title');
$schema->getSearchColumns();              // list<string>, local columns only
$schema->getSearchRelations();            // list<string>, dotted names
$schema->isSearchable();                  // bool
$schema->columnNames();                   // list<string>
$schema->defaultVisibleColumnNames();     // list<string>
$schema->toggleableColumnNames();         // list<string>
$schema->getFilters();                    // list<Filter>
$schema->getFilter('status');             // ?Filter
$schema->defaultFilters();                // array<string, mixed>
$schema->getGroups();                     // list<Group>
$schema->getGroup('status');              // ?Group
$schema->getDefaultGroup();               // ?string
$schema->getDefaultSortColumn();          // ?string
$schema->getDefaultSortDirection();       // SortDirection
$schema->getPerPageOptions();             // list<int>
$schema->getDefaultPerPage();             // int, clamped to the options
$schema->isSelectable();                  // bool
$schema->isReorderable();                 // bool
$schema->getReorderColumn();              // ?string
$schema->hasFrozenStart();                // bool
$schema->hasFrozenActions();              // bool
$schema->hasSummaries();                  // bool
$schema->getRecordActions();              // list<Action>
$schema->getRecordAction('approve');      // ?Action, column actions included
$schema->getBulkActions();
$schema->getBulkAction('delete');
$schema->getHeaderActions();
$schema->getToolbarActions();
$schema->getEmptyStateActions();
$schema->getTableAction('export');        // ?Action, across all three bars
```

And the three methods that produce a payload:

```php
$schema->applyColumnQueries($query);                      // void; aggregates into the select
$schema->summaries($query, $records);                     // array<string, list<array>>
$schema->groupSummaries($query, $records, $group);        // array<string, array<string, list<array>>>
$schema->toRow($record, $group = null);                   // one serialized row
$schema->toArray();                                       // the definition sent to Vue
```

## What the frontend receives

`toArray()` returns no closures, no query, and no model class. The top-level keys are:

| Key | Shape |
| --- | --- |
| `columns` | list of column definitions, discriminated by `type` |
| `filters` | list of filter definitions, discriminated by `type` |
| `groups`, `defaultGroup` | the ways rows can be banded |
| `columnManager` | `reorderable`, `deferred`, `triggerLabel`, `triggerIcon`, `resetLabel`, `showReset`, `modal`, `toggleable` |
| `filterBehaviour` | `deferred`, `triggerLabel`, `triggerIcon`, `applyLabel`, `resetLabel`, `showReset` |
| `searchable`, `searchPlaceholder`, `searchDebounce`, `searchOnBlur`, `individualSearchColumns` | search behaviour |
| `selectable`, `reorderable` | row selection and row dragging |
| `frozen` | `{ start: bool, actions: bool }` |
| `perPageOptions`, `defaultPerPage` | pagination |
| `defaultSort` | `{ column, direction, label }` or `null` |
| `bulkActions`, `headerActions`, `toolbarActions` | serialized actions, already authorized with no record |
| `recordActions` | `{ position, label }`; the buttons themselves ride on each row |
| `emptyState` | `heading`, `description`, `icon`, `component`, `actions` |

## Gotchas

- **The schema is rebuilt per request, and it holds resolved state.** Do not cache a `TableSchema` instance between requests or share one across two assertions in a test.
- **`defaultSort()` throws late.** The check runs in `toArray()`, so a bad column name surfaces when the page renders rather than when the line is written.
- **`defaultPerPage()` is clamped.** Setting `20` without adding it to `perPageOptions()` silently uses the first declared option instead.
- **A column that is not `toggleable()` can never be hidden**, however the request asks. That is what keeps the identifying column on screen.
- **Filters never narrow a record lookup.** They live in `TableQuery::paginate()`, not in `Resource::query()`, so a record filtered off the list is still openable by URL.
- **Search terms are escaped.** `%` and `_` in a search box match literally rather than as LIKE wildcards.
- **Table state is namespaced when a page carries more than one table.** A relation manager uses `relations.{key}`, a table widget uses `widgets.{widget-id}`. Without a namespace, two tables would share one `?page=`.

## See also

- [Columns](columns.md)
- [Search](search.md) and [Sorting](sorting.md)
- [Filters](filters.md), [Query builder filters](query-builder.md), [Tabs](tabs.md)
- [Grouping](grouping.md) and [Summaries](summaries.md)
- [Pagination](pagination.md) and [Persisted table state](persisted-state.md)
- [Column manager](column-manager.md), [Frozen and pinned columns](pinned-columns.md), [Editable columns](editable-columns.md)
- [Record actions](record-actions.md), [Bulk actions](bulk-actions.md), [Header and toolbar actions](toolbar-actions.md), [Reordering](reordering.md)
- [Relationship columns](relationships.md) and [Array data tables](array-data.md)
- [Table API reference](api.md)
- [Creating resources](../resources/creating-resources.md)
- [Actions](../actions/overview.md)
