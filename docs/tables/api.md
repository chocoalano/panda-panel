# Table API Reference

Every public member of the table classes, with its real signature and default. Use it to look something up; the pages linked from each section explain when to reach for it.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Tables\TableSchema` | The declarative description of a table |
| `PandaPanel\Tables\TableQuery` | Applies URL state to a query |
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

## `TableSchema`

`final class`. Every builder method returns `self`.

### Construction and content

```php
public static function make(): self;
public function columns(array $columns): self;                 // array<array-key, Column>
public function filters(array $filters): self;                 // array<array-key, Filter>
public function groups(array $groups): self;                   // array<array-key, Group>
public function defaultGroup(string $name): self;
```

`columns()` and `filters()` throw `PandaPanel\Exceptions\PanelSchemaException` on a duplicate name.

### Sorting, searching, paging

```php
public function defaultSort(string $column, SortDirection $direction = SortDirection::Descending): self;
public function defaultSortOptionLabel(string $label): self;   // null
public function perPageOptions(array $options): self;          // [10, 25, 50, 100]
public function defaultPerPage(int $perPage): self;            // 25
public function searchPlaceholder(string $placeholder): self;  // 'Search...'
public function searchDebounce(int $milliseconds): self;       // 300, clamped to >= 0
public function searchOnBlur(bool $onBlur = true): self;       // false
public function splitSearchTerms(bool $split = true): self;    // true
```

### Persistence and deferral

```php
public function persistSearchInSession(bool $persist = true): self;    // false
public function persistSortInSession(bool $persist = true): self;      // false
public function persistFiltersInSession(bool $persist = true): self;   // false
public function persistColumnsInSession(bool $persist = true): self;   // false
public function deferFilters(bool $defer = true): self;                // false
public function deferColumnManager(bool $defer = true): self;          // false
```

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
public function selectable(bool $selectable = true): self;                 // false
public function reorderable(string $column): self;                         // also sets defaultSort($column, Ascending)
public function recordActions(array $actions): self;
public function recordActionsPosition(RecordActionsPosition $position): self;   // AfterColumns
public function recordActionsLabel(string $label): self;                   // null
public function bulkActions(array $actions): self;                         // sets selectable(true) when non-empty
public function headerActions(array $actions): self;
public function toolbarActions(array $actions): self;
public function emptyStateActions(array $actions): self;
public function frozenActions(bool $frozen = true): self;                  // false
```

Each action setter rejects duplicate names and inert actions.

### Empty state

```php
public function emptyState(string $heading, ?string $description = null, ?string $icon = null): self;
public function emptyStateComponent(string $component): self;              // null
```

The heading defaults to `No records found`.

### Read-side

```php
public function getColumns(): array;                    // list<Column>
public function getColumn(string $name): ?Column;
public function columnNames(): array;                   // list<string>
public function defaultVisibleColumnNames(): array;     // list<string>
public function toggleableColumnNames(): array;         // list<string>
public function getSortableColumns(): array;            // list<Column>
public function getSearchColumns(): array;              // list<string>, local only
public function getSearchRelations(): array;            // list<string>, dotted
public function getIndividuallySearchableColumns(): array;
public function getIndividuallySearchableColumn(string $name): ?Column;
public function isSearchable(): bool;

public function getFilters(): array;                    // list<Filter>
public function getFilter(string $name): ?Filter;
public function defaultFilters(): array;                // array<string, mixed>
public function persistsFiltersInSession(): bool;
public function defersFilters(): bool;

public function getGroups(): array;                     // list<Group>
public function getGroup(string $name): ?Group;
public function getDefaultGroup(): ?string;

public function getDefaultSortColumn(): ?string;
public function getDefaultSortDirection(): SortDirection;
public function getPerPageOptions(): array;             // list<int>
public function getDefaultPerPage(): int;               // clamped to the options
public function shouldSplitSearchTerms(): bool;
public function persistsSearchInSession(): bool;
public function persistsSortInSession(): bool;
public function persistsColumnsInSession(): bool;
public function hasReorderableColumns(): bool;

public function isSelectable(): bool;
public function isReorderable(): bool;
public function getReorderColumn(): ?string;
public function hasFrozenStart(): bool;
public function hasFrozenActions(): bool;
public function hasSummaries(): bool;

public function getRecordActions(): array;              // list<Action>
public function getRecordAction(string $name): ?Action; // row actions and column actions
public function getBulkActions(): array;
public function getBulkAction(string $name): ?Action;
public function getHeaderActions(): array;
public function getToolbarActions(): array;
public function getEmptyStateActions(): array;
public function getTableAction(string $name): ?Action;  // header, toolbar, empty state
```

