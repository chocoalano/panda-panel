# Search Result Details

Every hit in the palette is drawn as a title and, under it, a row of labelled details. The title tells the user which record this is; the details tell them which of the three records with the same name they are looking at. Both are resolved on the server, so the frontend never has to decide what a record is called.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'email'];

    /**
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        return [
            'Email' => (string) $record->getAttribute('email'),
            'Role' => $record instanceof User && $record->is_admin ? 'Administrator' : 'Member',
        ];
    }

    // ... table(), form(), pages()
}
```

A hit now reads:

```text
Ada Lovelace
Email: ada@example.com   Role: Administrator
```

## The shape of a result

`PandaPanel\Search\GlobalSearch` reduces each record to a `PandaPanel\Search\GlobalSearchResult` before anything leaves the server:

```php
namespace PandaPanel\Search;

final readonly class GlobalSearchResult
{
    /**
     * @param  array<string, string>  $details
     */
    public function __construct(
        public string $title,
        public string $url,
        public array $details = [],
    ) {}

    /**
     * @return array{title: string, url: string, details: array<string, string>}
     */
    public function toArray(): array;
}
```

It carries no model, no query and no closure. By the time it exists the resource has been authorized, the title and details have been resolved, and the URL has been generated. You rarely construct one yourself; you decide what goes into it through the three resource methods below.

## The title

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultTitle(Model $record): string
{
    return static::recordTitle($record);
}
```

The default delegates to `Resource::recordTitle()`, the same method breadcrumbs and every other single-record label use:

```php
public static function recordTitle(Model $record): string
{
    $attribute = static::$recordTitleAttribute ?? 'name';

    $value = $record->getAttribute($attribute);

    return is_scalar($value) ? (string) $value : (string) $record->getKey();
}
```

So the usual way to fix a title is not to override the method at all:

```php
protected static ?string $recordTitleAttribute = 'reference';
```

Override the method when the title is composed rather than stored:

```php
use Illuminate\Database\Eloquent\Model;

public static function globalSearchResultTitle(Model $record): string
{
    return sprintf(
        '%s %s',
        (string) $record->getAttribute('first_name'),
        (string) $record->getAttribute('last_name'),
    );
}
```

Note the fallback: a missing or non-scalar attribute yields the primary key rather than an empty line, because a hit with no visible title is unusable.

## The details

```php
use Illuminate\Database\Eloquent\Model;

/**
 * @return array<string, string>
 */
public static function globalSearchResultDetails(Model $record): array
{
    return [];
}
```

Empty by default. The array is a map of **label to value**, both strings, and both are drawn verbatim:

```vue
<span v-for="(value, key) in result.details" :key="key">
    {{ key }}: {{ value }}
</span>
```

Insertion order is preserved, so the first entry is the leftmost. The whole detail line is omitted when the array is empty.

Formatting belongs on the server, because the array crosses the wire as strings and Vue does no formatting of its own:

```php
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * @return array<string, string>
 */
public static function globalSearchResultDetails(Model $record): array
{
    $placedAt = $record->getAttribute('placed_at');
    $status = $record->getAttribute('status');

    return [
        'Total' => Number::currency((float) $record->getAttribute('total'), 'EUR'),
        'Placed' => $placedAt instanceof Carbon ? $placedAt->toFormattedDateString() : '—',
        'Status' => $status instanceof OrderStatus ? $status->label() : (string) $status,
    ];
}
```

Translate the labels the ordinary way when the panel is localized:

```php
return [__('Email') => (string) $record->getAttribute('email')];
```

## Details that read a relation

`globalSearchResultDetails()` is called once per hit, so a relation read inside it is a query per hit unless it was eager loaded. `globalSearchQuery()` starts at `Resource::query()`, which applies `$with`, so declaring the relation there is enough:

```php
use Illuminate\Database\Eloquent\Model;

final class PostResource extends Resource
{
    /** @var list<string> */
    protected static array $with = ['author'];

    /**
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        $author = $record->getAttribute('author');

        return [
            'Author' => $author instanceof Model ? (string) $author->getAttribute('name') : '—',
        ];
    }
}
```

If `globalSearchQuery()` is overridden to something that does not go through `query()`, add the eager load there yourself.

## What details must not contain

Details are presentation, and they are read by anyone who can search the resource. Two rules:

- **Scalars only.** The annotation says `array<string, string>` and nothing casts at runtime, so a `Carbon`, an enum, or a model in that array is serialized by `json_encode` into whatever shape it happens to have — and the palette then renders `[object Object]`. Cast on the server.
- **Nothing the record page would not show.** `canViewAny()` gates the resource, not the row; a detail is visible to every user who can search. Do not put a token, a hash, or another user's private field in there. See [Search security](security.md).

Values are interpolated by Vue, which escapes them, so a detail containing markup renders as text rather than HTML. Details cannot inject anything into the palette.

## Gotchas

- **Long values are not truncated.** The detail row wraps; a 500-character note makes one result taller than the dialog. Truncate on the server with `Str::limit()`.
- **A null attribute becomes the string `""`,** which renders as `Label: ` with nothing after it. Use an explicit placeholder (`'—'`) when a value can be missing.
- **`recordTitle()` defaults to the `name` attribute,** not to the model's first string column. A model with no `name` shows its primary key until `$recordTitleAttribute` is set.
- **The title is not the navigation label.** `pluralLabel()` names the group; `globalSearchResultTitle()` names the row.
- **Details are computed for every hit, even ones the user never looks at.** Keep them to attribute reads; a details method that calls an API turns one keystroke into five HTTP requests.
- **There is no icon, colour or badge on a result.** The only per-result fields are `title`, `url` and `details`; the icon belongs to the group.

## See also

- [Search result URLs](result-urls.md)
- [Searchable resources](searchable-resources.md)
- [Search attributes](attributes.md)
- [Relationship search](relationships.md)
- [Search security](security.md)
- [Labels and navigation](../resources/labels-navigation.md)
- [Creating resources](../resources/creating-resources.md)
