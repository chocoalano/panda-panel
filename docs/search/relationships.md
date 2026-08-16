# Relationship Search

An attribute containing a dot searches the relation it names instead of a column on the resource's own table. It is how a post is found by its author's name, or an order by its customer's email, without denormalizing anything. This page covers the syntax, exactly one level deep, and what to do when you need more.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use PandaPanel\Resources\Resource;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    protected static ?string $recordTitleAttribute = 'title';

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['title', 'author.name', 'author.email'];

    // ... table(), form(), pages()
}
```

`Post::author()` is an ordinary Eloquent relation. Typing `Lovelace` now finds every post whose title contains it *or* whose author's name or email does.

## The syntax

```text
{relation}.{column}
```

The part before the first dot is a relation method on the resource's model. Everything after it is
used as a column on the related table. `$like` is the escaped `%escaped-term%` pattern the search
builder already prepared:

```php
[$relation, $column] = explode('.', $attribute, 2);

$query->orWhereHas(
    $relation,
    static fn (Builder $related): Builder => $related->where($column, 'like', $like),
);
```

So `['title', 'author.name']` with the term `Ada` compiles to:

```sql
select * from "posts"
where (
    "title" like ?
    or exists (
        select * from "users"
        where "posts"."author_id" = "users"."id" and "name" like ?
    )
)
limit 5
```

Each dotted attribute is one `EXISTS` subquery, OR-ed with the rest inside the same grouped `where` as the plain columns.

## Which relations work

Anything `whereHas()` accepts:

| Relation | Works | Note |
| --- | --- | --- |
| `belongsTo` | yes | the common case — a post's author |
| `hasOne` / `hasMany` | yes | matches when *any* related row matches |
| `belongsToMany` | yes | the pivot join is built by the relation |
| `hasOneThrough` / `hasManyThrough` | yes | this is the way to reach two levels — see below |
| `morphOne` / `morphMany` / `morphToMany` | yes | the morph type constraint comes from the relation |
| `morphTo` | no | Eloquent cannot constrain a polymorphic parent with `whereHas`; the search has no `whereHasMorph` equivalent |

The relation's own constraints come along: a `hasMany` filtered in its definition, a related model with `SoftDeletes`, a global scope on the related model. A trashed author is not a match, because the subquery is built from the relation, not from a table name this framework guessed.

## One level, and only one

The path is split **once**. `author.company.name` becomes the relation `author` and the column `company.name`, and a column with a dot in it is compiled as `"company"."name"` — a reference to a table that is not in the subquery. The database rejects it. Nothing warns you at boot; the first search that reaches the attribute fails.

Three ways out, in the order they usually apply.

**Define a through-relation on the model,** which turns two hops into one:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

final class Post extends Model
{
    public function authorCompany(): HasOneThrough
    {
        return $this->hasOneThrough(Company::class, User::class, 'id', 'id', 'author_id', 'company_id');
    }
}
```

```php
/** @var list<string> */
protected static array $globalSearchAttributes = ['title', 'authorCompany.name'];
```

**Denormalize the value onto the resource's own table,** which is the right answer when the search is hot and the value rarely changes:

```php
/** @var list<string> */
protected static array $globalSearchAttributes = ['title', 'author_name'];
```

**Narrow the search instead of widening the path.** If a user really needs to find posts by company, a company resource whose results link onward is usually the better interface.

## Combining with eager loads

Searching a relation and *showing* it are separate. `whereHas` filters without loading; if `globalSearchResultDetails()` reads the relation, load it or you get one query per hit:

```php
use Illuminate\Database\Eloquent\Model;

final class PostResource extends Resource
{
    /** @var list<string> */
    protected static array $globalSearchAttributes = ['title', 'author.name'];

    /**
     * `query()` applies this, and `globalSearchQuery()` starts from `query()`,
     * so the search gets the eager load without restating it.
     *
     * @var list<string>
     */
    protected static array $with = ['author'];

    /**
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        $author = $record->getAttribute('author');

        return ['Author' => $author instanceof Model ? (string) $author->getAttribute('name') : '—'];
    }
}
```

`Model::shouldBeStrict()` is on outside production in the starter kit, so a forgotten eager load fails loudly rather than quietly costing a query per row.

## Narrowing further

`globalSearchQuery()` can constrain the relation as well, and its conditions are ANDed with the whole search block:

```php
use Illuminate\Database\Eloquent\Builder;

public static function globalSearchQuery(): Builder
{
    return static::query()
        ->with('author')
        ->whereHas('author', static fn (Builder $author): Builder => $author->where('active', true));
}
```

That is "an active author's post, matching the term anywhere" — not "a post whose author is active or whose title matches".

## Gotchas

- **A dot is always a relation.** There is no way to write a table-qualified column in `$globalSearchAttributes`; `users.name` looks for a relation called `users`.
- **A missing relation is a runtime error.** `whereHas('athor', …)` throws `BadMethodCallException` on the first search, not at boot.
- **`morphTo` is not searchable.** Search the concrete resources instead and let each one contribute its own group.
- **Each dotted attribute is a separate `EXISTS`.** Three relation attributes are three subqueries per search, each with a leading-wildcard `LIKE` inside. Index what you can, and prefer one denormalized column when the palette starts to feel slow.
- **`whereHas` matches rows, not values.** A `hasMany` attribute makes the parent a hit when *any* child matches; the child that matched is not shown anywhere. Put something identifying in `globalSearchResultDetails()` if that would confuse the user.
- **The related model's global scopes apply.** That is usually what you want (no trashed authors), but it also means a scoped relation can make a record unfindable by a term that is plainly in the database.

## See also

- [Search attributes](attributes.md)
- [Searchable resources](searchable-resources.md)
- [Search result details](result-details.md)
- [Global search overview](overview.md)
- [Resource queries](../resources/queries.md)
- [Table relationships](../tables/relationships.md)
- [Relation managers](../relations/relation-managers.md)
