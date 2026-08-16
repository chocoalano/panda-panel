# Tables Reference

`PandaPanel\Tables\TableSchema` and everything it holds: columns, filters, groups, tabs, summaries, and the `TableQuery` that turns a URL into a constrained query. The URL is the table's state — page, per-page, search, sort, direction, filters — so back, forward, refresh, and bookmark all behave.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Tables\TableSchema` | The declarative description of a table |
| `PandaPanel\Tables\TableQuery` | Applies URL state to an Eloquent query |
| `PandaPanel\Tables\ArrayTableData` | The same table over records that are not in a database |
| `PandaPanel\Tables\Group` | One way of banding rows |
| `PandaPanel\Tables\Tab` | One filter tab above a list page |
| `PandaPanel\Tables\Columns\Column` | The base of every column |
| `PandaPanel\Tables\Columns\EditableColumn` | The base of every writable column |
| `PandaPanel\Tables\Columns\Concerns\HasRelationshipState` | Aggregates and relation sorting, on every column |
| `PandaPanel\Tables\Filters\Filter` | The base of every filter |
| `PandaPanel\Tables\Filters\Constraints\Constraint` | One column a query builder may constrain |
| `PandaPanel\Tables\Summaries\Summarizer` | One figure under a column |
| `PandaPanel\Tables\Enums\*` | The closed sets that cross into Vue |

## A table that runs

```php
<?php

namespace App\Panels\Admin\Resources\Users\Tables;

use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Tables\Columns\BooleanColumn;
use PandaPanel\Tables\Columns\DateColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\TableSchema;

final class UsersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                BooleanColumn::make('is_admin')->label('Admin'),
                DateColumn::make('created_at')->label('Joined')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_admin')->label('Administrator'),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->recordActions([
                EditAction::make(UserResource::class),
                DeleteAction::make(UserResource::class),
            ]);
    }
}
```

```php
public static function table(TableSchema $table): TableSchema
{
    return UsersTable::configure($table);
}
```

## `TableSchema`

`final class`. Every builder method returns `self`.

### Content

```php
public static function make(): self;
public function columns(array $columns): self;     // array<array-key, Column>
public function filters(array $filters): self;     // array<array-key, Filter>
public function groups(array $groups): self;       // array<array-key, Group>
public function defaultGroup(string $name): self;
```

`columns()` and `filters()` throw `PandaPanel\Exceptions\PanelSchemaException` on a duplicate name — a column name is the key its cell, visibility, search term, and sort all live under, and filter state travels in the query string keyed by name.

### Sorting, searching, paging

```php
public function defaultSort(string $column, SortDirection $direction = SortDirection::Descending): self;
public function defaultSortOptionLabel(string $label): self;   // null
public function perPageOptions(array $options): self;          // [10, 25, 50, 100]
public function defaultPerPage(int $perPage): self;            // 25
public function searchPlaceholder(string $placeholder): self;  // 'Search...'
public function searchDebounce(int $milliseconds): self;       // 300
public function searchOnBlur(bool $onBlur = true): self;       // false
public function splitSearchTerms(bool $split = true): self;    // true
```

`splitSearchTerms()` on means every word must match somewhere rather than the whole string matching one column: `ada lovelace` across a first-name and a last-name column finds nothing as one string and the obvious record as two words.

A default sort naming a column the table does not declare throws `PanelSchemaException::unknownDefaultSort()` — checked by `toArray()` rather than by the setter, because `defaultSort()` may legitimately run before `columns()`. The table would otherwise fall back to its natural order with nothing to say why.

### Persistence and deferral

```php
public function persistSearchInSession(bool $persist = true): self;    // false
public function persistSortInSession(bool $persist = true): self;      // false
public function persistFiltersInSession(bool $persist = true): self;   // false
public function persistColumnsInSession(bool $persist = true): self;   // false
public function deferFilters(bool $defer = true): self;                // false
public function deferColumnManager(bool $defer = true): self;          // false
```

The request wins whenever a parameter is *present*, including when it says the value is now empty. Absence is the only case that falls back to what was stored, so clearing a search is not undone by the thing that remembered it.

### Filter bar and column manager

