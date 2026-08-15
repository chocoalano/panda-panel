# Creating Resources

A resource is one Eloquent model presented inside one panel: its table, its form, its pages, and the query every one of them reads through. You reach for a resource whenever a model needs listing, creating, viewing, or editing behind a panel. Anything that is not a model — a report, a settings screen, a dashboard — is a [standalone page](../pages-navigation/custom-pages.md) instead.

## A minimal resource

The generator writes the whole set:

```bash
php artisan make:panel-resource Post --panel=Admin
```

That produces `app/Panels/Admin/Resources/Posts/PostResource.php` along with its pages, table, and form. Written by hand, the smallest resource that runs is:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts;

use App\Models\Post;
use App\Panels\Admin\Resources\Posts\Pages\ListPosts;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('title')->required()->maxLength(255),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return ['index' => ListPosts::class];
    }
}
```

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

With the panel discovering `app_path('Panels/Admin/Resources')`, that is everything: the resource is registered, `/admin/posts` is routed, and the sidebar has an entry.

## What a resource must declare

`PandaPanel\Resources\Resource` leaves exactly four members to the subclass.

| Member | Signature | Why it is required |
| --- | --- | --- |
| `$model` | `protected static string $model` | Everything starts from it: the query, the slug, the labels, the policy lookup |
| `table()` | `abstract public static function table(TableSchema $table): TableSchema` | The index has nothing to show without columns |
| `form()` | `abstract public static function form(FormSchema $schema): FormSchema` | A resource with no form should say so explicitly, rather than inherit a create page that silently saves nothing |
| `pages()` | `abstract public static function pages(): array` | The page map is what gets routed; a resource with no pages has no URLs |

`$model` is read through `Resource::getModel()`, which throws `PandaPanel\Exceptions\PanelSchemaException` naming your class when the property was never set. PHP's own "must not be accessed before initialization" names the base class instead, which is why the check exists.

```php
use App\Panels\Admin\Resources\Posts\PostResource;

PostResource::getModel();      // 'App\Models\Post'
```

## Pages

`pages()` maps a page key to a page class. The keys are not decoration: they become route name suffixes, and the four standard keys have fixed route shapes.

```php
use App\Panels\Admin\Resources\Posts\Pages\CreatePost;
use App\Panels\Admin\Resources\Posts\Pages\EditPost;
use App\Panels\Admin\Resources\Posts\Pages\ListPosts;
use App\Panels\Admin\Resources\Posts\Pages\ViewPost;

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

Every key is optional. A resource with only `index` is a read-only list; one with `index` and `edit` has no detail page, and the framework stops offering links to a page that was never declared — `ListRecords` renders no "New" button without a `create` key, and `ViewRecord` renders no "Edit" button without an `edit` key. Anything that is not one of the four is a custom page: see [CRUD pages](crud-pages.md) and [Resource pages](resource-pages.md).

## Keeping the schemas out of the resource

The generator puts the table and form in their own classes and has the resource delegate. That keeps a resource readable once its table has fifteen columns:

```php
use App\Panels\Admin\Resources\Posts\Forms\PostForm;
use App\Panels\Admin\Resources\Posts\Tables\PostsTable;

public static function table(TableSchema $table): TableSchema
{
    return PostsTable::configure($table);
}

public static function form(FormSchema $schema): FormSchema
{
    return PostForm::configure($schema);
}
```

Those classes are plain PHP with a static `configure()`; nothing in the framework requires them. See [Directory convention](directory-convention.md).

## Optional declarations

Every one of these is a static property on the resource with a working default.

| Property | Type | Default | Covered in |
| --- | --- | --- | --- |
| `$slug` | `?string` | plural kebab of the model basename | [URLs and routes](urls-routes.md) |
| `$label` | `?string` | headline of the model basename | [Labels and navigation](labels-navigation.md) |
| `$pluralLabel` | `?string` | `Str::plural()` of the label | [Labels and navigation](labels-navigation.md) |
| `$recordTitleAttribute` | `?string` | `'name'` | [Model binding](model-binding.md) |
| `$navigationLabel` | `?string` | the plural label | [Labels and navigation](labels-navigation.md) |
| `$navigationIcon` | `?string` | `null` | [Labels and navigation](labels-navigation.md) |
| `$activeNavigationIcon` | `?string` | `$navigationIcon` | [Labels and navigation](labels-navigation.md) |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | [Labels and navigation](labels-navigation.md) |
| `$navigationSort` | `int` | `0` | [Labels and navigation](labels-navigation.md) |
| `$shouldRegisterNavigation` | `bool` | `true` | [Labels and navigation](labels-navigation.md) |
| `$cluster` | `?class-string<Cluster>` | `null` | [Clusters](../pages-navigation/clusters.md) |
| `$subNavigationPosition` | `?SubNavigationPosition` | `null`, meaning the panel's | [Sub-navigation](../pages-navigation/sub-navigation.md) |
| `$with` | `list<string>` | `[]` | [Queries](queries.md) |
| `$softDeletes` | `bool` | `false` | [Soft deletes](soft-deletes.md) |
| `$singular` | `bool` | `false` | [Singular resources](singular-resources.md) |
| `$parentResource` | `?class-string<Resource>` | `null` | [Nested resources](nested-resources.md) |
| `$parentRelationship` | `?string` | camel case of the default slug | [Nested resources](nested-resources.md) |
| `$tenantRelationship` | `?string` | `null` | [Queries](queries.md) |
| `$globalSearchAttributes` | `list<string>` | `[]` | [Global search](global-search.md) |
| `$globalSearchLimit` | `int` | `5` | [Global search](global-search.md) |
| `$globalSearchSort` | `int` | `0` | [Global search](global-search.md) |

