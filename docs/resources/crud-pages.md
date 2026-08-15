# List, Create, View and Edit Pages

The four standard resource pages. Each is a real controller class you subclass, name in `Resource::pages()`, and — most of the time — leave empty. This page covers what each one does, every method worth overriding, and the routes they register.

## The minimal set

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Resources\Pages\ListRecords;

final class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
```

`CreatePost extends CreateRecord`, `ViewPost extends ViewRecord`, and `EditPost extends EditRecord` are the same three lines with a different base. Wire them up:

```php
/**
 * @return array<string, class-string>
 */
public static function pages(): array
{
    return [
        'index' => ListPosts::class,
        'create' => CreatePost::class,
        'view' => ViewPost::class,
        'edit' => EditPost::class,
    ];
}
```

`$resource` is the only required declaration. Everything else on these classes has a default that works.

## Routes

Each page key registers fixed routes, relative to the resource path, and each route name is `panel.{panelId}.resources.{slug}.{suffix}`.

| Page key | Verb and path | Method called | Route name suffix |
| --- | --- | --- | --- |
| `index` | `GET /` | `render` | `index` |
| `create` | `GET create` | `render` | `create` |
| `create` | `POST create` | `handle` | `store` |
| `create` | `POST create/step` | `validateStep` | `validateCreateStep` |
| `view` | `GET {record}` | `render` | `view` |
| `edit` | `GET {record}/edit` | `render` | `edit` |
| `edit` | `PUT {record}/edit` | `handle` | `update` |
| `edit` | `POST {record}/edit/step` | `validateStep` | `validateEditStep` |

Routes are registered as `[PageClass::class, 'render']` and `[PageClass::class, 'handle']` — real controller actions, never closures, so `php artisan route:cache` keeps working.

Static segments are registered before the `{record}` wildcard, otherwise `/create` would be matched as a record key. A [singular resource](singular-resources.md) has the `{record}` segment stripped from all of them.

## `ListRecords`

Renders `panel/resources/Index`. It owns authorization, the table schema, the URL-driven query, and the serialized rows — and it never builds a query of its own: it starts from `Resource::query()`, so the resource scope always applies.

`render()` refuses with 403 unless `Resource::canViewAny()`.

### Tabs

```php
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Tables\Tab;

final class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /**
     * @return array<string, Tab>
     */
    public function tabs(): array
    {
        return [
            'all' => Tab::make('all')->badge(static fn (): int => Post::query()->count()),
            'published' => Tab::make('published')
                ->icon('check')
                ->query(static fn (Builder $query): Builder => $query->whereNotNull('published_at')),
            'drafts' => Tab::make('drafts')
                ->query(static fn (Builder $query): Builder => $query->whereNull('published_at')),
        ];
    }
}
```

Returning `[]` — the default — means no tabs. The array key is the value the tab takes in the URL (`?tab=drafts`); an unrecognised key falls back to the first tab rather than erroring, exactly as an unknown sort column does, because the query string is user input.

A tab is a narrowing of the resource query, never a query of its own, so tenant and permission scopes still apply to whatever it shows. The page's widgets are given the tab-scoped query too, so a widget counts what the user is looking at.

### Other overrides

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\TableSchema;

protected function headerActions(): array;                       // list<array<string, mixed>>
protected function rows(TableSchema $schema, LengthAwarePaginator $records, ?Group $group = null): array;
protected function pagination(LengthAwarePaginator $records): array;
protected function pageMetadata(): array;
```

`headerActions()` returns a single "New {label}" link, and only when the resource declares a `create` page *and* `Resource::canCreate()` allows it. Override it to return `[]` for a list that should never offer creation from the header.

`pagination()` deliberately sends counters only — `page`, `perPage`, `total`, `lastPage`, `from`, `to` — and not the paginator's link array: the frontend builds URLs from the current query string, which keeps the URL the single source of truth.

## `CreateRecord`

Renders `panel/resources/Create`. `render()` and `handle()` both refuse with 403 unless `Resource::canCreate()`.

