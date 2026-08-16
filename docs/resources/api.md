# Resource API Reference

Every public and protected member of the resource classes, with its real signature. Use it to look something up; the pages linked from each section explain when to reach for it.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Resources\Resource` | The base class a resource extends |
| `PandaPanel\Contracts\ResourceContract` | What the registries and the navigation builder type-hint |
| `PandaPanel\Resources\ResourceConfiguration` | One resource class, configured for one panel |
| `PandaPanel\Resources\Pages\ResourcePage` | The base of every resource page |
| `PandaPanel\Resources\Pages\ListRecords` | The index page |
| `PandaPanel\Resources\Pages\CreateRecord` | The create page |
| `PandaPanel\Resources\Pages\ViewRecord` | The read-only detail page |
| `PandaPanel\Resources\Pages\EditRecord` | The edit page |
| `PandaPanel\Resources\Pages\ManageRelatedRecords` | A page devoted to one of a record's relations |
| `PandaPanel\Resources\Concerns\HasLifecycleHooks` | The hooks a page may override |
| `PandaPanel\Resources\Concerns\InteractsWithRecord` | Record resolution for a custom page |
| `PandaPanel\Search\GlobalSearch` | The service behind the command palette |
| `PandaPanel\Support\ParentRecord` | The bound parent of a nested resource |

`PandaPanel\Resources\RelationManager`, `RelationTable`, and `RelationForm` live in the same namespace and are documented under [Relation managers](../relations/relation-managers.md).

## A resource at its smallest

```php
use App\Models\Post;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

final class PostResource extends Resource
{
    protected static string $model = Post::class;

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('title')->required()]);
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

## `Resource` — properties

All are `protected static`.

| Property | Type | Default | Page |
| --- | --- | --- | --- |
| `$model` | `class-string<Model>` | *required* | [Creating resources](creating-resources.md) |
| `$slug` | `?string` | `null` | [URLs and routes](urls-routes.md) |
| `$label` | `?string` | `null` | [Labels](labels-navigation.md) |
| `$pluralLabel` | `?string` | `null` | [Labels](labels-navigation.md) |
| `$recordTitleAttribute` | `?string` | `null`, meaning `'name'` | [Model binding](model-binding.md) |
| `$navigationLabel` | `?string` | `null` | [Navigation](labels-navigation.md) |
| `$navigationIcon` | `?string` | `null` | [Navigation](labels-navigation.md) |
| `$activeNavigationIcon` | `?string` | `null` | [Navigation](labels-navigation.md) |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | [Navigation](labels-navigation.md) |
| `$navigationSort` | `int` | `0` | [Navigation](labels-navigation.md) |
| `$shouldRegisterNavigation` | `bool` | `true` | [Navigation](labels-navigation.md) |
| `$cluster` | `?class-string<Cluster>` | `null` | [Clusters](../pages-navigation/clusters.md) |
| `$subNavigationPosition` | `?SubNavigationPosition` | `null` | [Sub-navigation](../pages-navigation/sub-navigation.md) |
| `$with` | `list<string>` | `[]` | [Queries](queries.md) |
| `$softDeletes` | `bool` | `false` | [Soft deletes](soft-deletes.md) |
| `$singular` | `bool` | `false` | [Singular resources](singular-resources.md) |
| `$parentResource` | `?class-string<Resource>` | `null` | [Nested resources](nested-resources.md) |
| `$parentRelationship` | `?string` | `null` | [Nested resources](nested-resources.md) |
| `$tenantRelationship` | `?string` | `null` | [Queries](queries.md) |
| `$globalSearchAttributes` | `list<string>` | `[]` | [Global search](global-search.md) |
| `$globalSearchLimit` | `int` | `5` | [Global search](global-search.md) |
| `$globalSearchSort` | `int` | `0` | [Global search](global-search.md) |

## `Resource` — schema