```php
public function filtersTrigger(string $label, ?string $icon = null): self;        // 'Filters', null
public function filtersApplyLabel(string $label): self;                          // 'Apply filters'
public function filtersResetLabel(string $label): self;                          // 'Clear'
public function showFiltersResetAction(bool $show = true): self;                 // true
public function reorderableColumns(bool $reorderable = true): self;              // false
public function columnManagerTrigger(string $label, ?string $icon = null): self; // 'Columns', null
public function columnManagerInModal(bool $inModal = true): self;                // false
public function showColumnManagerReset(bool $show = true): self;                 // true
```

The column manager's reset label is fixed at `Reset`; only its visibility is configurable.

### Rows, selection, actions

```php
public function selectable(bool $selectable = true): self;                     // false
public function reorderable(string $column): self;                             // also defaultSort($column, Ascending)
public function recordActions(array $actions): self;
public function recordActionsPosition(RecordActionsPosition $position): self;  // AfterColumns
public function recordActionsLabel(string $label): self;                       // null
public function bulkActions(array $actions): self;                             // sets selectable(true) when non-empty
public function headerActions(array $actions): self;
public function toolbarActions(array $actions): self;
public function emptyStateActions(array $actions): self;
public function frozenActions(bool $frozen = true): self;                      // false
```

Every action setter rejects duplicate names (`PanelSchemaException::duplicateActions()`) and inert actions — one with no URL, no handler, no form, and no modal (`inertAction()`), which would otherwise render a button that responds to being pressed by doing nothing.

### Empty state

```php
public function emptyState(string $heading, ?string $description = null, ?string $icon = null): self;
public function emptyStateComponent(string $component): self;   // null
```

The heading defaults to `No records found`.

### Reading a schema

```php
public function getColumns(): array;                 // list<Column>
public function getColumn(string $name): ?Column;
public function columnNames(): array;
public function defaultVisibleColumnNames(): array;
public function toggleableColumnNames(): array;
public function getSortableColumns(): array;
public function getSearchColumns(): array;           // local columns only
public function getSearchRelations(): array;         // dotted paths
public function isSearchable(): bool;
public function getIndividuallySearchableColumns(): array;
public function getIndividuallySearchableColumn(string $name): ?Column;

public function getFilters(): array;
public function getFilter(string $name): ?Filter;
public function defaultFilters(): array;
public function persistsFiltersInSession(): bool;
public function defersFilters(): bool;
public function persistsSearchInSession(): bool;
public function persistsSortInSession(): bool;
public function persistsColumnsInSession(): bool;
public function hasReorderableColumns(): bool;
public function shouldSplitSearchTerms(): bool;

public function getGroups(): array;
public function getGroup(string $name): ?Group;
public function getDefaultGroup(): ?string;

public function getDefaultSortColumn(): ?string;
public function getDefaultSortDirection(): SortDirection;
public function getPerPageOptions(): array;
public function getDefaultPerPage(): int;

public function isSelectable(): bool;
public function isReorderable(): bool;
public function getReorderColumn(): ?string;
public function hasFrozenActions(): bool;
public function hasFrozenStart(): bool;

public function getRecordActions(): array;
public function getBulkActions(): array;
public function getHeaderActions(): array;
public function getToolbarActions(): array;
public function getEmptyStateActions(): array;
public function getRecordAction(string $name): ?Action;
public function getBulkAction(string $name): ?Action;
public function getTableAction(string $name): ?Action;

public function hasSummaries(): bool;
public function applyColumnQueries(Builder $query): void;
```

The `get*Action()` lookups are the whitelists the action endpoint resolves against. An action not declared here does not exist, however the request spells it.

### Producing a payload

```php
public function toArray(): array;                                   // the table definition
public function toRow(Model $record, ?Group $group = null): array;  // one row
public function summaries(Builder $query, array $records): array;
public function groupSummaries(Builder $query, array $records, Group $group): array;
```

`toRow()` returns `{key, group, cells, cellMeta, actions}`. Cell metadata sits beside the cells rather than inside them, so a cell value keeps exactly the shape its renderer expects.

## `TableQuery`

`final readonly class`.

