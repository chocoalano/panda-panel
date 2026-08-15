# Singular Resources

A singular resource is one with exactly one record: application settings, the current tenant, a singleton configuration row. Its pages carry no `{record}` because there is nothing to choose between, and the record is resolved by the resource instead of by the URL. Reach for it when a model has a table but only ever one row in it — anything with no model at all is a [standalone page](../pages-navigation/custom-pages.md) instead.

## A working singular resource

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\AppSettings;

use App\Models\AppSetting;
use App\Panels\Admin\Resources\AppSettings\Pages\EditAppSettings;
use App\Panels\Admin\Resources\AppSettings\Pages\ViewAppSettings;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

final class AppSettingResource extends Resource
{
    protected static string $model = AppSetting::class;

    protected static ?string $slug = 'app-settings';

    protected static bool $singular = true;

    protected static ?string $navigationLabel = 'Application settings';

    protected static ?string $navigationIcon = 'settings';

    public static function table(TableSchema $table): TableSchema
    {
        return $table;
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('support_email')->required()->email(),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ViewAppSettings::class,
            'edit' => EditAppSettings::class,
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\AppSettings\Pages;

use App\Panels\Admin\Resources\AppSettings\AppSettingResource;
use PandaPanel\Resources\Pages\ViewRecord;

final class ViewAppSettings extends ViewRecord
{
    protected static string $resource = AppSettingResource::class;
}
```

`EditAppSettings extends EditRecord` is the same three lines. That gives `/admin/app-settings` for the read screen and `/admin/app-settings/edit` for the form, a sidebar entry, and no record key anywhere.

## The declaration

```php
protected static bool $singular = false;

public static function isSingular(): bool;
```

One property. Everything below follows from it, and nothing else has to be configured.

## What happens to the routes

`PanelRouteRegistrar` strips `{record}` from every path a singular resource registers. Route names are untouched.

| Page key | Ordinary path | Singular path | Route name |
| --- | --- | --- | --- |
| `index` | `/` | `/` | `.index` |
| `create` | `create` | `create` | `.create`, `.store`, `.validateCreateStep` |
| `view` | `{record}` | `/` | `.view` |
| `edit` | `{record}/edit` | `edit` | `.edit`, `.update`, `.validateEditStep` |
| a custom key | `ResourcePage::routePath($key)` | the same, with `{record}` removed | `.{key}` |

```php
use Illuminate\Support\Facades\Route;

Route::has('panel.admin.resources.app-settings.edit');                 // true
route('panel.admin.resources.app-settings.edit', absolute: false);     // '/admin/app-settings/edit'
```

A custom page path that is nothing but `{record}` strips to `/`, and one like `{record}/audit` strips to `audit`.

## Where the record comes from

```php
public static function resolveSingularRecord(): Model
{
    return static::query()->firstOrFail();
}
```

Through `query()` like every other lookup, so a tenant, module, or permission scope still applies — a singular resource is a singleton within its scope, not a singleton globally. When the scope holds no row, `firstOrFail()` throws `ModelNotFoundException`, which Laravel renders as a 404.

Pages reach it through the concern they already use:

```php
protected function resolveRecord(int|string|null $key = null): Model
{
    $resource = static::$resource;

    $record = $key === null || $resource::isSingular()
        ? $resource::resolveSingularRecord()
        : $resource::resolveRecord($key);

    abort_unless($this->authorizeRecord($record), 403);

    return $this->record = $record;
}
```

Two conditions, and the second is what matters: a singular resource resolves its own record even when a key somehow arrives. There is no URL that can name a different row.

### Creating the row on first visit

Override the method. It is a normal static method with no framework wiring:

```php
use App\Models\AppSetting;
use Illuminate\Database\Eloquent\Model;

public static function resolveSingularRecord(): Model
{
    return static::query()->firstOr(static fn (): AppSetting => AppSetting::query()->create([
        'support_email' => config('mail.from.address'),
    ]));
}
```

Or, when the row is identified by a key rather than by being first:

```php
public static function resolveSingularRecord(): Model
{
    return static::query()->firstOrCreate(['key' => 'global']);
}
```

Both still go through `query()`, so the created row lands inside whatever scope the resource declares.

## URLs

`Resource::url()` drops the record argument for a singular resource, so calling code does not have to know:

```php
use App\Panels\Admin\Resources\AppSettings\AppSettingResource;

AppSettingResource::url();                 // '/admin/app-settings'
AppSettingResource::url('edit');           // '/admin/app-settings/edit'
AppSettingResource::url('edit', $record);  // '/admin/app-settings/edit' — the record is ignored
```

The relevant line is a single condition in `Resource::url()`:

```php
if ($record !== null && ! static::isSingular()) {
    $parameters['record'] = $record instanceof Model ? $record->getKey() : $record;
}
```

That is what lets `EditRecord` keep working unchanged: it builds its submit URL as `Resource::url('update', $model)`, and on a singular resource the model is not needed. See [URLs and route names](urls-routes.md).

## Which pages to declare

`index` and `view` both strip to the resource root, so declaring both registers two GET routes on one path. The second replaces the first: the `index` route name disappears entirely, and `Resource::url()` — which defaults to `'index'` — throws `RouteNotFoundException`. Declare one of them, not both.

`Resource::url()` is not only called by your code. `ResourcePage::baseBreadcrumbs()` calls it on every record page:

```php
Breadcrumb::make($resource::pluralLabel())->url($resource::url());
```

So **a singular resource needs an `index` key**. Without one, its edit page 500s on the breadcrumb rather than rendering. Two shapes work:

```php
// Read screen at /admin/app-settings, form at /admin/app-settings/edit.
return [
    'index' => ViewAppSettings::class,
    'edit' => EditAppSettings::class,
];

// A one-row table at /admin/app-settings, form at /admin/app-settings/edit.
return [
    'index' => ListAppSettings::class,
    'edit' => EditAppSettings::class,
];
```

Any `ResourcePage` subclass may sit under the `index` key — the key decides the route, the class decides what is rendered. What does not work is putting `EditRecord` there and nothing else: the form submits to the `update` route, which only the `edit` key registers.

`create` is rarely wanted. A resource with exactly one record has one either because a migration seeded it or because `resolveSingularRecord()` makes it; a create page offers to make a second.

## The sidebar entry

`Resource::navigationItem()` returns `null` when `pages()` has no `index` key, because the item's `href` is the index URL and building a link to a route that was never registered fails while rendering the sidebar — taking down every page in the panel rather than just that one.

So the `index` key is also what puts a singular resource in the sidebar. With it, the ordinary declarations apply:

```php
protected static ?string $navigationLabel = 'Application settings';

protected static ?string $navigationIcon = 'settings';

protected static string|BackedEnum|null $navigationGroup = 'System';

protected static int $navigationSort = 90;
```

A singular resource that should stay out of the sidebar sets `protected static bool $shouldRegisterNavigation = false;` and is reached from somewhere else. See [Labels and navigation](labels-navigation.md).

## Authorization

Unchanged. The record pages ask the same abilities they ask for any other resource:

| Page | Ability |
| --- | --- |
| `index` | `Resource::canViewAny()` |
| `view` and custom record pages | `Resource::canView($record)` |
| `edit` | `Resource::canEdit($record)` |
| `create` | `Resource::canCreate()` |

The policy receives the one record like any other, so a policy written for the model needs nothing special:

```php
final class AppSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, AppSetting $record): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, AppSetting $record): bool
    {
        return $user->is_admin;
    }
}
```

See [Resource authorization](authorization.md).

## Notes

- **Singularity is a declaration, not a detection.** A table that happens to hold one row is still an ordinary resource; the framework never guesses.
- **`index` and `view` cannot both be declared.** They resolve to the same path and one silently replaces the other.
- **Every singular resource needs an `index` page.** `ResourcePage::baseBreadcrumbs()` builds `Resource::url()`, which is the index route, so a record page without it fails while rendering its trail. The sidebar is gentler: it omits the entry rather than throwing.
- **`resolveSingularRecord()` is `firstOrFail()`, not `find(1)`.** Which row is "first" is whatever `query()` orders by; add an `orderBy` in `query()` if more than one row can exist.
- **The record argument to `url()` is ignored, not rejected.** Passing one is harmless, which is what lets shared page code work on both kinds of resource.
- **Tables, filters and bulk actions still work** — a singular resource with a `ListRecords` index is a table of one row. It is rarely what you want, but nothing forbids it.
- **Global search still applies** if `$globalSearchAttributes` is declared, and its result URL is the view page when there is one and the index otherwise.

## See also

- [Creating resources](creating-resources.md)
- [List, create, view and edit pages](crud-pages.md)
- [Resource pages](resource-pages.md)
- [Model binding](model-binding.md)
- [URLs and route names](urls-routes.md)
- [Resource queries](queries.md)
- [Labels and navigation](labels-navigation.md)
- [Resource authorization](authorization.md)
- [Standalone pages](../pages-navigation/custom-pages.md)
- [Settings pages](../panels/settings-pages.md)
- [Routing](../concepts/routing.md)