### Producing a payload

```php
public function applyColumnQueries(Builder $query): void;
public function summaries(Builder $query, array $records): array;
public function groupSummaries(Builder $query, array $records, Group $group): array;
public function toRow(Model $record, ?Group $group = null): array;
public function toArray(): array;
```

`toArray()` validates the default sort column and throws `PanelSchemaException::unknownDefaultSort()` when it is not a declared column.

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

`constrain()` applies, in order: column queries, base-query filters, the global search, the per-column searches, the filters, and the sort. `paginate()` calls it and then paginates with `withQueryString()`.

### Query parameters

Read from the namespace when there is one (`?relations[tasks][page]=2`).

| Parameter | Type | Validated against |
| --- | --- | --- |
| `page` | scalar | `max(1, (int) $value)` |
| `perPage` | scalar | `perPageOptions()`, else the default |
| `search` | scalar | trimmed, truncated to 255 characters |
| `columnSearch[{column}]` | map | columns with `searchable(individually: true)` |
| `sort` | scalar | columns with `sortable()` |
| `direction` | scalar | `SortDirection`, unknown becomes `asc` |
| `group` | scalar | declared groups; `''` turns grouping off |
| `filters` | map, or `''` | each filter's `sanitize()` |
| `columns[visible]`, `columns[order]` | lists | declared column names |

### `state()`

```php
[
    'search' => ?string,
    'sort' => ?string,
    'direction' => 'asc'|'desc',
    'perPage' => int,
    'filters' => array<string, mixed>,          // only the ones that sanitized
    'filterIndicators' => list<array{name: string, label: string}>,
    'columnSearches' => array<string, string>,
    'columns' => ['visible' => list<string>, 'order' => list<string>],
    'group' => ?string,
]
```

## `Column`

`abstract class`, constructor `final`. Every setter returns `static`.

```php
public static function make(string $name): static;    // throws on an empty name
abstract public function type(): ColumnType;
abstract public function toCell(Model $record): mixed;
```

| Method | Signature | Default |
| --- | --- | --- |
| `label` | `label(string $label): static` | `Str::headline($name)` |
| `sortable` | `sortable(bool $sortable = true, ?string $column = null): static` | `false` |
| `sortUsing` | `sortUsing(Closure $callback): static` | none; also sets sortable |
| `searchable` | `searchable(bool $searchable = true, ?array $columns = null, bool $individually = false): static` | `false` |
| `visible` | `visible(bool $visible = true): static` | `true` |
| `toggleable` | `toggleable(bool $toggleable = true): static` | `true` |
| `alignment` | `alignment(Alignment\|string $alignment): static` | `Alignment::Start` |
| `headerAlignment` | `headerAlignment(Alignment\|string $alignment): static` | follows `alignment` |
| `placeholder` | `placeholder(string $placeholder): static` | `null` |
| `default` | `default(mixed $default): static` | `null` |
| `tooltip` | `tooltip(Closure\|string $tooltip): static` | `null` |
| `headerTooltip` | `headerTooltip(string $tooltip): static` | `null` |
| `wrapHeader` | `wrapHeader(bool $wrap = true): static` | `false` |
| `width` | `width(string $width): static` | `null` |
| `frozen` | `frozen(ColumnPin\|bool $pin = true): static` | `null` |
| `extraAttributes` | `extraAttributes(Closure\|array $attributes): static` | `[]` |
| `url` | `url(Closure $callback): static` | `null` |
| `action` | `action(Action $action): static` | `null` |
| `formatUsing` | `formatUsing(Closure $callback): static` | `null` |
| `summarize` | `summarize(array $summarizers): static` | `[]` |