```php
abstract public static function table(TableSchema $table): TableSchema;
abstract public static function form(FormSchema $schema): FormSchema;
abstract public static function pages(): array;                              // array<string, class-string>

public static function infolist(InfolistSchema $schema): InfolistSchema;     // returns it untouched
public static function relationManagers(): array;                            // list<class-string<RelationManager>>, empty
public static function relationManager(string $key): ?string;                // class-string<RelationManager>|null
public static function integrations(Integrations $integrations): Integrations;
public static function integrationSettings(): Integrations;                  // resolved once per class per request
public static function getWidgets(): array;                                  // list<class-string<Widget>>, empty
public static function getHeaderWidgets(string $page): array;                 // index gets getWidgets()
public static function getFooterWidgets(string $page): array;                 // empty
```

`relationManager()` throws `PanelRegistrationException` when two managers answer to one key: the endpoint's answer would otherwise depend on declaration order.

## `Resource` — model and query

```php
public static function getModel(): string;                    // class-string<Model>; throws when $model is unset
public static function query(): Builder;                      // the single entry point for every read
public static function globalSearchQuery(): Builder;          // returns query()
protected static function recordQuery(): Builder;             // query(), soft-delete scope lifted where declared
protected static function applyTenantScope(Builder $q): Builder;
public static function tenantRelationship(): ?string;
public static function usesSoftDeletes(): bool;               // declared and corroborated by the model
```

## `Resource` — records

```php
public static function resolveRecord(int|string $key): Model;      // findOrFail
public static function findRecord(int|string $key): ?Model;
public static function findRecords(array $keys): Collection;       // Collection<int, Model>
public static function resolveSingularRecord(): Model;             // query()->firstOrFail()
public static function recordTitle(Model $record): string;
public static function isSingular(): bool;
```

## `Resource` — nesting

```php
public static function isNested(): bool;
public static function parentResource(): ?string;             // class-string<Resource>|null
public static function parentRelationship(): string;          // camel case of the default slug when undeclared
protected static function parentRelation(): Relation;
```

## `Resource` — identity and URLs

```php
public static function defaultSlug(): string;
public static function defaultLabel(): string;
public static function defaultPluralLabel(): string;

public static function slug(): string;                        // as the current panel configured it
public static function slugIn(?Panel $panel): string;
public static function label(): string;
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

Route names are `panel.{panelId}.resources.{slug}.{page}`.

## `Resource` — navigation

```php
public static function navigationItem(PanelContract $panel): ?NavigationItem;
public static function navigationIcon(): ?string;
public static function activeNavigationIcon(): ?string;       // falls back to navigationIcon()
public static function cluster(): ?string;                    // class-string<Cluster>|null
public static function subNavigationPosition(): ?SubNavigationPosition;
```

## `Resource` — authorization

```php
public static function canViewAny(): bool;                    // viewAny
public static function canView(Model $record): bool;          // view
public static function canCreate(): bool;                     // create
public static function canEdit(Model $record): bool;          // update
public static function canDelete(Model $record): bool;        // delete
public static function canDeleteAny(): bool;                  // deleteAny
public static function canRestore(Model $record): bool;       // restore
public static function canRestoreAny(): bool;                 // restoreAny
public static function canForceDelete(Model $record): bool;   // forceDelete
public static function canForceDeleteAny(): bool;             // forceDeleteAny

protected static function authorize(string $ability, Model|string $argument): bool;
```

## `Resource` — global search

```php
public static function globalSearchAttributes(): array;                    // list<string>
public static function isGloballySearchable(): bool;
public static function globalSearchLimit(): int;
public static function globalSearchSort(): int;
public static function globalSearchResultTitle(Model $record): string;
public static function globalSearchResultDetails(Model $record): array;    // array<string, string>
public static function globalSearchResultUrl(Model $record): string;
```

## `ResourceContract`

The members the registries and the navigation builder rely on. Implemented by `Resource`; a module supplying its own implementation needs exactly these.

```php
public static function slug(): string;
public static function query(): Builder;
public static function pages(): array;
public static function canViewAny(): bool;
public static function navigationItem(PanelContract $panel): ?NavigationItem;
public static function cluster(): ?string;
```

## `ResourceConfiguration`

```php
public readonly string $resource;

public static function for(string $resource): self;