```php
public function __construct(
    TableSchema $schema,
    Request $request,
    ?string $namespace = null,
    ?string $sessionKey = null,
);

public function namespace(): ?string;
public function constrain(Builder $query): void;
public function paginate(Builder $query): LengthAwarePaginator;
public function paginateRelation(Relation $relation): LengthAwarePaginator;
public function activeGroup(): ?Group;
public function state(): array;
```

```php
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;

$schema = UserResource::table(TableSchema::make());

$query = new TableQuery(
    $schema,
    $request,
    sessionKey: 'panel.admin.table.users',
);

$records = $query->paginate(UserResource::query());
$rows    = array_map(fn ($record) => $schema->toRow($record), $records->items());
$state   = $query->state();
```

`constrain()` applies, in order: column queries (aggregates and relation sorts), base-query filters, the global search, the per-column searches, the filters, and the sort. `paginate()` calls it and paginates with `withQueryString()`.

This layer never *starts* a query — it receives the resource's own, so tenant, module, and permission scopes stay applied.

### Query parameters

Every one is validated against the schema before it touches the builder. An unknown sort column, an out-of-range per-page, or an unrecognised filter is ignored rather than passed through.

| Parameter | Validated against |
| --- | --- |
| `page` | `max(1, (int) $value)` |
| `perPage` | `perPageOptions()`, else the default |
| `search` | trimmed, truncated to 255 characters |
| `columnSearch[{column}]` | columns declared `searchable(individually: true)` |
| `sort` | columns declared `sortable()` |
| `direction` | `SortDirection`, unknown becomes ascending |
| `filters[{name}]` | each filter's own `sanitize()` |
| `group` | declared groups |
| `columns` | declared column names; a column that is not toggleable is put back into `visible` whatever the request said |

### `state()`

```php
[
    'search' => ?string,
    'sort' => ?string,
    'direction' => 'asc'|'desc',
    'perPage' => int,
    'filters' => array<string, mixed>,
    'filterIndicators' => array<string, string>,
    'columnSearches' => array<string, string>,
    'columns' => array{visible: list<string>, order: list<string>},
    'group' => ?string,
]
```

### Namespaces

A namespace moves the whole state under one query-string key:

```php
new TableQuery($schema, $request, namespace: 'relations.tasks');
// ?relations[tasks][sort]=title&relations[tasks][page]=2
```

A record page can carry several relation tables at once, and each has to sort and paginate without touching the others — a shared `?page=` would move all of them together.

## `Column`

`abstract class`. Every setter returns `static`.

### Identity and behaviour

```php
public static function make(string $name): static;
public function label(string $label): static;                 // Str::headline($name)
public function sortable(bool $sortable = true, ?string $column = null): static;   // false
public function searchable(bool $searchable = true, ?array $columns = null, bool $individually = false): static;
public function sortUsing(Closure $callback): static;
public function visible(bool $visible = true): static;        // true
public function toggleable(bool $toggleable = true): static;  // true
```

`searchable()` with `$columns` searches other columns instead of this one; a dotted entry searches a relation. `individually: true` also gives the column its own search box.

### Presentation

```php
public function alignment(Alignment|string $alignment): static;        // Start
public function headerAlignment(Alignment|string $alignment): static;  // follows alignment
public function placeholder(string $placeholder): static;              // null
public function default(mixed $default): static;                       // null
public function tooltip(Closure|string $tooltip): static;
public function headerTooltip(string $tooltip): static;
public function wrapHeader(bool $wrap = true): static;                 // false
public function width(string $width): static;                          // null
public function frozen(ColumnPin|bool $pin = true): static;            // null
public function extraAttributes(Closure|array $attributes): static;
public function formatUsing(Closure $callback): static;
```

`width()` is a CSS length, because it becomes a style rather than a class the compiler has to have seen. `frozen(true)` pins to the start; pass `ColumnPin::End` for the other side.

### Links, actions, summaries

```php
public function url(Closure $callback): static;
public function action(Action $action): static;
public function getAction(): ?Action;
public function summarize(array $summarizers): static;
public function getSummarizers(): array;
public function hasSummaries(): bool;
public function summaryColumn(): string;
```

### Reading