Read-side:

```php
public function getName(): string;
public function getLabel(): string;
public function isSortable(): bool;
public function isSearchable(): bool;
public function isIndividuallySearchable(): bool;
public function isVisible(): bool;
public function isToggleable(): bool;
public function hasCustomSort(): bool;
public function getSortColumn(): string;
public function getSearchColumns(): array;      // list<string>
public function getFrozen(): ?ColumnPin;
public function getAction(): ?Action;
public function getSummarizers(): array;        // list<Summarizer>
public function hasSummaries(): bool;
public function summaryColumn(): string;
public function resolveValue(Model $record): mixed;
public function applyCustomSort(Builder $query, SortDirection $direction): void;
public function toCellMeta(Model $record): ?array;
public function toArray(): array;
```

Closure signatures:

| Method | Closure |
| --- | --- |
| `sortUsing` | `fn (Builder $query, SortDirection $direction): void` |
| `formatUsing` | `fn (mixed $value, Model $record): mixed` |
| `tooltip` | `fn (Model $record): ?string` |
| `url` | `fn (Model $record): ?string` |
| `extraAttributes` | `fn (Model $record): array` — scalars only, `on*` keys refused |

## Column types

| Class | `type()` | Own methods |
| --- | --- | --- |
| `TextColumn` | `text` | `limit(int $characters)`, `wrap(bool $wrap = true)` |
| `NumberColumn` | `number` | `decimals(int)` (0), `prefix(string)`, `suffix(string)` |
| `BadgeColumn` | `badge` | `colors(array)`, `labels(array)` |
| `BooleanColumn` | `boolean` | `labels(string $true, string $false)` (`Yes`, `No`) |
| `DateColumn` | `date` | `format(string)` (`M j, Y`), `relative(bool $relative = true)` |
| `DateTimeColumn` | `datetime` | inherits `DateColumn`; format `M j, Y H:i` |
| `ImageColumn` | `image` | `circular(bool)`, `size(int)` (32), `fallbackUsing(Closure)` |
| `IconColumn` | `icon` | `icons(array)`, `colors(array)`, `iconUsing(Closure)`, `boolean(string $trueIcon = 'check', string $falseIcon = 'x')` |
| `ColorColumn` | `color` | `copyable(bool $copyable = true)` |
| `CustomColumn` | `custom` | `component(string)`, `state(Closure)` |

Default alignments: `End` on `NumberColumn`; `Center` on `BooleanColumn`, `IconColumn`, `ToggleColumn`, `CheckboxColumn`; `Start` everywhere else.

Cell shapes are listed on [Columns](columns.md).

## `EditableColumn`

`abstract class extends Column`. Its serialized definition carries `editable => true`.

```php
public function rules(array $rules): static;                  // []
public function disabledUsing(Closure $callback): static;     // fn (Model $record): bool
public function mutateUsing(Closure $callback): static;       // fn (mixed $value, Model $record): mixed
public function updateUsing(Closure $callback): static;       // fn (mixed $value, Model $record): void
public function writeTo(string $attribute): static;

public function getWriteAttribute(): string;
public function isDisabledFor(Model $record): bool;
public function validationRules(): array;                     // implied rules, then rules()
public function write(Model $record, mixed $value): void;
```

| Class | `type()` | Own methods | Implied rules |
| --- | --- | --- | --- |
| `ToggleColumn` | `toggle` | — | `boolean` |
| `CheckboxColumn` | `checkbox` | — | `boolean` |
| `TextInputColumn` | `text_input` | `numeric()`, `maxLength(int)` | `string` or `numeric`, plus `max:` |
| `SelectColumn` | `select` | `options(array)` | `Rule::in(array_keys($options))` |

## `HasRelationshipState`

