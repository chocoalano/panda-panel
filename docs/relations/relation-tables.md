# Relation Tables

A relation table is the `TableSchema` a relation manager declares, serialized for the record page that carries it. It is the same shape a resource index sends — definition, applied state, rows, pagination — plus the identity of the relation, the endpoints its writes post to, and the query-string namespace its state lives under. You read this page when you want columns, filters, sorting, or paging on a relation and need to know which parts of the table API carry over.

## A minimal relation table

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableSchema;

public static function table(TableSchema $table, Model $owner): TableSchema
{
    return $table
        ->columns([
            TextColumn::make('title')->searchable()->sortable(),
            BadgeColumn::make('status'),
        ])
        ->filters([
            SelectFilter::make('status')->options([
                'draft' => 'Draft',
                'published' => 'Published',
            ]),
        ])
        ->defaultSort('title')
        ->recordActions([
            EditRelatedAction::make(UserResource::class, self::class, $owner),
            DeleteRelatedAction::make(self::class, $owner),
        ])
        ->emptyState(
            heading: 'No posts yet',
            description: 'Posts written by this user will appear here.',
        );
}
```

Everything in [Columns](../tables/columns.md), [Filters](../tables/filters.md), [Sorting](../tables/sorting.md), [Search](../tables/search.md), [Grouping](../tables/grouping.md), and [Summaries](../tables/summaries.md) applies unchanged — it is one `TableSchema` class, and a relation table is built by the same `TableQuery` a resource index is.

## State lives under its own namespace

Several relation tables share one record page, so each reads its state from its own slice of the query string:

```text
/admin/users/3?relations[posts][page]=2&relations[posts][sort]=title&relations[comments][search]=hello
```

Sorting one leaves the others where they were. The namespace is `relations.{key}` and it is sent to the frontend as `stateKey`, so the browser never reconstructs it:

```php
use PandaPanel\Resources\RelationTable;

RelationTable::stateKey('posts');   // 'relations.posts'
```

| Parameter | Shape | Meaning |
| --- | --- | --- |
| `relations[key][page]` | int | Page number |
| `relations[key][perPage]` | int | Rows per page, from `perPageOptions()` |
| `relations[key][search]` | string | The global search term |
| `relations[key][sort]` | string | A column name the table declared sortable |
| `relations[key][direction]` | `asc` / `desc` | Sort direction |
| `relations[key][filters][name]` | filter value | One filter's value |
| `relations[key][columnSearch][name]` | string | An individually searchable column's term |
| `relations[key][columns][visible]` | list | Column manager visibility |
| `relations[key][columns][order]` | list | Column manager order |

Every value is validated against the schema. A sort naming a column the relation table did not declare is ignored rather than becoming an `order by` on a name from the request:

```text
?relations[tasks][sort]=project_id   →  state.sort is null
```

Session persistence works too — `persistSortInSession()`, `persistFiltersInSession()`, `persistSearchInSession()`, `persistColumnsInSession()` — under a key that includes the manager, so two relations on one page never share remembered state:

```text
panel.{panelId}.table.{resourceSlug}.{relationKey}
```

## Pagination goes through the relation

```php
public static function relationForTable(Model $owner): Relation;
```

The table paginates `relationForTable()`, not `query()`. A many-to-many hydrates its pivot inside `BelongsToMany::paginate()`, and a builder taken out of the relation produces rows whose pivot columns all read as null. That is why a pivot column works at all — see [Pivot fields](pivot-fields.md).

Pagination metadata is sent as:

```json
{ "page": 2, "perPage": 10, "total": 34, "lastPage": 4, "from": 11, "to": 20 }
```

## Header actions are resolved, not declared

Create, attach, and associate are added by `RelationTable` itself:

```php
CreateRelatedAction::make($resource, $manager, $owner);
AttachAction::make($resource, $manager, $owner);
AssociateAction::make($resource, $manager, $owner);
```

All three are answers to what the relation *is*, and a manager that had to list them would be able to offer an attach on a `hasMany`. Each is dropped from the payload when it is hidden or unauthorized, so a `hasMany` never renders an attach button and a user without `create` never renders a create button.

`TableSchema::headerActions()` is a separate list belonging to the table schema, and a relation table does not render it. Put row-level work in `recordActions()` and set-level work in `bulkActions()`.

## Record actions

```php
->recordActions([
    EditRelatedAction::make(UserResource::class, self::class, $owner),
    DetachAction::make(self::class, $owner),
    RestoreAction::make(self::class, $owner),
    ForceDeleteAction::make(self::class, $owner),
    DeleteRelatedAction::make(self::class, $owner),
])
```

A row action posts to the panel's relation action endpoint:

```text
POST /{panel}/relations/action
{ "resource": "users", "record": 3, "relation": "posts", "action": "delete", "related": 12 }
```

The controller resolves the manager through `Resource::relationManager()`, loads the related record through `RelationManager::resolveRecord()`, and asks the action's own `isAuthorizedFor($related)` before running it. A related key belonging to another owner is a 404; an action name the table did not declare is a 404; an action with no handler is a 400.

Custom actions work the same way as anywhere else — build a `PandaPanel\Actions\Action` and add it to `recordActions()`:

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

// Inside the manager's table(), where $owner and self:: are both in scope.
Action::make('publish')
    ->label('Publish')
    ->icon('send')
    ->variant(ActionVariant::Outline)
    ->requiresConfirmation(heading: 'Publish this post?')
    ->successMessage('Post published.')
    ->authorize(static fn (?Model $record): bool => $record !== null
        && self::canEdit($owner, $record))
    ->action(static function (Model $record): void {
        $record->update(['published_at' => now()]);
    });
```

