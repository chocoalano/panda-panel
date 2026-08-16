# Resources Reference

`PandaPanel\Resources\Resource` and the classes around it: the pages it routes, the configuration a panel wraps it in, and the hooks its pages may override. Reach for this page to look up a signature or a default; [Creating resources](../resources/creating-resources.md) explains when to reach for each one.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Resources\Resource` | The base class a resource extends |
| `PandaPanel\Resources\ResourceConfiguration` | One resource class, configured for one panel |
| `PandaPanel\Resources\Pages\ResourcePage` | The base of every resource page |
| `PandaPanel\Resources\Pages\ListRecords` | The index |
| `PandaPanel\Resources\Pages\CreateRecord` | The create page |
| `PandaPanel\Resources\Pages\ViewRecord` | The read-only detail page |
| `PandaPanel\Resources\Pages\EditRecord` | The edit page |
| `PandaPanel\Resources\Pages\ManageRelatedRecords` | A page devoted to one relation |
| `PandaPanel\Resources\Concerns\HasLifecycleHooks` | The hooks a page may override |
| `PandaPanel\Resources\Concerns\InteractsWithRecord` | Record resolution for a custom page |
| `PandaPanel\Resources\RelationManager` | One relation, managed beneath a record |
| `PandaPanel\Search\GlobalSearch` | The service behind the command palette |
| `PandaPanel\Support\ParentRecord` | The bound parent of a nested resource |

## The smallest resource that works

```php
<?php

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
            TextInput::make('title')->required(),
        ]);
    }

    public static function pages(): array
    {
        return ['index' => ListPosts::class];
    }
}
```

```php
namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Resources\Pages\ListRecords;

final class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}
```

Drop it under a discovered path and it is routed at `/admin/posts`, named `panel.admin.resources.posts.index`.

## `Resource` — declarations

Static properties, all `protected`. Every one has a reader that resolves it, and the reader is what everything else calls.

| Property | Type | Default | Reader |
| --- | --- | --- | --- |
| `$model` | `class-string<Model>` | *required* | `getModel()` |
| `$slug` | `?string` | model basename, plural, kebab | `defaultSlug()`, `slug()` |
| `$label` | `?string` | model basename, headline | `defaultLabel()`, `label()` |
| `$pluralLabel` | `?string` | plural of the label | `defaultPluralLabel()`, `pluralLabel()` |
| `$recordTitleAttribute` | `?string` | `'name'` | `recordTitle()` |
| `$navigationLabel` | `?string` | the plural label | `navigationItem()` |
| `$navigationIcon` | `?string` | `null` | `navigationIcon()` |
| `$activeNavigationIcon` | `?string` | the navigation icon | `activeNavigationIcon()` |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | `navigationItem()` |
| `$navigationSort` | `int` | `0` | `navigationItem()` |
| `$shouldRegisterNavigation` | `bool` | `true` | `navigationItem()` |
| `$cluster` | `?class-string<Cluster>` | `null` | `cluster()` |
| `$subNavigationPosition` | `?SubNavigationPosition` | `null` (takes the panel's) | `subNavigationPosition()` |
| `$globalSearchAttributes` | `list<string>` | `[]` | `globalSearchAttributes()` |
| `$globalSearchLimit` | `int` | `5` | `globalSearchLimit()` |
| `$globalSearchSort` | `int` | `0` | `globalSearchSort()` |
| `$singular` | `bool` | `false` | `isSingular()` |
| `$with` | `list<string>` | `[]` | applied inside `query()` |
| `$softDeletes` | `bool` | `false` | `usesSoftDeletes()` |
| `$parentResource` | `?class-string<Resource>` | `null` | `parentResource()`, `isNested()` |
| `$parentRelationship` | `?string` | camel-cased default slug | `parentRelationship()` |
| `$tenantRelationship` | `?string` | `null` | `tenantRelationship()` |

`getModel()` throws `PanelSchemaException::missingModel()` when `$model` was never declared, because PHP's own message names the base class rather than the one that forgot.

## `Resource` — schema methods

```php
abstract public static function table(TableSchema $table): TableSchema;
abstract public static function form(FormSchema $schema): FormSchema;
abstract public static function pages(): array;              // array<string, class-string>