```php
public function getName(): string;
public function getLabel(): string;
public function isSortable(): bool;
public function isVisible(): bool;
public function isToggleable(): bool;
public function isSearchable(): bool;
public function isIndividuallySearchable(): bool;
public function hasCustomSort(): bool;
public function getSortColumn(): string;
public function getSearchColumns(): array;
public function getFrozen(): ?ColumnPin;
public function resolveValue(Model $record): mixed;
public function toCellMeta(Model $record): ?array;
public function applyCustomSort(Builder $query, SortDirection $direction): void;
public function toArray(): array;
abstract public function type(): ColumnType;
```

## Column types

| Class | `ColumnType` | Own methods | Notable defaults |
| --- | --- | --- | --- |
| `TextColumn` | `Text` | `limit(int)`, `wrap(bool = true)` | — |
| `NumberColumn` | `Number` | `decimals(int)`, `prefix(string)`, `suffix(string)` | `decimals` 0, aligned `End` |
| `BadgeColumn` | `Badge` | `colors(array)`, `labels(array)` | — |
| `BooleanColumn` | `Boolean` | `labels(string $true, string $false)` | `Yes`/`No`, aligned `Center` |
| `DateColumn` | `Date` | `format(string)`, `relative(bool = true)` | `'M j, Y'` |
| `DateTimeColumn` | `DateTime` | *(extends `DateColumn`)* | `'M j, Y H:i'` |
| `ImageColumn` | `Image` | `circular(bool = true)`, `size(int)`, `fallbackUsing(Closure)` | 32 px, square |
| `IconColumn` | `Icon` | `icons(array)`, `colors(array)`, `iconUsing(Closure)`, `boolean(string $true = 'check', string $false = 'x')` | aligned `Center` |
| `ColorColumn` | `Color` | `copyable(bool = true)` | false |
| `CustomColumn` | `Custom` | `component(string)`, `state(Closure)` | — |
| `TextInputColumn` | `TextInput` | `numeric()`, `maxLength(int)` | writable |
| `SelectColumn` | `Select` | `options(array)` | writable |
| `ToggleColumn` | `Toggle` | — | writable, aligned `Center` |
| `CheckboxColumn` | `Checkbox` | — | writable, aligned `Center` |

```php
use PandaPanel\Tables\Enums\BadgeColor;

BadgeColumn::make('status')
    ->colors(['live' => BadgeColor::Success, 'draft' => BadgeColor::Neutral])
    ->labels(['live' => 'Live', 'draft' => 'Draft']);

DateColumn::make('created_at')->format('Y-m-d')->relative();

IconColumn::make('is_admin')->boolean('shield', 'x');

CustomColumn::make('account_age')
    ->component('Panels/Admin/Columns/AccountAge')  // the path below resources/js/pages/, no extension
    ->state(fn (Model $record): int => $record->created_at->diffInDays());
```

## `EditableColumn`

The base of the four writable columns. A cell write posts to the panel's `actions.cell` endpoint, which is an action in every sense that matters: it names a record, authorizes it, and changes it.

```php
public function rules(array $rules): static;
public function disabledUsing(Closure $callback): static;
public function mutateUsing(Closure $callback): static;
public function updateUsing(Closure $callback): static;
public function writeTo(string $attribute): static;
public function getWriteAttribute(): string;
public function isDisabledFor(Model $record): bool;
public function validationRules(): array;
public function write(Model $record, mixed $value): void;
```

```php
ToggleColumn::make('is_published')
    ->disabledUsing(fn (Model $record): bool => $record->archived_at !== null);

TextInputColumn::make('sku')
    ->rules(['string', 'max:32'])
    ->writeTo('stock_keeping_unit');

SelectColumn::make('status')
    ->options(['draft' => 'Draft', 'live' => 'Live'])
    ->updateUsing(function (mixed $value, Model $record): void {
        app(PublishPost::class)->run($record, $value);
    });
```

## `HasRelationshipState`

A trait on `Column`, so every column has it.

```php
public function counts(string $relation): static;
public function exists(string $relation): static;
public function sum(string $relation, string $column): static;
public function avg(string $relation, string $column): static;
public function min(string $relation, string $column): static;
public function max(string $relation, string $column): static;
public function sortableByRelation(string $relation, string $column): static;

public function getAggregateRelation(): ?string;
public function getSortRelation(): ?string;
public function aggregateAttribute(): ?string;
public function applyQuery(Builder $query): void;
public function applyRelationshipSort(Builder $query, SortDirection $direction): void;
public function summaryUsesAggregate(): bool;
```