On every column.

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
public function summaryUsesAggregate(): bool;
public function applyQuery(Builder $query): void;
public function applyRelationshipSort(Builder $query, SortDirection $direction): void;
```

Generated attribute names follow Eloquent's own rule: `{relation}_count`, `{relation}_exists`, `{relation}_{aggregate}_{column}`.

## `Filter`

`abstract class`, constructor `final`.

```php
public static function make(string $name): static;
abstract public function type(): FilterType;
abstract public function sanitize(mixed $value): mixed;               // null rejects
abstract protected function constrain(Builder $query, mixed $value): void;

public function label(string $label): static;                         // Str::headline($name)
public function column(string $column): static;                       // the filter name
public function query(Closure $callback): static;                     // fn (Builder, mixed): void
public function modifyBaseQueryUsing(Closure $callback): static;      // fn (Builder, mixed): void
public function default(mixed $value): static;

public function getName(): string;
public function getLabel(): string;
public function getColumn(): string;
public function hasDefault(): bool;
public function getDefault(): mixed;
public function indicator(mixed $value): ?string;
public function toArray(): array;

final public function apply(Builder $query, mixed $value): void;
final public function applyBaseQuery(Builder $query, mixed $value): void;
protected function describe(mixed $value): string;
protected function extraArray(): array;
```

| Class | `type()` | Own methods |
| --- | --- | --- |
| `SelectFilter` | `select` | `options(array)`, `placeholder(string)` |
| `BooleanFilter` | `boolean` | `labels(string $true, string $false)`, `nullable(bool $nullable = true)` |
| `DateFilter` | `date` | — |
| `TernaryFilter` | `ternary` | `labels(string $true, string $false, ?string $blank = null)`, `nullable()`, `queries(Closure $true, Closure $false)`; constants `TRUE`, `FALSE` |
| `TrashedFilter` | `select` | constants `WITHOUT`, `WITH`, `ONLY` |
| `FormFilter` | `form` | `form(Closure)`, `schema(): FormSchema` |
| `QueryBuilderFilter` | `query_builder` | `constraints(array)`, `maxRules(int)` (10), `constraint(string): ?Constraint` |

## `Constraint`

`abstract class`, constructor `final`.

```php
public static function make(string $name): static;
abstract public function operators(): array;      // list<ConstraintOperator>
abstract public function inputType(): string;     // 'text' | 'number' | 'date' | 'none'

public function label(string $label): static;
public function column(string $column): static;
public function getName(): string;
public function getLabel(): string;
public function getColumn(): string;
public function supports(ConstraintOperator $operator): bool;
public function accepts(ConstraintOperator $operator, mixed $value): bool;
public function apply(Builder $query, ConstraintOperator $operator, mixed $value): void;
public function toArray(): array;
```

| Class | `inputType()` | Operators |
| --- | --- | --- |
| `TextConstraint` | `text` | contains, does not contain, starts with, ends with, equal, not equal, is filled, is blank |
| `NumberConstraint` | `number` | equal, not equal, >, >=, <, <=, is filled, is blank |
| `DateConstraint` | `date` | equal, >, >=, <, <=, is filled, is blank |
| `BooleanConstraint` | `none` | is true, is false, is filled, is blank |

`NumberConstraint::accepts()` requires `is_numeric()`; `DateConstraint::accepts()` requires a string `strtotime()` can read. The base implementation accepts any non-empty scalar.

## `Summarizer`

`abstract class`, constructor `final`.

```php
public static function make(string $name = ''): static;
abstract public function aggregate(): ?string;
abstract protected function reduce(array $values): mixed;