public static function infolist(InfolistSchema $schema): InfolistSchema;   // returns it unchanged
public static function relationManagers(): array;            // []
public static function relationManager(string $key): ?string;
public static function integrations(Integrations $integrations): Integrations;  // returns it unchanged
public static function integrationSettings(): Integrations;  // resolved once per class per request
```

`form()` is abstract rather than defaulting to an empty schema: a resource with no form should say so explicitly rather than inherit a create page that silently saves nothing.

`infolist()` is empty by default, and `ViewRecord` falls back to deriving entries from the form — so adding an infolist is an improvement a resource opts into.

`relationManager($key)` throws `PanelRegistrationException::duplicateRelationKey()` when two managers answer to one key.

## `Resource` — the query

```php
public static function query(): Builder;
protected static function recordQuery(): Builder;
protected static function applyTenantScope(Builder $query): Builder;
protected static function parentRelation(): Relation;
```

`query()` is the single entry point for list, view, edit, update, delete, bulk, action lookup, and global search. In order it:

1. starts from `parentRelation()->getQuery()` for a nested resource, or `Model::query()` otherwise;
2. eager loads `$with`;
3. applies the tenant scope when the panel has tenancy *and* the resource names a `$tenantRelationship`;
4. applies the current panel's `ResourceConfiguration::modifyQueryUsing()`.

Overriding it means calling `parent::query()`, or the panel's own narrowing is silently dropped:

```php
public static function query(): Builder
{
    return parent::query()->where('team_id', auth()->user()->team_id);
}
```

`recordQuery()` is `query()` with `SoftDeletingScope` lifted, and only that scope — tenant, module, and permission scopes still apply. It is what record pages resolve through, so a trashed record can be opened and restored while the index still hides it.

## `Resource` — record lookup

```php
public static function resolveRecord(int|string $key): Model;   // findOrFail, so a miss is a 404
public static function findRecord(int|string $key): ?Model;
public static function findRecords(array $keys): Collection;    // list<int|string> => Collection<int, Model>
public static function resolveSingularRecord(): Model;          // query()->firstOrFail()
public static function recordTitle(Model $record): string;
```

All four go through `recordQuery()`, so a record outside the resource scope is a 404 rather than a leak.

```php
PostResource::findRecord(7);        // ?Post
PostResource::findRecords([1, 2]);  // Collection<int, Post>
```

A singular resource overrides `resolveSingularRecord()` when the row should be created on first visit:

```php
public static function resolveSingularRecord(): Model
{
    return static::query()->firstOrCreate([]);
}
```

## `Resource` — authorization

```php
public static function canViewAny(): bool;          // 'viewAny' on the model class
public static function canView(Model $record): bool;
public static function canCreate(): bool;
public static function canEdit(Model $record): bool;      // 'update'
public static function canDelete(Model $record): bool;
public static function canDeleteAny(): bool;
public static function canRestore(Model $record): bool;
public static function canForceDelete(Model $record): bool;
public static function canRestoreAny(): bool;
public static function canForceDeleteAny(): bool;

protected static function authorize(string $ability, Model|string $argument): bool;
```

Every one routes through `authorize()`, which calls `PandaPanel\Support\PolicyGate::allows()`. Override `authorize()` to change all ten at once; override one `can*` and call `static::authorize()` to keep strict mode working.

Under `Panel::strictAuthorization()` a missing policy or a missing policy method raises `PanelAuthorizationException` instead of quietly denying.

These are for navigation visibility and for the pages' own checks. They are not the security boundary — routes and actions authorize independently.

## `Resource` — slugs, labels, URLs

```php
public static function defaultSlug(): string;
public static function slug(): string;                       // slugIn(panel())
public static function slugIn(?Panel $panel): string;
public static function defaultLabel(): string;
public static function label(): string;
public static function defaultPluralLabel(): string;
public static function pluralLabel(): string;
public static function configurationIn(?Panel $panel): ?ResourceConfiguration;