`handle()` runs the create lifecycle, validating only the fields the schema declares and persisting only the fields that dehydrate — an extra key in the request body is discarded rather than mass-assigned.

### Declarations

```php
protected static bool $canCreateAnother = true;
protected static bool $preservesDataOnCreateAnother = false;
```

`$canCreateAnother` offers a second submit that saves and returns to the form. `$preservesDataOnCreateAnother` decides whether that form keeps what was just typed; it is off because the common case is a run of different records, and a form that silently keeps the previous one invites saving it twice. The frontend only reports which button was pressed — what "another" means is the server's decision.

### Overrides

```php
use Illuminate\Database\Eloquent\Model;

protected function handleRecordCreation(array $attributes): Model;
protected function getRedirectUrl(Model $record): string;
protected function createdNotification(Model $record): ?array;
```

`handleRecordCreation()` is the write itself. The default is `new $model` filled and saved; override it to create through a service, a factory, or a relationship:

```php
use App\Services\PostPublisher;
use Illuminate\Database\Eloquent\Model;

/**
 * @param  array<string, mixed>  $attributes
 */
protected function handleRecordCreation(array $attributes): Model
{
    return app(PostPublisher::class)->create($attributes, auth()->user());
}
```

`getRedirectUrl()` returns the edit page when the resource has one and the index otherwise. `createdNotification()` returns `['type' => 'success', 'message' => '{Label} created.']`; return `null` for a page that should say nothing.

```php
use Illuminate\Database\Eloquent\Model;

protected function getRedirectUrl(Model $record): string
{
    return PostResource::url('view', $record);
}

/**
 * @return array{type: string, message: string}|null
 */
protected function createdNotification(Model $record): ?array
{
    return ['type' => 'success', 'message' => 'Post scheduled.'];
}
```

## `EditRecord`

Renders `panel/resources/Edit`. The record is resolved through `Resource::query()`, so a record the resource scope excludes is a 404 here as well as on the index, and authorized with `canEdit()` — `EditRecord` overrides `authorizeRecord()` for exactly that reason, because editing needs more than viewing.

```php
use Illuminate\Database\Eloquent\Model;

protected function authorizeRecord(Model $record): bool;   // canEdit() by default
protected function handleRecordUpdate(Model $record, array $attributes): Model;
protected function getRedirectUrl(Model $record): string;  // the edit page again
protected function savedNotification(Model $record): ?array;
```

```php
use Illuminate\Database\Eloquent\Model;

/**
 * @param  array<string, mixed>  $attributes
 */
protected function handleRecordUpdate(Model $record, array $attributes): Model
{
    app(PostRevisions::class)->snapshot($record);

    $record->forceFill($attributes)->save();

    return $record;
}
```

The edit page also ships the record's relation tables, so every relation manager the resource declares appears beneath the form. See [Relation managers](../relations/relation-managers.md).

## `ViewRecord`

Renders `panel/resources/View`, read-only, authorized with `canView()`.

Its content comes from one of two places. When the resource declares an infolist, that is rendered. When it does not, the page derives entries from the same form schema the edit page uses — so a field added once shows up in both places. Password fields are excluded from the derived entries: they have nothing meaningful to display and rendering the stored hash would put it on screen.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Field;

protected function entries(Model $record): array;                        // the form-derived fallback
protected function displayValue(Field $field, Model $record): ?string;   // stringified server-side
protected function headerActions(Model $record): array;
```

`headerActions()` returns a single "Edit" link, and only when the resource declares an `edit` page and `canEdit()` allows it for this record.

Adopting an infolist is the better path once the view page has any structure to it:

```php
use PandaPanel\Infolists\InfolistSchema;

public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return PostInfolist::configure($schema);
}
```

See [Infolists](../infolists/overview.md).

## Shared behaviour

Every one of the four extends `PandaPanel\Resources\Pages\ResourcePage`.

### Titles and headings

```php
protected static ?string $title = null;
protected static ?string $heading = null;
protected static ?string $subheading = null;