A worked example using several of them:

```php
use BackedEnum;
use PandaPanel\Resources\Resource;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $navigationIcon = 'users';

    protected static string|BackedEnum|null $navigationGroup = 'User Management';

    protected static int $navigationSort = 10;

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'email'];

    /** @var list<string> */
    protected static array $with = ['passkeys'];

    // ...
}
```

## Optional overrides

Beyond the three abstract members, these are the methods a resource commonly overrides. Each has a working default.

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Integrations\Integrations;
use PandaPanel\Resources\RelationManager;

public static function query(): Builder;                                  // the central query
public static function infolist(InfolistSchema $schema): InfolistSchema;  // the view page's presentation
public static function relationManagers(): array;                         // list<class-string<RelationManager>>
public static function integrations(Integrations $integrations): Integrations;
```

`infolist()` returns the schema untouched by default, and the view page falls back to deriving entries from the form — so adopting an infolist is an improvement you opt into. `relationManagers()` returns `[]`; naming a manager there is the only registration it gets. `integrations()` is off unless a resource calls `$integrations->isEnabled(true)`, because turning it on lets whoever can reach the screen make the server issue outbound requests.

```php
public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return PostInfolist::configure($schema);
}

/**
 * @return list<class-string<RelationManager>>
 */
public static function relationManagers(): array
{
    return [CommentsRelationManager::class];
}
```

## Registering the resource

Two ways, and they merge without duplicating.

```php
use PandaPanel\Core\Panel;

// Discovery: every concrete Resource under the path.
$panel->discoverResources(app_path('Panels/Admin/Resources'));

// Explicit, for a class that lives somewhere else.
$panel->resources([PostResource::class]);
```

Discovery resolves class names through Composer's PSR-4 prefixes rather than by reading files, skips abstract classes and anything that is not a resource, and sorts its results so two machines produce the same manifest. See [Discovery](../concepts/discovery.md).

A panel keys its resources by slug. Two different classes claiming one slug throw `PanelRegistrationException` at registration, and one class registered twice in a panel does too — a second registration would make `Resource::url()` ambiguous. To give a class a different slug or label per panel, use [per-panel configuration](per-panel-configuration.md).

## The generator

```bash
php artisan make:panel-resource Post --panel=Admin
php artisan make:panel-resource Post --panel=Admin --model="App\\Domain\\Blog\\Post"
php artisan make:panel-resource Post --panel=Admin --simple
php artisan make:panel-resource Post --panel=Admin --no-view
php artisan make:panel-resource Post --panel=Admin --soft-deletes
```

| Option | Effect |
| --- | --- |
| `--panel=` | Required. The panel directory and namespace the files are written under |
| `--model=` | The model class. Defaults to `App\Models\{Name}` |
| `--simple` | Only the list page, for modal-based editing |
| `--no-view` | Omits the view page, and the `ViewAction` that would link to it |
| `--soft-deletes` | Declares `$softDeletes`, and adds `TrashedFilter` with the restore and force-delete actions |
| `--force` | Overwrites files that already exist |

Every flag changes the output. Nothing is overwritten without `--force`, and the command reports each file it created and each it left alone. Details in [make:panel-resource](../cli/make-panel-resource.md).

## Notes

- **The model is not optional, and forgetting it fails at the first call.** `getModel()` throws with the name of your class and the line to add.
- **`query()` is the single entry point.** List, view, edit, delete, bulk, action lookup, and global search all resolve through it, so a record outside it is a 404 rather than a filtered row. A resource that overrides it must call `parent::query()`.
- **A resource is not authorized by being registered.** Every page checks a policy, and the sidebar hides an entry the user may not see. See [Authorization](authorization.md).
- **Registering a resource in a second panel does not widen anything by itself,** but it does give the class a second slug to answer to. Ask for the right one with `Resource::url(panel: $other)`.
- **`php artisan panel:cache` freezes the class list, not the data.** Add a resource and the manifest has to be rebuilt. See [Caching](../concepts/caching.md).

## See also

- [Resource directory convention](directory-convention.md)
- [Model binding](model-binding.md)
- [CRUD pages](crud-pages.md)
- [Resource queries](queries.md)
- [Labels and navigation](labels-navigation.md)
- [URLs and route names](urls-routes.md)
- [Resource authorization](authorization.md)
- [Resource API reference](api.md)
- [Tables](../tables/overview.md)
- [Forms and schemas](../forms/overview.md)
- [Actions](../actions/overview.md)
- [Relation managers](../relations/relation-managers.md)