public static function routeName(string $page = 'index', Panel|string|null $panel = null): string;
public static function url(
    string $page = 'index',
    Model|int|string|null $record = null,
    Panel|string|null $panel = null,
    Model|int|string|null $parent = null,
): string;

protected static function resolvePanel(Panel|string|null $panel): Panel;
protected static function assertRegisteredIn(Panel $panel): void;
```

The panel owns the slug, not the class. `defaultSlug()` exists because the registry needs an answer before it can compute an effective one, so it must not ask the registry back.

```php
PostResource::url();                       // '/admin/posts'
PostResource::url('edit', $post);          // '/admin/posts/7/edit'
PostResource::url('index', panel: 'app');  // the same resource in another panel
CommentResource::url('edit', $comment, parent: $post);  // nested
```

`url()` is always route-name based and always relative (`absolute: false`). Asking for a URL in a panel that does not register the resource throws `PanelRegistrationException::resourceNotInPanel()`; asking outside a panel with no explicit one throws `noCurrentPanel()`.

A nested resource's URLs carry the parent automatically, from `ParentRecord::require()`, so links between its own pages need no extra argument.

## `Resource` — global search

```php
public static function globalSearchAttributes(): array;      // []
public static function isGloballySearchable(): bool;         // attributes !== []
public static function globalSearchLimit(): int;             // 5
public static function globalSearchSort(): int;              // 0
public static function globalSearchQuery(): Builder;         // query()
public static function globalSearchResultTitle(Model $record): string;
public static function globalSearchResultDetails(Model $record): array;   // []
public static function globalSearchResultUrl(Model $record): string;
```

Opt-in: a resource is searched only once it declares `$globalSearchAttributes`. Adding a resource to a panel must never silently widen what a search can reach.

```php
protected static array $globalSearchAttributes = ['name', 'email'];

public static function globalSearchResultDetails(Model $record): array
{
    return ['Email' => (string) $record->getAttribute('email')];
}
```

Details are scalars keyed by label; they cross to Vue. `globalSearchResultUrl()` prefers the view page, then the edit page, then the index — each authorizes independently on arrival.

## `Resource` — navigation

```php
public static function navigationItem(PanelContract $panel): ?NavigationItem;
```

Returns `null` for a nested resource (there is no index to link to), for a resource with no `index` page, and for one whose `$shouldRegisterNavigation` or per-panel configuration says no. Every field falls back to the class's own, so a panel states only what it wants to differ.

## `ResourceConfiguration`

One resource class, configured for one panel. Passed to `Panel::resources()` in place of the bare class.

```php
public static function for(string $resource): self;
public function slug(string $slug): self;
public function label(string $label): self;
public function pluralLabel(string $pluralLabel): self;
public function navigationLabel(string $navigationLabel): self;
public function navigationGroup(?string $navigationGroup): self;
public function navigationIcon(?string $navigationIcon): self;
public function navigationSort(int $navigationSort): self;
public function registerNavigation(bool $register = true): self;
public function modifyQueryUsing(Closure $callback): self;

public function getSlug(): string;
public function getLabel(): string;
public function getPluralLabel(): string;
public function getNavigationLabel(): ?string;
public function getNavigationGroup(): ?string;
public function getNavigationIcon(): ?string;
public function getNavigationSort(): ?int;
public function shouldRegisterNavigation(): ?bool;
public function applyQuery(Builder $query): Builder;
```

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People')
        ->navigationGroup('Directory')
        ->modifyQueryUsing(fn (Builder $query) => $query->where('is_admin', false)),
]);
```

The narrowing applies inside `Resource::query()`, so it reaches every page, every action, and global search. A class registered with a configuration is not also registered bare — `PanelManager` skips the second registration so it cannot claim its default slug too.

## `ResourcePage`

The base of every resource page. Routed as `[PageClass::class, 'render']` for the GET and `'handle'` for the write, so each page is a real controller and panel routes stay cacheable.

### Declarations