public function label(string $label): static;
public function formatUsing(Closure $callback): static;    // fn (mixed $value): string
public function perPage(bool $perPage = true): static;      // false
public function isPerPage(): bool;
public function getName(): string;                         // lowercased class basename when unnamed
public function getLabel(): string;                        // Str::headline(getName())
public function summarize(QueryBuilder $query, string $column): mixed;
public function summarizeRecords(array $records, string $column): mixed;
public function format(mixed $value): string;              // '—' for null
public function toArray(mixed $value): array;              // name, label, value, raw, perPage
```

| Class | `aggregate()` | Figure |
| --- | --- | --- |
| `Sum` | `sum` | `float\|int` |
| `Average` | `avg` | `?float` |
| `Count` | `count` | `int` |
| `Range` | `null` | `{min, max}`, computed with its own `min()` and `max()` |

## `Group`

```php
public function __construct(string $name);
public static function make(string $name): self;

public function label(string $label): self;                    // Str::headline($name)
public function column(string $column): self;                  // the group name
public function direction(SortDirection $direction): self;     // Ascending
public function titleUsing(Closure $callback): self;           // fn (Model): ?string
public function descriptionUsing(Closure $callback): self;     // fn (Model): ?string

public function getName(): string;
public function getLabel(): string;
public function getColumn(): string;
public function keyFor(Model $record): string;
public function titleFor(Model $record): string;               // 'Ungrouped' for an empty key
public function descriptionFor(Model $record): ?string;
public function applySort(Builder $query): void;
public function toArray(): array;                              // name, label
```

## `Tab`

Declared on a `ListRecords` page's `tabs()` method, keyed by the value the URL takes.

```php
public static function make(string $key, ?string $label = null): self;
public readonly string $key;

public function query(Closure $callback): self;                // fn (Builder): Builder
public function badge(string|int|Closure|null $badge): self;
public function icon(?string $icon): self;                     // an icon registry key
public function getLabel(): string;
public function apply(Builder $query): Builder;
public function resolveBadge(): string|int|null;
public function toArray(bool $active): array;                  // key, label, icon, badge, active
```

## `ArrayTableData`

`final readonly class`.

```php
public function __construct(
    TableSchema $schema,
    Collection $records,
    Request $request,
    ?string $namespace = null,
);

public static function make(
    TableSchema $schema,
    iterable $records,
    Request $request,
    ?string $namespace = null,
): self;

