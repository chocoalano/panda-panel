# Testing Tables

`panelTable()` builds the table the list page builds, from the resource's own `TableSchema` and `TableQuery`. Reach for it when the question is "what would this user see" — which rows, in which order, with what in a given cell — rather than "does this URL return 200". A list page returning 200 while showing every tenant's records is a passing test and a data leak.

## A minimal working example

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada Lovelace']);

    $this->actingAs($this->admin);
});

it('says which records the table is showing', function (): void {
    $grace = User::factory()->create(['name' => 'Grace Hopper']);

    panelTable(UserResource::class)
        ->assertCanSeeRecord($this->admin)
        ->assertCanSeeRecord($grace)
        ->assertCount(2);
});
```

## How it is wired

`TestsTables::records()` does exactly what the list controller does:

```php
$schema = $this->resource::table(TableSchema::make());
$request = Request::create('/', 'GET', $this->state);

$request->setLaravelSession(app('session.store'));

return array_values(
    (new TableQuery($schema, $request))
        ->paginate($this->resource::query())
        ->items(),
);
```

Two consequences worth knowing. The state is a **query string**, so a filter the helper can set is a filter a URL can set and nothing more. And the request carries a session, because a table with `persistSearchInSession()` or `persistFiltersInSession()` reads one.

## Setting state

Four setters, each returning a **clone**. The object is immutable, so a variable holding a table is not changed by the assertion chain built from it.

| Method | Signature | Query-string equivalent |
| --- | --- | --- |
| `filter` | `filter(array $values): self` | `?filters[verified]=false` |
| `search` | `search(string $term): self` | `?search=Grace` |
| `sort` | `sort(string $column, string $direction = 'asc'): self` | `?sort=name&direction=asc` |
| `page` | `page(int $page): self` | `?page=2` |

```php
use PandaPanel\Tables\Filters\TernaryFilter;

$unverified = panelTable(UserResource::class)
    ->filter(['verified' => TernaryFilter::FALSE]);

$byName = $unverified->sort('name', 'desc')->page(2);

// `$unverified` is unchanged: filter() cloned, sort() cloned again.
```

`filter()` **merges**, so two calls compose:

```php
panelTable(UserResource::class)
    ->filter(['verified' => TernaryFilter::FALSE])
    ->filter(['is_admin' => TernaryFilter::TRUE]);
```

A filter value the schema does not accept is ignored rather than applied, which is a property worth asserting rather than working around:

```php
it('ignores a ternary value outside the three it accepts', function (): void {
    $all = panelTable(UserResource::class)->keys();

    expect(panelTable(UserResource::class)->filter(['verified' => 'maybe'])->keys())
        ->toBe($all);
});
```

There is **no `perPage()`**. Page size, column visibility, grouping and per-column searches are set by a request; make one and read `viewData('page')`.

## Reading the result

| Method | Signature | Returns |
| --- | --- | --- |
| `records` | `records(): array` | `list<Model>` — the current page, in order |
| `keys` | `keys(): array` | `list<int\|string>` — their primary keys |
| `row` | `row(Model $record): array` | one row as the frontend receives it |
| `schema` | `schema(): TableSchema` | the table as the resource declares it, built fresh |

```php
$records = panelTable(UserResource::class)->sort('name')->records();

expect($records[0]->name)->toBe('Ada Lovelace');

$keys = panelTable(UserResource::class)->keys();

$row = panelTable(UserResource::class)->row($this->admin);
```

`row()` is `TableSchema::toRow()`, so its shape is the row shape the browser gets:

```php
[
    'key' => 1,
    'group' => null,                    // the band, when the table is grouped
    'cells' => ['name' => …, 'email' => …],
    'cellMeta' => [],                   // per-cell extras, beside the values
    'actions' => [['name' => 'view', …], …],
]
```

`actions` holds only the actions offered **for that record**: an action refused by its `authorize()` closure or hidden by `visible()` is absent from the row rather than rendered and refused later.

## The assertions

| Method | Signature | Fails when |
| --- | --- | --- |
| `assertCanSeeRecord` | `assertCanSeeRecord(Model $record): self` | the key is not on the current page |
| `assertCanNotSeeRecord` | `assertCanNotSeeRecord(Model $record): self` | the key is on the current page |
| `assertCanSeeRecords` | `assertCanSeeRecords(array $records): self` | any one of them is missing |
| `assertCount` | `assertCount(int $count): self` | the page holds a different number of records |
| `assertRecordsInOrder` | `assertRecordsInOrder(array $records): self` | the keys differ, in value or in order |
| `assertCellEquals` | `assertCellEquals(Model $record, string $column, mixed $expected): self` | the cell is not identical (`assertSame`) to `$expected` |
| `assertColumnExists` | `assertColumnExists(string $column): self` | the schema declares no column with that name |

All seven return `$this`, so they chain:

```php
panelTable(UserResource::class)
    ->search('Grace')
    ->assertCanSeeRecord($grace)
    ->assertCanNotSeeRecord($this->admin)
    ->assertCount(1);