| Property | Type | Default |
| --- | --- | --- |
| `$resource` | `class-string<Resource>` | *required* |
| `$hasDatabaseTransactions` | `?bool` | `null` — inherits the panel |
| `$routePath` | `?string` | `null` — takes the page key |
| `$title` | `?string` | `null` — the page's own default |
| `$heading` | `?string` | `null` — follows the title |
| `$subheading` | `?string` | `null` |

### Public methods

```php
public function getTitle(?Model $record = null): string;
public function getHeading(?Model $record = null): string;
public function getSubheading(?Model $record = null): ?string;
public static function routePath(string $key): string;
public static function hasDatabaseTransactions(): ?bool;
public static function renderHookScope(): string;      // 'resource:{slug}'
public static function resource(): string;
public function headerWidgets(): array;                // []
public function footerWidgets(): array;                // []
```

Override the three heading methods when the text depends on something a static property cannot say:

```php
protected function defaultHeading(?Model $record): string
{
    return $record === null ? 'Orders' : "Order #{$record->number}";
}
```

### Protected helpers a custom page uses

```php
protected function defaultTitle(?Model $record): string;
protected function defaultHeading(?Model $record): string;
protected function defaultSubheading(?Model $record): ?string;
protected function headingMetadata(?Model $record = null): array;
protected function panel(): Panel;
protected function dashboardUrl(): string;
protected function baseBreadcrumbs(): array;
protected function parentBreadcrumbs(): array;
protected function recordCrumb(Model $record, string $title): Breadcrumb;
protected function serializeBreadcrumbs(array $crumbs): array;
protected function resourceMetadata(): array;
protected function recordTitle(Model $record): string;
protected function actionEndpoints(): array;
protected function clusterNavigation(): ?array;
protected function subNavigation(?Model $record, string $currentPage): array;
protected function subNavigationPosition(): SubNavigationPosition;
protected function relationTables(Request $request, Model $record): array;
protected function widgetProps(?PageContext $context = null): array;
protected function fillForm(FormSchema $schema, ?Model $record = null): array;
protected function validateStepFor(Request $request, FormSchema $schema, ?Model $record = null): JsonResponse;
```

## `ListRecords`

```php
protected static string $component = 'panel/resources/Index';

public function render(Request $request): Response;
public function tabs(): array;                          // array<string, Tab>, empty by default
protected function pageMetadata(): array;
protected function headerActions(): array;
protected function rows(TableSchema $schema, LengthAwarePaginator $records, ?Group $group = null): array;
protected function pagination(LengthAwarePaginator $records): array;
```

`render()` aborts 403 unless `canViewAny()`, builds the schema through `Resource::table()`, and drives it with a `TableQuery` keyed by panel and slug — never by anything from the request.

```php
use PandaPanel\Tables\Tab;

public function tabs(): array
{
    return [
        'all' => Tab::make('all', 'All'),
        'draft' => Tab::make('draft', 'Draft')
            ->query(fn (Builder $query) => $query->where('status', 'draft'))
            ->badge(fn () => Post::where('status', 'draft')->count()),
    ];
}
```

An unknown `?tab=` falls back to the first, exactly as an unknown sort column does: the query string is user input.

`headerActions()` returns a single create link when the resource declares a `create` page and `canCreate()` allows it. `pagination()` sends counters only — the paginator's link array is deliberately not sent, because the frontend builds URLs from the current query string.

## `CreateRecord`

```php
protected static string $component = 'panel/resources/Create';
protected static string $page = 'create';
protected static bool $canCreateAnother = true;
protected static bool $preservesDataOnCreateAnother = false;

public function render(Request $request): Response;
public function handle(Request $request): RedirectResponse;
public function validateStep(Request $request): JsonResponse;

protected function handleRecordCreation(array $attributes): Model;
protected function getRedirectUrl(Model $record): string;
protected function createdNotification(Model $record): ?array;
protected function schema(): FormSchema;
```

`handle()` runs the create lifecycle: validate, hooks, `dehydrate()`, then `handleRecordCreation()` and `saveRelations()` inside one transaction. Only fields the schema declares are validated, and only fields that dehydrate are persisted, so an extra key in the body is discarded rather than mass-assigned.