public function paginate(): LengthAwarePaginator;
public function rows(LengthAwarePaginator $records): array;
public function pagination(LengthAwarePaginator $records): array;
public function state(): array;
public function sortableColumns(): array;      // list<Column>
```

Reads `page`, `search`, `sort`, and `direction`. Filters, per-column search, grouping, the column manager, `perPage`, and session persistence are not applied.

## Enums

| Enum | Cases |
| --- | --- |
| `ColumnType` | `text`, `number`, `badge`, `boolean`, `date`, `datetime`, `image`, `icon`, `color`, `custom`, `toggle`, `checkbox`, `text_input`, `select` |
| `FilterType` | `select`, `boolean`, `date`, `ternary`, `form`, `query_builder` |
| `Alignment` | `start`, `center`, `end`, `justify`; `fromRequest()` maps `left`/`right` |
| `BadgeColor` | `neutral`, `success`, `warning`, `danger`, `info`; `fromValue()` falls back to `Neutral` |
| `ColumnPin` | `start`, `end` |
| `SortDirection` | `asc`, `desc`; `fromRequest()` falls back to `Ascending`; `opposite()` |
| `RecordActionsPosition` | `after_columns`, `before_columns`, `after_cells` |
| `RelationshipAggregate` | `count`, `exists`, `sum`, `avg`, `min`, `max`; `attributeFor()`, `apply()` |
| `ConstraintOperator` | `contains`, `does_not_contain`, `starts_with`, `ends_with`, `equal_to`, `not_equal_to`, `greater_than`, `greater_than_or_equal`, `less_than`, `less_than_or_equal`, `is_filled`, `is_blank`, `is_true`, `is_false`; `label()`, `needsValue()` |

## Exceptions

All are `PandaPanel\Exceptions\PanelSchemaException`, thrown at the setter so the trace points at the declaration.

| Factory | Thrown when |
| --- | --- |
| `duplicateColumns(array $names)` | two columns share a name |
| `duplicateFilters(array $names)` | two filters share a name |
| `duplicateActions(string $set, array $names)` | two actions in one set share a name |
| `inertAction(string $name)` | an action has no handler, URL, form, or modal |
| `emptyName(string $what)` | `Column::make('')` |
| `unknownDefaultSort(string $column, array $available)` | `toArray()` finds the default sort column is not declared |

## Endpoints

One set per panel. Route names are `panel.{panel id}.actions.*`.

| Route | Method | Payload |
| --- | --- | --- |
| `actions.record` | POST | `resource`, `action`, `record`, `parent?` |
| `actions.bulk` | POST | `resource`, `action`, `records[]` (1–500), `parent?` |
| `actions.table` | POST | `resource`, `action`, `parent?` |
| `actions.cell` | POST | `resource`, `record`, `column`, `value`, `parent?` |
| `actions.reorder` | POST | `resource`, `records[]` (1–500), `parent?` |
| `actions.form` | GET | `resource`, `action`, `scope`, `record?` — fetches an action's form |
| `actions.submit` | POST | `resource`, `action`, `scope`, `record?`, `records[]?`, plus the form's fields |

Both form routes sit at the path `actions/form`; `scope` is one of `record`, `table`, `bulk`, `infolist`.

`ResourcePage::actionEndpoints()` sends the resolved URLs to Vue, so no panel URL is hardcoded in the frontend.

## Serialized shapes

### `TableSchema::toArray()`

`columns`, `filters`, `groups`, `defaultGroup`, `columnManager`, `filterBehaviour`, `searchable`, `searchPlaceholder`, `searchDebounce`, `searchOnBlur`, `individualSearchColumns`, `selectable`, `reorderable`, `frozen`, `perPageOptions`, `defaultPerPage`, `defaultSort`, `bulkActions`, `recordActions`, `headerActions`, `toolbarActions`, `emptyState`.

### `TableSchema::toRow()`

```php
[
    'key' => int|string,
    'group' => ['key' => string, 'title' => string, 'description' => ?string] | null,
    'cells' => array<string, mixed>,
    'cellMeta' => array<string, array{tooltip?, url?, attributes?, action?}>,
    'actions' => list<array<string, mixed>>,
]
```

### Pagination

```php
['page' => int, 'perPage' => int, 'total' => int, 'lastPage' => int, 'from' => int, 'to' => int]
```

### Summaries

```php
// TableSchema::summaries()
['total' => [['name' => 'sum', 'label' => 'Sum', 'value' => '1,240', 'raw' => 1240, 'perPage' => false]]]

// TableSchema::groupSummaries(), keyed by group key first
['open' => ['total' => [...]]]
```

## Testing helpers

`PandaPanel\Testing\TestsTables` asks a table what it is showing, through the real schema and query.

```php
public static function for(string $resource): self;

public function filter(array $values): self;
public function search(string $term): self;
public function sort(string $column, string $direction = 'asc'): self;
public function page(int $page): self;

public function records(): array;                 // list<Model>
public function keys(): array;                    // list<int|string>
public function row(Model $record): array;
public function schema(): TableSchema;

public function assertCanSeeRecord(Model $record): self;
public function assertCanNotSeeRecord(Model $record): self;
public function assertCanSeeRecords(array $records): self;
public function assertCount(int $count): self;
public function assertRecordsInOrder(array $records): self;
public function assertCellEquals(Model $record, string $column, mixed $expected): self;
public function assertColumnExists(string $column): self;
```

Every method except `schema()` is immutable: `filter()`, `search()`, `sort()`, and `page()` return a clone.

## See also

- [TableSchema basics](overview.md)
- [Columns](columns.md) and [Editable columns](editable-columns.md)
- [Filters](filters.md) and [Query builder filters](query-builder.md)
- [Summaries](summaries.md), [Grouping](grouping.md), [Tabs](tabs.md)
- [Pagination](pagination.md) and [Persisted table state](persisted-state.md)
- [Array data tables](array-data.md)
- [Testing tables](../testing/tables.md)
- [Resource API reference](../resources/api.md)
- [Actions API reference](../api/actions.md)