Authorization is yours to declare: an action without `->authorize()` is authorized for everyone who can read the relation.

## Bulk actions

```php
->bulkActions([
    DetachBulkAction::make(self::class, $owner),
    RestoreBulkAction::make(self::class, $owner),
    ForceDeleteBulkAction::make(self::class, $owner),
])
```

```text
POST /{panel}/relations/bulk
{ "resource": "users", "record": 3, "relation": "posts", "action": "detach", "records": [1, 2, 3] }
```

| Rule | Behaviour |
| --- | --- |
| `records` | required array, 1 to 500 keys, deduplicated |
| A key outside the relation | 404 for the whole request — the count of loaded records must match the count of keys |
| Collective authorization | `isAuthorizedFor(null)` before anything is loaded |
| Per-record authorization | the action's own handler re-checks each record and throws 403 for the set |

Keys outside the relation silently disappear from `query()`, so the count check is what turns that into a visible failure rather than a partial operation.

## Summaries and grouping

```php
->groups([Group::make('status')])
->defaultGroup('status')
```

Summaries and group summaries are computed against `relationForTable()->getQuery()` and the page's records, and sent as `summaries` and `groupSummaries`. Both are empty when the schema declares none. See [Summaries](../tables/summaries.md) and [Grouping](../tables/grouping.md).

## The serialized payload

`PandaPanel\Resources\RelationTable::toArray()` produces:

| Key | Type | Notes |
| --- | --- | --- |
| `key` | `string` | `RelationManager::key()` |
| `title` | `string` | `RelationManager::title()` |
| `icon` | `string\|null` | `RelationManager::icon()` |
| `stateKey` | `string` | `relations.{key}` |
| `table` | `array` | `TableSchema::toArray()` |
| `state` | `array` | The state the server actually applied |
| `rows` | `list<array>` | One serialized row per record |
| `summaries` | `array` | Empty when the schema declares none |
| `groupSummaries` | `array` | Empty when no group is active |
| `pagination` | `array` | `page`, `perPage`, `total`, `lastPage`, `from`, `to` |
| `headerActions` | `list<array>` | Create, attach, associate — only those that survived authorization |
| `endpoints` | `array` | `form`, `save`, `action`, `bulk` |

The whole thing is `null` when `RelationManager::canViewAny($owner)` says no, so an unauthorized manager never queries and never appears.

## The `RelationTable` API

| Method | Signature | Returns |
| --- | --- | --- |
| `stateKey()` | `static stateKey(string $relationKey): string` | `relations.{key}` |
| `toArray()` | `toArray(Request $request): ?array` | The payload above, or null |
| `forRecord()` | `static forRecord(string $resource, Model $owner, Request $request): list<array>` | Every manager the resource declares |
| `forManager()` | `static forManager(string $resource, string $manager, Model $owner, Request $request): ?array` | One named manager |
| `actionFor()` | `static actionFor(string $manager, Model $owner, string $name): ?Action` | A record action by name |
| `bulkActionFor()` | `static bulkActionFor(string $manager, Model $owner, string $name): ?Action` | A bulk action by name |

```php
use PandaPanel\Resources\RelationTable;

// What ViewRecord and EditRecord put in their `relations` prop.
$relations = RelationTable::forRecord(UserResource::class, $user, $request);

// What a ManageRelatedRecords page puts in its `relation` prop.
$relation = RelationTable::forManager(
    UserResource::class,
    PostsRelationManager::class,
    $user,
    $request,
);
```

A resource page can narrow the list by overriding `ResourcePage::relationTables()`:

```php
protected function relationTables(Request $request, Model $record): array
{
    return array_values(array_filter(
        parent::relationTables($request, $record),
        static fn (array $relation): bool => $relation['key'] !== 'posts',
    ));
}
```

## What a relation table does not do

A relation manager has four endpoints — form, save, action, bulk. Table surfaces that post to a resource's own endpoints have no relation equivalent, so declaring them renders a control that does nothing:

- **Toolbar actions** (`toolbarActions()`) and **table actions**.
- **Empty-state actions** — the empty state's heading, description, and icon render; its actions do not run.
- **Editable columns** — a relation cell is read-only.
- **Drag reordering** (`reorderable()`).
- **Filter tabs** — those belong to `ListRecords`, not to a `TableSchema`.

Column visibility, filters, search, sorting, grouping, summaries, pagination, row selection, record actions, and bulk actions all work.

## Gotchas

- **Sorting or searching a pivot column does not work by default.** `TextColumn::make('pivot.role')->sortable()` orders by a column literally named `pivot.role`. Give `sortable()` a real column — `sortable(column: 'label_project.role')` — or leave the column unsorted.
- **A relation table renders inside a card, with its own border.** It is one object among several on the record page, so it draws an edge of its own rather than joining toolbar, rows, and pagination into one surface the way a resource index does.
- **The `table()` method runs more than once per request.** It is called to serialize the table, and again to resolve an action by name on the action and bulk endpoints. Keep it free of side effects.
- **`canViewAny()` runs before the query.** A refused manager costs nothing — a manager that queried and then hid its rows would still have read them.
- **A key that is not in the relation is invisible, not forbidden.** That is deliberate: 404 is the honest answer for a record this owner has never had.

## See also

- [Relation managers](relation-managers.md)
- [Relation forms](relation-forms.md)
- [Relation pages](relation-pages.md)
- [Pivot fields](pivot-fields.md)
- [Soft deleted relations](soft-deletes.md)
- [Tables overview](../tables/overview.md)
- [Columns](../tables/columns.md)
- [Filters](../tables/filters.md)
- [Record actions](../tables/record-actions.md)
- [Bulk actions](../tables/bulk-actions.md)
- [Persisted table state](../tables/persisted-state.md)