```php
protected function handleRecordCreation(array $attributes): Model
{
    return app(CreateOrder::class)->run($attributes);
}

protected function getRedirectUrl(Model $record): string
{
    return static::$resource::url('view', $record);
}

protected function createdNotification(Model $record): ?array
{
    return ['type' => 'success', 'message' => 'Order placed.'];
}
```

Return `null` from `createdNotification()` for a page that should say nothing.

## `EditRecord`

```php
protected static string $component = 'panel/resources/Edit';
protected static string $page = 'edit';

public function render(Request $request, ?string $record = null): Response;
public function handle(Request $request, ?string $record = null): RedirectResponse;
public function validateStep(Request $request, ?string $record = null): JsonResponse;

protected function authorizeRecord(Model $record): bool;   // canEdit()
protected function handleRecordUpdate(Model $record, array $attributes): Model;
protected function getRedirectUrl(Model $record): string;  // back to edit
protected function savedNotification(Model $record): ?array;
protected function schema(): FormSchema;
```

`validateStep()` resolves the record first, so a wizard step cannot be validated against a record the user may not edit.

## `ViewRecord`

```php
protected static string $component = 'panel/resources/View';
protected static string $page = 'view';

public function render(Request $request, ?string $record = null): Response;
protected function entries(Model $record): array;
protected function displayValue(Field $field, Model $record): ?string;
protected function headerActions(Model $record): array;
```

When the resource declares an infolist, that is what renders. When it does not, `entries()` derives read-only rows from the form schema — with `PasswordInput` fields skipped, because rendering the stored hash would put it on screen.

## `ManageRelatedRecords`

```php
protected static string $component = 'panel/resources/ManageRelated';
protected static string $relationManager;   // class-string<RelationManager>
protected static string $page = 'relation';

public function render(Request $request, ?string $record = null): Response;
public static function relationManager(): string;
public static function routePath(string $key): string;    // '{record}/'.$key
public static function relationPageKey(): string;         // the manager's key
```

```php
final class ManageUserPosts extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;
    protected static string $relationManager = PostsRelationManager::class;
}
```

```php
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
        'edit' => EditUser::class,
        'posts' => ManageUserPosts::class,
    ];
}
```

`render()` aborts 404 when the resource does not declare that manager: the page list points into the manager list rather than being a second registration.

## `HasLifecycleHooks`

Used by `ResourcePage`, so every resource page has all of these. Each has a working no-op default and is genuinely called.

```php
protected function halt(): never;                                        // throws Halt

protected function beforeFill(): void;
protected function mutateFormDataBeforeFill(array $data): array;
protected function afterFill(array $data): void;

protected function beforeValidate(array $input): array;
protected function afterValidate(array $data): array;

protected function beforeCreate(): void;
protected function mutateFormDataBeforeCreate(array $data): array;
protected function mutateFormDataBeforeSave(array $data, ?Model $record): array;
protected function beforeSave(?Model $record): void;
protected function afterCreate(Model $record): void;
protected function afterSave(Model $record): void;
```

The order:

```text
render a form   beforeFill → mutateFormDataBeforeFill → afterFill

create          beforeValidate → validate → afterValidate → beforeCreate
                → mutateFormDataBeforeCreate → mutateFormDataBeforeSave
                → beforeSave → handleRecordCreation → afterCreate → afterSave

update          beforeValidate → validate → afterValidate
                → mutateFormDataBeforeSave → beforeSave → handleRecordUpdate → afterSave
```

Two kinds, and the split is the point: a `mutate*` hook takes data and returns it, everything else returns nothing and exists for side effects and for halting.

```php
protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['created_by'] = auth()->id();

    return $data;
}

protected function beforeCreate(): void
{
    if (Order::whereDate('created_at', today())->count() >= 100) {
        $this->halt();
    }
}
```

`halt()` stops the lifecycle before anything is written; the page catches it and returns the user where they came from. It is not an HTTP exception and never surfaces as a 500.

The persist step and the `after*` hooks share one transaction, so a hook that throws rolls the write back.