```php
NumberColumn::make('posts')->counts('posts')->sortable();
NumberColumn::make('revenue')->sum('orders', 'total')->decimals(2);
TextColumn::make('author')->sortableByRelation('author', 'name');
```

The aggregate is added to the query with `withCount` / `withSum` and read back off the generated attribute, so no row triggers a query of its own.

`RelationshipAggregate` is `Count`, `Exists`, `Sum`, `Avg`, `Min`, `Max`.

## Filters

`abstract class Filter`.

```php
public static function make(string $name): static;
public function label(string $label): static;                 // Str::headline($name)
public function column(string $column): static;               // defaults to the name
public function query(Closure $callback): static;
public function modifyBaseQueryUsing(Closure $callback): static;
public function default(mixed $value): static;
public function hasDefault(): bool;
public function getDefault(): mixed;
public function apply(Builder $query, mixed $value): void;
public function applyBaseQuery(Builder $query, mixed $value): void;
public function indicator(mixed $value): ?string;
public function toArray(): array;
abstract public function type(): FilterType;
abstract public function sanitize(mixed $value): mixed;
```

`sanitize()` is the whitelist: whatever the query string says, a filter decides what a usable value is and returns `null` for anything else.

| Class | `FilterType` | Own methods |
| --- | --- | --- |
| `SelectFilter` | `Select` | `options(array)`, `placeholder(string)` |
| `BooleanFilter` | `Boolean` | `labels(string $true, string $false)`, `nullable(bool = true)` |
| `TernaryFilter` | `Ternary` | `labels(string $true, string $false, ?string $blank = null)`, `nullable(bool = true)`, `queries(Closure $true, Closure $false)` |
| `DateFilter` | `Date` | — |
| `TrashedFilter` | `Select` | — (three fixed options: `without`, `with`, `only`) |
| `FormFilter` | `Form` | `form(Closure)`, `schema()` |
| `QueryBuilderFilter` | `QueryBuilder` | `constraints(array)`, `maxRules(int)`, `constraint(string)` |

`TernaryFilter`'s blank label defaults to `All`; `BooleanFilter` and `TernaryFilter` use `Yes`/`No`.

```php
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\Filters\TrashedFilter;

SelectFilter::make('status')
    ->options(['draft' => 'Draft', 'live' => 'Live'])
    ->placeholder('Any status')
    ->default('live');

TernaryFilter::make('verified')
    ->labels('Verified', 'Unverified')
    ->queries(
        function (Builder $query): void {
            $query->whereNotNull('email_verified_at');
        },
        function (Builder $query): void {
            $query->whereNull('email_verified_at');
        },
    );

// Labelled 'Deleted records', with Hidden / Included / Only deleted.
// Needs $softDeletes on the resource to reach anything.
TrashedFilter::make('trashed');
```

A `FormFilter` carries a whole `FormSchema`, so a date range or a pair of numbers is one filter:

```php
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Filters\FormFilter;

FormFilter::make('created')
    ->form(fn (FormSchema $schema): FormSchema => $schema->schema([
        DatePicker::make('from'),
        DatePicker::make('until'),
    ]))
    ->query(function (Builder $query, mixed $value): void {
        $query
            ->when($value['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($value['until'] ?? null, fn ($q, $until) => $q->whereDate('created_at', '<=', $until));
    });
```

### Constraints

A `QueryBuilderFilter` is built from constraints, each naming one column and the operators it supports.

```php
abstract class Constraint
{
    public static function make(string $name): static;
    public function label(string $label): static;
    public function column(string $column): static;
    public function getName(): string;
    public function getLabel(): string;
    public function getColumn(): string;
    public function supports(ConstraintOperator $operator): bool;
    public function accepts(ConstraintOperator $operator, mixed $value): bool;
    public function apply(Builder $query, ConstraintOperator $operator, mixed $value): void;
    public function toArray(): array;
    abstract public function inputType(): string;
    abstract public function operators(): array;
}
```