public function getTitle(?Model $record = null): string;
public function getHeading(?Model $record = null): string;
public function getSubheading(?Model $record = null): ?string;
```

| Page | `title` | `heading` | `subheading` |
| --- | --- | --- | --- |
| `ListRecords` | plural label | title | — |
| `CreateRecord` | `New {label}` | title | — |
| `ViewRecord` | record title | title | label |
| `EditRecord` | `Edit {record}` | record title | `Edit {label}` |
| `ManageRelatedRecords` | manager title | title | owner's record title |

The heading follows the title unless a page separates the two, which only the edit page does: the breadcrumb above already says the page is an edit, so heading the record twice with the verb reads as a mistake.

Declare the static property when the text is fixed, override the getter when it depends on the record:

```php
use Illuminate\Database\Eloquent\Model;

public function getSubheading(?Model $record = null): ?string
{
    return $record === null ? null : 'Editing '.$record->getAttribute('email');
}
```

The record is passed on the pages that have one and is `null` on the pages that do not, so a getter must handle both.

### Transactions

```php
protected static ?bool $hasDatabaseTransactions = null;
```

`null` inherits the panel, which has transactions on by default. Set it to `true` or `false` on a page whose write differs from the rest of the panel — one that also calls an external service, say, where holding a transaction open is worse than not having one. The persist step and the `after*` hooks share that transaction; see [Lifecycle hooks](lifecycle-hooks.md).

### Widgets

```php
/**
 * @return list<class-string<Widget>>
 */
public function headerWidgets(): array
{
    return [PostsThisWeek::class];
}

/**
 * @return list<class-string<Widget>>
 */
public function footerWidgets(): array
{
    return [];
}
```

Both are empty by default. Widgets on a list page receive the page's own query as context; widgets on a record page receive the record. See [Widgets](../widgets/overview.md).

### Route paths and render hooks

```php
protected static ?string $routePath = null;

public static function routePath(string $key): string;      // defaults to the page key
public static function renderHookScope(): string;           // 'resource:{slug}'
public static function resource(): string;                  // class-string<Resource>
public static function hasDatabaseTransactions(): ?bool;
```

`$routePath` only matters for custom pages — the four standard keys have fixed shapes. The render hook scope is a slug, never a class name: nothing in page metadata may name a PHP class. See [Render hooks](../panels/render-hooks.md).

## Wizards

A form split into steps validates one step at a time as the user moves on. `validateStep()` exists on the create and edit pages for that, and is routed at `create/step` and `{record}/edit/step`. The rules are derived from the same schema the whole form uses, narrowed to the fields the step already says it holds, so there is no second definition to drift. A page whose form has no wizard answers 400 rather than pretending to check something.

Nothing to wire: declaring a `Wizard` in the form is enough, and the page sends `validateStepUrl` only when there is one. See [Form layouts](../forms/layouts.md).

## Notes

- **`render()` handles the GET, `handle()` the write.** Both are ordinary controller methods, which is what keeps panel routes cacheable.
- **A create page whose fill hook halts redirects to the index** rather than rendering a half-filled form. A halt during `handle()` returns the user back where they were, having written nothing.
- **The create page posts to the `store` route, not to `create`.** `Resource::url('store')` is what the form submits to; `Resource::url('create')` is the page.
- **`ViewRecord` falls back to form-derived entries only while the resource declares no infolist.** Once `infolist()` returns anything, `entries` is empty and the infolist is authoritative.
- **The four keys are the only ones with fixed routes.** Any other key in `pages()` is a custom page with a single GET route. See [Resource pages](resource-pages.md).

## See also

- [Resource pages](resource-pages.md)
- [Lifecycle hooks](lifecycle-hooks.md)
- [Model binding](model-binding.md)
- [Resource queries](queries.md)
- [URLs and route names](urls-routes.md)
- [Singular resources](singular-resources.md)
- [Tables](../tables/overview.md)
- [Forms and schemas](../forms/overview.md)
- [Infolists](../infolists/overview.md)
- [Widgets](../widgets/overview.md)
- [Relation managers](../relations/relation-managers.md)