Deletion has no hooks here: it runs through the action endpoint, which executes without a page instance. Use `Action::before()` and `Action::after()`, which share the action's transaction.

## `InteractsWithRecord`

For a custom resource page whose route carries `{record}`. Used by `ViewRecord`, `EditRecord`, and `ManageRelatedRecords`.

```php
protected function resolveRecord(int|string|null $key = null): Model;
protected function getRecord(): Model;      // throws LogicException before resolveRecord()
protected function hasRecord(): bool;
protected function authorizeRecord(Model $record): bool;    // canView()
```

```php
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Resources\Pages\ResourcePage;

final class OrderTimeline extends ResourcePage
{
    use InteractsWithRecord;

    protected static string $resource = OrderResource::class;
    protected static ?string $routePath = '{record}/timeline';

    public function render(Request $request, ?string $record = null): Response
    {
        $order = $this->resolveRecord($record);

        return Inertia::render('Panels/Admin/Pages/OrderTimeline', [
            'page' => $this->headingMetadata($order),
            'events' => $order->events()->latest()->get(),
        ]);
    }
}
```

A `null` key means a singular resource: its route carries no `{record}`, so the resource resolves its own row.

## Route shapes

`PanelRouteRegistrar` knows four page keys. Anything else is a custom page: one GET at `ResourcePage::routePath($key)`, which defaults to the key.

| Key | Routes |
| --- | --- |
| `index` | `GET /` → `render`, named `index` |
| `create` | `GET create` → `render` (`create`), `POST create` → `handle` (`store`), `POST create/step` → `validateStep` (`validateCreateStep`) |
| `view` | `GET {record}` → `render`, named `view` |
| `edit` | `GET {record}/edit` → `render` (`edit`), `PUT {record}/edit` → `handle` (`update`), `POST {record}/edit/step` → `validateStep` (`validateEditStep`) |

A singular resource has `{record}` stripped from all of them. A nested resource's group is prefixed `{parentSlug}/{parentRecord}/{slug}` and carries `ResolveParentRecord`. A cluster prefixes the path with the cluster slug; the route name is untouched.

Two resources claiming one path shape throw `PanelRegistrationException::collidingRoutePath()` at boot — parameter names are erased before comparing, because the router does not distinguish `{record}` from `{parentRecord}` either.

## Notes

- **`$softDeletes` is declared *and* corroborated.** `usesSoftDeletes()` returns false unless the model actually uses the `SoftDeletes` trait, so a model using it for something the panel was never meant to expose does not silently grow restore actions.
- **The index still hides trashed records.** Turning `$softDeletes` on changes three things: record pages can reach a trashed record, restore and force-delete become answerable, and `TrashedFilter` has something to reveal. The list waits for the filter.
- **A nested resource has no sidebar entry.** Its pages only exist beneath a parent record, and the sidebar has no parent in hand.
- **A resource with no `index` page has no sidebar entry either.** Building one would link to a route that was never registered, which fails while rendering the sidebar and takes down every page in the panel.
- **`integrationSettings()` is cached per class per request.** The route registrar, the boot-time observer registration, and the page all ask the same question.
- **`ManageRelatedRecords` does not remove the inline table.** A relation with its own page still appears beneath the record; where a manager appears is the page's decision, and a page that wants only some of them overrides `relationTables()`.

## See also

- [Creating resources](../resources/creating-resources.md)
- [CRUD pages](../resources/crud-pages.md)
- [Resource pages](../resources/resource-pages.md)
- [Lifecycle hooks](../resources/lifecycle-hooks.md)
- [Resource queries](../resources/queries.md)
- [Per-panel configuration](../resources/per-panel-configuration.md)
- [Nested resources](../resources/nested-resources.md)
- [Singular resources](../resources/singular-resources.md)
- [Soft deletes](../resources/soft-deletes.md)
- [Global search](../resources/global-search.md)
- [Relation managers](../relations/relation-managers.md)
- [Tables reference](tables.md)
- [Forms reference](forms.md)
- [Infolists reference](infolists.md)
- [Actions reference](actions.md)