public function slug(string $slug): self;
public function label(string $label): self;
public function pluralLabel(string $pluralLabel): self;
public function navigationLabel(string $navigationLabel): self;
public function navigationGroup(?string $navigationGroup): self;
public function navigationIcon(?string $navigationIcon): self;
public function navigationSort(int $navigationSort): self;
public function registerNavigation(bool $register = true): self;
public function modifyQueryUsing(Closure $callback): self;   // Closure(Builder): Builder

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

See [Per-panel configuration](per-panel-configuration.md).

## `ResourcePage`

Shared by every resource page.

```php
protected static string $resource;                        // class-string<Resource>, required
protected static ?bool $hasDatabaseTransactions = null;   // null inherits the panel
protected static ?string $routePath = null;
protected static ?string $title = null;
protected static ?string $heading = null;
protected static ?string $subheading = null;

public function getTitle(?Model $record = null): string;
public function getHeading(?Model $record = null): string;
public function getSubheading(?Model $record = null): ?string;
protected function defaultTitle(?Model $record): string;
protected function defaultHeading(?Model $record): string;
protected function defaultSubheading(?Model $record): ?string;
protected function headingMetadata(?Model $record = null): array;

public static function routePath(string $key): string;              // defaults to the page key
public static function hasDatabaseTransactions(): ?bool;
public static function renderHookScope(): string;                   // 'resource:{slug}'
public static function resource(): string;                          // class-string<Resource>

public function headerWidgets(): array;                             // list<class-string<Widget>>
public function footerWidgets(): array;
protected function widgetProps(?PageContext $context = null): array;
protected function widgetPageKey(): string;
protected function widgetFilterSessionKey(?PageContext $context = null): string;

protected function fillForm(FormSchema $schema, ?Model $record = null): array;
protected function validateStepFor(Request $r, FormSchema $s, ?Model $record = null): JsonResponse;

protected function panel(): Panel;
protected function dashboardUrl(): string;
protected function resourceMetadata(): array;
protected function actionEndpoints(): array;                        // array<string, string>
protected function recordTitle(Model $record): string;
protected function relationTables(Request $request, Model $record): array;
protected function clusterNavigation(): ?array;
protected function subNavigationPosition(): SubNavigationPosition;
protected function subNavigation(?Model $record, string $currentPage): array;
protected function baseBreadcrumbs(): array;                        // list<Breadcrumb>
protected function parentBreadcrumbs(): array;
protected function recordCrumb(Model $record, string $title): Breadcrumb;
protected function serializeBreadcrumbs(array $crumbs): array;
```

## `ListRecords`

```php
protected static string $component = 'panel/resources/Index';

public function render(Request $request): Response;
public function tabs(): array;                                      // array<string, Tab>, empty
protected function headerActions(): array;
protected function rows(TableSchema $schema, LengthAwarePaginator $records, ?Group $group = null): array;
protected function pagination(LengthAwarePaginator $records): array;
protected function pageMetadata(): array;
```

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
protected function createdNotification(Model $record): ?array;      // ['type' => ..., 'message' => ...]
protected function schema(): FormSchema;
protected function pageMetadata(): array;
```

## `ViewRecord`

```php
protected static string $component = 'panel/resources/View';
protected static string $page = 'view';

public function render(Request $request, ?string $record = null): Response;

protected function entries(Model $record): array;                   // the form-derived fallback
protected function displayValue(Field $field, Model $record): ?string;
protected function headerActions(Model $record): array;
protected function pageMetadata(Model $record): array;
```

Uses `InteractsWithRecord`; authorizes with `canView()`.

## `EditRecord`

```php
protected static string $component = 'panel/resources/Edit';
protected static string $page = 'edit';

public function render(Request $request, ?string $record = null): Response;
public function handle(Request $request, ?string $record = null): RedirectResponse;
public function validateStep(Request $request, ?string $record = null): JsonResponse;