`TextConstraint`, `NumberConstraint`, `DateConstraint`, `BooleanConstraint`.

```php
use PandaPanel\Tables\Filters\Constraints\NumberConstraint;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\QueryBuilderFilter;

QueryBuilderFilter::make('query')
    ->maxRules(5)                 // 10 by default
    ->constraints([
        TextConstraint::make('name'),
        NumberConstraint::make('orders_count')->label('Orders'),
    ]);
```

`ConstraintOperator`: `Contains`, `DoesNotContain`, `StartsWith`, `EndsWith`, `EqualTo`, `NotEqualTo`, `GreaterThan`, `GreaterThanOrEqual`, `LessThan`, `LessThanOrEqual`, `IsFilled`, `IsBlank`, `IsTrue`, `IsFalse`. Each has `label()` and `needsValue()`.

## Summaries

```php
abstract class Summarizer
{
    public static function make(string $name = ''): static;
    public function label(string $label): static;
    public function formatUsing(Closure $callback): static;
    public function perPage(bool $perPage = true): static;   // false
    public function isPerPage(): bool;
    public function getName(): string;
    public function getLabel(): string;
    public function summarize(QueryBuilder $query, string $column): mixed;
    public function summarizeRecords(array $records, string $column): mixed;
    public function format(mixed $value): string;
    public function toArray(mixed $value): array;
    abstract public function aggregate(): ?string;
}
```

`Count`, `Sum`, `Average`, `Range`.

```php
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Range;
use PandaPanel\Tables\Summaries\Sum;

NumberColumn::make('total')
    ->decimals(2)
    ->summarize([
        Sum::make()->label('Total'),
        Average::make()->label('Average')->formatUsing(fn (mixed $v): string => number_format((float) $v, 2)),
        Range::make()->label('Range'),
    ]);
```

Figures are computed by the database over the filtered query, not by adding up the rows on screen — a page total that changed when you paged would be a different number wearing the same label. A summarizer that wants the page says `perPage()` and gets exactly the records shown.

## `Group`

```php
public static function make(string $name): self;
public function label(string $label): self;
public function column(string $column): self;                 // defaults to the name
public function direction(SortDirection $direction): self;    // Ascending
public function titleUsing(Closure $callback): self;
public function descriptionUsing(Closure $callback): self;
public function getName(): string;
public function getLabel(): string;
public function getColumn(): string;
public function keyFor(Model $record): string;
public function titleFor(Model $record): string;
public function descriptionFor(Model $record): ?string;
public function applySort(Builder $query): void;
public function toArray(): array;
```

```php
use PandaPanel\Tables\Group;

$table
    ->groups([
        Group::make('status')->titleUsing(fn (Model $r): string => ucfirst((string) $r->status)),
        Group::make('created_at')->label('Month')->direction(SortDirection::Descending),
    ])
    ->defaultGroup('status');
```

The band key, title, and description are all resolved per record on the server, so the frontend never has to know how a key becomes a name.

## `Tab`

One filter tab above a list page, declared by `ListRecords::tabs()`.

```php
public static function make(string $key, ?string $label = null): self;
public function query(Closure $callback): self;            // Closure(Builder): Builder
public function badge(string|int|Closure|null $badge): self;
public function icon(?string $icon): self;
public function getLabel(): string;                        // Str::headline($key)
public function apply(Builder $query): Builder;
public function resolveBadge(): string|int|null;
public function toArray(bool $active): array;
public readonly string $key;
```

```php
public function tabs(): array
{
    return [
        'all' => Tab::make('all')->badge(fn (): int => User::query()->count()),
        'admins' => Tab::make('admins', 'Administrators')
            ->icon('shield')
            ->query(fn (Builder $query): Builder => $query->where('is_admin', true)),
    ];
}
```

A tab is a named scope on the resource's own query, never a query of its own, so a tenant or permission scope still applies to whatever it shows.

## `ArrayTableData`

The same schema over records that are not in a database — a config file, an API response, a computed report.

```php
public static function make(TableSchema $schema, iterable $records, Request $request, ?string $namespace = null): self;
public function paginate(): LengthAwarePaginator;
public function state(): array;
public function pagination(LengthAwarePaginator $records): array;
public function rows(LengthAwarePaginator $records): array;
public function sortableColumns(): array;
```