```

### Scope, not presence

`assertCanNotSeeRecord()` is about the whole current page, so on its own it proves "not on this page" rather than "unreachable". When the property under test is the scope, assert on a page that could hold everything:

```php
it('hides a record the resource query excludes', function (): void {
    $outside = User::factory()->create(['is_admin' => true]);

    panelTable(ScopedUserResource::class)->assertCanNotSeeRecord($outside);

    // And the record is unreachable by key, which is the half a table
    // assertion cannot see.
    $this->get("/scope-host/scoped-users/{$outside->id}")->assertNotFound();
});
```

### Order

`assertRecordsInOrder()` compares keys with `assertSame`, so it is both the sort assertion and a count assertion:

```php
panelTable(UserResource::class)
    ->sort('name', 'asc')
    ->assertRecordsInOrder([$ada, $grace]);
```

### Cells

`assertCellEquals()` reads `row($record)['cells'][$column]` and compares with `assertSame`. The value is whatever that column type serializes, which is frequently not a string:

```php
// TextColumn — a scalar
panelTable(UserResource::class)
    ->assertCellEquals($this->admin, 'email', $this->admin->email);

// TextInputColumn — an editable cell is the input's state
panelTable(UserResource::class)
    ->assertCellEquals($this->admin, 'name', ['value' => 'Ada Lovelace', 'disabled' => false]);
```

The cell shapes in the example application's users table, as a map of what to expect:

| Column type | Cell |
| --- | --- |
| `TextColumn` | the formatted value, or `null` |
| `TextInputColumn`, `ToggleColumn` | `['value' => …, 'disabled' => bool]` |
| `BadgeColumn` | `['value' => …, 'label' => …, 'color' => …]` |
| `IconColumn` | `['icon' => …, 'color' => …, 'label' => …]` |
| `NumberColumn` | `['display' => string, 'raw' => int\|float]` |
| `DateTimeColumn` | `['display' => string, 'iso' => string]` |
| `ImageColumn` | `['url' => …, 'fallback' => …, 'alt' => …]` |
| `CustomColumn` | whatever its `state()` closure returns |

Asserting the shape is itself worth doing, because it is the contract the Vue component is written against:

```php
expect(panelTable(UserResource::class)->row($this->admin)['cells']['created_at'])
    ->toHaveKeys(['display', 'iso']);
```

## Asserting through the request

The helper covers what the schema and the query do. Three things only a request can show, and all three are worth a test:

**The applied state.** The table reports back what it honoured, which is how you prove a hostile parameter was ignored rather than merely harmless:

```php
$page = $this->get('/admin/users?sort=password')->viewData('page');

expect($page['props']['state']['sort'])->toBeNull();
```

**Pagination metadata.**

```php
use Inertia\Testing\AssertableInertia;

User::factory()->count(12)->create();   // plus the administrator: thirteen

$this->get('/admin/users?perPage=10')
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->where('pagination.page', 1)
        ->where('pagination.perPage', 10)
        ->where('pagination.total', 13)
        ->where('pagination.lastPage', 2)
        ->has('rows', 10));
```

**A query per row.** The one N+1 test that keeps working as the table grows: count queries for a small page and a large one, and assert they are equal.

```php
use Illuminate\Support\Facades\DB;

it('does not issue a query per row while serializing', function (): void {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->get('/admin/users?perPage=50')->assertOk();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    User::factory()->count(4)->create();
    $few = $countQueries();

    User::factory()->count(30)->create();
    $many = $countQueries();

    expect($many)->toBe($few);
});
```

A budget (`expect($queries)->toBeLessThan(12)`) drifts upward every time somebody adds an eager load. Equality does not.

## Gotchas

- **`row()` serializes the model you give it.** A column reading a `withCount` alias — `NumberColumn::make('passkeys_count')->counts('passkeys')` — is `null` for a bare factory model and `['display' => '0', 'raw' => 0]` for a record taken from `records()`, because only the second went through the table's query. Take the record from `records()` when the column is an aggregate.
- **`schema()` is rebuilt on every call.** Two assertions never share resolved state. It also means holding `$table->schema()` in a variable and mutating it changes nothing about later assertions.
- **`assertCellEquals()` uses `assertSame`.** `'1'` is not `1`, and `['value' => true, 'disabled' => false]` must match key order and types.
- **The current user is part of the answer.** `disabledUsing()` on a toggle column, `visible()` on a row action, and the resource policy are all evaluated during `row()`. The same record produces different rows for different users, which is the property to test rather than to control for.
- **`assertCount()` counts the page, not the table.** Combine with `page()` or assert on `pagination.total` through a request.

## See also

- [Testing helpers](helpers.md) and [test setup](setup.md)
- [Tables overview](../tables/overview.md), [columns](../tables/columns.md), [filters](../tables/filters.md)
- [Editable columns](../tables/editable-columns.md), [sorting](../tables/sorting.md), [search](../tables/search.md)
- [Tables API](../tables/api.md)
- [Negative security tests](negative-security-tests.md) — the hostile-input half of the same surface
