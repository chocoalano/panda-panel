# Search Attributes

`$globalSearchAttributes` is the list of columns the palette is allowed to match a term against. It is the opt-in *and* the whitelist: a resource with an empty list is not searched, and a column not on the list can never be reached by a request, however the term is spelled. This page is about what you may put in that list and what the search does with it.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders;

use App\Models\Order;
use PandaPanel\Resources\Resource;

final class OrderResource extends Resource
{
    protected static string $model = Order::class;

    protected static ?string $recordTitleAttribute = 'reference';

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['reference', 'customer_email'];

    // ... table(), form(), pages()
}
```

Typing `INV-2024` now matches an order whose `reference` or `customer_email` contains that text.

## The declaration

```php
/**
 * @var list<string>
 */
protected static array $globalSearchAttributes = [];

public static function globalSearchAttributes(): array;   // list<string>
public static function isGloballySearchable(): bool;      // globalSearchAttributes() !== []
```

The accessor is public because `PandaPanel\Search\GlobalSearch` asks for it. Override the method rather than the property when the list depends on something only known at request time:

```php
/**
 * @return list<string>
 */
public static function globalSearchAttributes(): array
{
    $attributes = ['reference'];

    if (auth()->user()?->is_admin === true) {
        $attributes[] = 'internal_note';
    }

    return $attributes;
}
```

Returning `[]` from that method turns the resource off for that user as completely as never declaring it: `isGloballySearchable()` is derived from the list.

## What the search does with the list

Every attribute becomes one `LIKE` condition, and all of them are OR-ed inside a single grouped `where`. The term is escaped for `LIKE` before the pattern is built:

```php
$like = '%'.$this->escapeLike($term).'%';

$query->where(static function (Builder $query) use ($attributes, $like): void {
    foreach ($attributes as $attribute) {
        if (! str_contains($attribute, '.')) {
            $query->orWhere($attribute, 'like', $like);

            continue;
        }

        [$relation, $column] = explode('.', $attribute, 2);

        $query->orWhereHas(
            $relation,
            static fn (Builder $related): Builder => $related->where($column, 'like', $like),
        );
    }
});
```

For `['reference', 'customer_email']` and the term `INV`, the resulting SQL is:

```sql
select * from "orders"
where ("reference" like ? or "customer_email" like ?)
limit 5
```

with `%INV%` bound twice. Two properties follow from that shape, and both matter:

- **The group is one `where`,** so the conditions in `globalSearchQuery()` are ANDed with the whole OR block. A resource that scopes itself to `whereNotNull('published_at')` cannot have that scope widened by a search term.
- **The term is always a bound value.** It is never concatenated into a column name or an operator. Nothing from the request reaches SQL as an identifier.

## What may go in the list

| Form | Example | Behaviour |
| --- | --- | --- |
| A column on the resource's table | `'name'` | `where('name', 'like', '%escaped-term%')` |
| A relation path, one level | `'author.name'` | `whereHas('author', fn ($q) => $q->where('name', 'like', '%escaped-term%'))` — see [Relationship search](relationships.md) |
| Anything else Eloquent's `where()` accepts as a column | `'meta->company'` | passed through unchanged, so a JSON path is compiled by the grammar |

And what may not:

| Not supported | Why |
| --- | --- |
| An accessor or computed attribute (`full_name`) | The condition is SQL; the database has no such column |
| A qualified column (`users.name`) | A dot always means a relation, so this looks for a relation named `users` |
| A deeper path (`author.company.name`) | The path is split once — see [Relationship search](relationships.md) |
| An encrypted or hashed column | `LIKE` cannot match ciphertext |
| An aggregate or subquery expression | The list holds column names, not expressions |

There is no validation of the list at boot. A misspelled column is a database error on the first search that reaches it, not a startup failure.

## Matching semantics

The operator is `like` and the pattern is `%escaped-term%` — a substring match, anchored nowhere.

- **Case sensitivity is the database's,** not the framework's. MySQL with a `_ci` collation matches case-insensitively; PostgreSQL's `LIKE` does not. If you need case-insensitive matching on PostgreSQL, make the column or the collation do it — the operator is not configurable.
- **`%`, `_`, and `\` in the user's term are escaped.** A term of `%%` searches for literal percent signs rather than every row with a non-null value.
- **The term is trimmed and must be at least two characters** (`mb_strlen` after `trim`). Shorter terms return `[]` without a query.
- **There is no ranking.** A row that matches on three attributes is not ordered above one that matched on one. Add an `orderBy` in `globalSearchQuery()` if the order matters.

## Performance

A leading `%` makes a standard B-tree index unusable, so every search is a scan of whatever `globalSearchQuery()` narrowed the table to. That is fine on a table of thousands and not fine on a table of millions. Options, in the order they usually apply:

```php
use Illuminate\Database\Eloquent\Builder;

// 1. Narrow the searchable set.
public static function globalSearchQuery(): Builder
{
    return static::query()->where('archived', false);
}
```

```php
// 2. Search fewer columns. Each attribute is another OR condition on every row.
protected static array $globalSearchAttributes = ['reference'];
```

```php
// 3. Lower the resource's share so the database returns sooner.
protected static int $globalSearchLimit = 3;
```

```php
// 4. Raise the debounce so a typed word costs one query, not six.
$panel->globalSearch(debounce: 500);
```

A denormalized column — one text column kept in sync and indexed for full text — is the usual next step, and it is a column like any other in this list. There is no Laravel Scout integration to fall back on.

## A worked example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Customers;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\Resource;

final class CustomerResource extends Resource
{
    protected static string $model = Customer::class;

    protected static ?string $recordTitleAttribute = 'company_name';

    /**
     * `search_index` is a generated column holding name, email and phone,
     * kept in one place so a search is one indexed condition rather than
     * three unindexed ones.
     *
     * @var list<string>
     */
    protected static array $globalSearchAttributes = ['search_index'];

    protected static int $globalSearchLimit = 8;

    public static function globalSearchQuery(): Builder
    {
        return static::query()->where('status', '!=', 'archived');
    }

    // ... table(), form(), pages()
}
```

## Gotchas

- **A dot always means a relation.** `'orders.reference'` is a `whereHas` on the `orders` relation, never a qualified column. If `globalSearchQuery()` joins a table and a column name becomes ambiguous, you cannot fix it here — rename the column in the query, or select an alias.
- **An empty list is a silent opt-out.** Removing the last attribute while refactoring removes the resource from the palette, and if it was the only searchable resource the palette disappears from the header.
- **A misspelled column fails at query time.** It surfaces as a failed search request, which the palette renders as "Nothing found." Check the log, not the dialog.
- **`null` values never match.** `LIKE` on `NULL` is unknown, so a row with an empty column is simply not a hit.
- **Numeric columns work but rarely as intended.** `where('id', 'like', '%12%')` matches 12, 120, and 512. Search a reference string instead of a primary key when the user is typing an identifier.
- **The list is read on every search.** Overriding `globalSearchAttributes()` with something expensive puts that cost on every keystroke.

## See also

- [Global search overview](overview.md)
- [Searchable resources](searchable-resources.md)
- [Relationship search](relationships.md)
- [Search result details](result-details.md)
- [Search security](security.md)
- [Resource queries](../resources/queries.md)
- [Table search](../tables/search.md)