```php
use Illuminate\Http\Request;
use PandaPanel\Tables\ArrayTableData;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

$schema = TableSchema::make()->columns([
    TextColumn::make('city')->searchable()->sortable(),
    TextColumn::make('temperature')->sortable(),
]);

$data = ArrayTableData::make(
    $schema,
    collect($readings)->map(fn (array $row) => Reading::make($row)),
    request(),
);

$records = $data->paginate();

return Inertia::render('Panels/Admin/Pages/Weather', [
    'table' => $schema->toArray(),
    'state' => $data->state(),
    'rows' => $data->rows($records),
    'pagination' => $data->pagination($records),
]);
```

Search, sort, and paging are applied in memory against the declared columns. The columns, the serialization, and the row shape are the table builder's own; only where the rows come from differs.

## Enums

| Enum | Cases |
| --- | --- |
| `ColumnType` | `Text`, `Number`, `Badge`, `Boolean`, `Date`, `DateTime`, `Image`, `Icon`, `Color`, `Custom`, `Toggle`, `Checkbox`, `TextInput`, `Select` |
| `SortDirection` | `Ascending` (`asc`), `Descending` (`desc`); `fromRequest()`, `opposite()` |
| `Alignment` | `Start`, `Center`, `End`, `Justify`; `fromRequest()` |
| `BadgeColor` | `Neutral`, `Success`, `Warning`, `Danger`, `Info`; `fromValue()` |
| `ColumnPin` | `Start`, `End` |
| `FilterType` | `Select`, `Boolean`, `Date`, `Ternary`, `Form`, `QueryBuilder` |
| `ConstraintOperator` | fourteen cases; `label()`, `needsValue()` |
| `RecordActionsPosition` | `AfterColumns`, `BeforeColumns`, `AfterCells` |
| `RelationshipAggregate` | `Count`, `Exists`, `Sum`, `Avg`, `Min`, `Max`; `attributeFor()`, `apply()` |

`BadgeColor` and `Alignment` are closed sets because each case maps to a literal Tailwind class the compiler has to have seen. A colour that is a *value* rather than a meaning belongs in `Panel::colors()`, which becomes a custom property instead.

## Endpoints a table uses

| Route name | Used by |
| --- | --- |
| `panel.{id}.actions.record` | a row action |
| `panel.{id}.actions.bulk` | a bulk action over the selection |
| `panel.{id}.actions.table` | a header, toolbar, or empty-state action |
| `panel.{id}.actions.reorder` | a drag-reorder writing back to `reorderable()`'s column |
| `panel.{id}.actions.cell` | an editable column's write |
| `panel.{id}.actions.form` / `actions.submit` | an action that carries a form |

## Notes

- **The URL is the state.** Nothing about a table lives only in component state, which is what makes a filtered list a link somebody can send.
- **Everything from the query string is validated against the schema.** An unknown sort column is ignored rather than passed to the builder, because the query string is user input.
- **Session persistence never skips validation.** A remembered sort column goes through the same whitelist a fresh one does, so a stale session cannot name a column the table no longer has.
- **A table can render without session middleware.** `persist*InSession()` becomes a no-op rather than a fatal, which is what makes the same schema usable from a console command.
- **`bulkActions()` turns selection on.** Declaring bulk actions and forgetting `selectable()` would ship checkboxes nobody could tick, so the schema does it.
- **Aggregates are added to the query, not computed per row.** `counts()` and friends go through `withCount` / `withSum`, so a hundred rows is still one query.
- **`reorderable($column)` also sets the default sort.** A reorderable table sorted by anything else would show an order the drag handle cannot change.

## See also

- [Tables overview](../tables/overview.md)
- [Columns](../tables/columns.md)
- [Filters](../tables/filters.md)
- [Query builder filter](../tables/query-builder.md)
- [Summaries](../tables/summaries.md)
- [Record actions](../tables/record-actions.md)
- [Toolbar actions](../tables/toolbar-actions.md)
- [Table API reference](../tables/api.md)
- [Actions reference](actions.md)
- [Resources reference](resources.md)
- [Forms reference](forms.md)
- [Exceptions reference](exceptions.md)