protected function authorizeRecord(Model $record): bool;            // canEdit()
protected function handleRecordUpdate(Model $record, array $attributes): Model;
protected function getRedirectUrl(Model $record): string;
protected function savedNotification(Model $record): ?array;
protected function schema(): FormSchema;
protected function pageMetadata(Model $record): array;
```

## `ManageRelatedRecords`

```php
protected static string $component = 'panel/resources/ManageRelated';
protected static string $relationManager;                           // class-string<RelationManager>, required
protected static string $page = 'relation';

public function render(Request $request, ?string $record = null): Response;
public static function relationManager(): string;
public static function relationPageKey(): string;                   // the manager's key
public static function routePath(string $key): string;              // '{record}/'.$key by default
protected function pageMetadata(Model $owner): array;
```

The page 404s when the resource does not declare the manager it names, and 403s when the manager refuses the owner. See [Relation pages](../relations/relation-pages.md).

## `HasLifecycleHooks`

```php
protected function halt(): never;

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

Order and semantics: [Lifecycle hooks](lifecycle-hooks.md).

## `InteractsWithRecord`

```php
protected function resolveRecord(int|string|null $key = null): Model;   // resolves, authorizes, memoizes
protected function getRecord(): Model;                                  // LogicException before resolution
protected function hasRecord(): bool;
protected function authorizeRecord(Model $record): bool;                // canView() by default
```

## `GlobalSearch` and `GlobalSearchResult`

```php
final class GlobalSearch
{
    public function __construct(private readonly PanelManager $manager) {}

    public function for(Panel $panel, string $term): array;
}

final readonly class GlobalSearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public array $details = [],
    ) {}

    public function toArray(): array;
}
```

`for()` returns `list<array{resource: string, label: string, icon: string|null, results: list<array<string, mixed>>}>`. See [Global search](global-search.md).

## `ParentRecord`

```php
public static function bind(Model $record): void;
public static function current(): ?Model;
public static function require(string $resource): Model;                 // throws when none is bound
public static function routeParameter(): string;                         // 'parentRecord'
public static function resolve(string $parentResource, int|string $key): ?Model;   // scoped and authorized
public static function assertRegistered(string $resource, string $parent): void;
```

## Route names

| Page key | Route name | Verb and path |
| --- | --- | --- |
| `index` | `panel.{id}.resources.{slug}.index` | `GET /` |
| `create` | `...create` | `GET create` |
| `create` | `...store` | `POST create` |
| `create` | `...validateCreateStep` | `POST create/step` |
| `view` | `...view` | `GET {record}` |
| `edit` | `...edit` | `GET {record}/edit` |
| `edit` | `...update` | `PUT {record}/edit` |
| `edit` | `...validateEditStep` | `POST {record}/edit/step` |
| custom | `...{key}` | `GET` at `$routePath`, defaulting to the key |

A [singular resource](singular-resources.md) has `{record}` stripped from every path. A [nested resource](nested-resources.md) has the whole group prefixed with `{parentSlug}/{parentRecord}/`.

## Exceptions

| Exception | Raised when |
| --- | --- |
| `PandaPanel\Exceptions\PanelSchemaException` | `$model` was never declared, or a schema declares duplicate names |
| `PandaPanel\Exceptions\PanelRegistrationException` | Two classes on one slug; a URL asked for in a panel that does not register the resource; no current panel; an unregistered parent resource; no parent record bound; an unknown or non-relation `$tenantRelationship`; two managers on one key; two resources claiming one path |
| `PandaPanel\Exceptions\PanelAuthorizationException` | Under `strictAuthorization()`, a missing policy or a missing policy method |
| `PandaPanel\Exceptions\Halt` | `$this->halt()` — caught by the page, never surfaced |
| `Illuminate\Database\Eloquent\ModelNotFoundException` | `resolveRecord()` found nothing; becomes a 404 |

## See also

- [Creating resources](creating-resources.md)
- [CRUD pages](crud-pages.md)
- [Lifecycle hooks](lifecycle-hooks.md)
- [Resource queries](queries.md)
- [Per-panel configuration](per-panel-configuration.md)
- [Resource authorization](authorization.md)
- [Global search](global-search.md)
- [Contracts reference](../api/contracts.md)
- [Exceptions reference](../api/exceptions.md)
- [Core API reference](../api/core.md)
