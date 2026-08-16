# Resource Pages

Every screen a resource has is a class named in `Resource::pages()`. Four keys — `index`, `create`, `view`, `edit` — have fixed routes and dedicated base classes; every other key is a page you shape yourself. This page covers `PandaPanel\Resources\Pages\ResourcePage`, the base all of them extend, and everything a page beyond the standard four needs: its route, its record, its heading, its widgets, and the metadata it ships to Vue.

## A custom page

Two files. The page, and the key that routes it.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Panels\Admin\Resources\Posts\PostResource;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Resources\Pages\ResourcePage;

final class AuditPost extends ResourcePage
{
    use InteractsWithRecord;

    protected static string $resource = PostResource::class;

    protected static ?string $routePath = '{record}/audit';

    protected static ?string $title = 'Audit';

    public function render(string $record): Response
    {
        $post = $this->resolveRecord($record);

        return Inertia::render('panel/Page', [
            'page' => [
                ...$this->headingMetadata($post),
                'breadcrumbs' => [],
                'headerActions' => [],
                'scope' => static::renderHookScope(),
            ],
            'widgets' => [],
            'revisions' => $post->revisions()->count(),
        ]);
    }
}
```

```php
/**
 * @return array<string, class-string>
 */
public static function pages(): array
{
    return [
        'index' => ListPosts::class,
        'edit' => EditPost::class,
        'audit' => AuditPost::class,
    ];
}
```

That registers `GET /admin/posts/{record}/audit`, named `panel.admin.resources.posts.audit`.

## What a page is

A page is a controller. `PandaPanel\Routing\PanelRouteRegistrar` registers it as `[PageClass::class, 'render']` for the GET and `[PageClass::class, 'handle']` for a write verb — never a closure, which is what keeps `php artisan route:cache` working.

A custom key registers exactly one route:

| Key | Verb | Path | Method | Route name |
| --- | --- | --- | --- | --- |
| anything not `index`/`create`/`view`/`edit` | GET | `ResourcePage::routePath($key)` | `render` | `panel.{id}.resources.{slug}.{key}` |

The four standard keys are covered in [CRUD pages](crud-pages.md); their shapes are fixed and cannot be moved.

`ResourcePage` declares no `render()` of its own and no Vue component. A custom page must define `render()` and call `Inertia::render()` itself, naming a component that exists in the host application or in the package's own page set. See [Inertia pages](../frontend/inertia-pages.md).

## Declarations

Everything on `ResourcePage` that is a static property:

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$resource` | `class-string<Resource>` | required | The resource this page belongs to |
| `$routePath` | `?string` | `null`, meaning the page key | The path the page registers under, relative to the resource |
| `$title` | `?string` | `null`, meaning the page's own default | The document title |
| `$heading` | `?string` | `null`, meaning the title | The heading above the content |
| `$subheading` | `?string` | `null`, meaning the page's own default | The line beneath the heading |
| `$hasDatabaseTransactions` | `?bool` | `null`, meaning the panel's setting | Whether this page's write runs in a transaction |

`$resource` is the only one without a working default. Everything else can be left alone.

### Route paths

```php
public static function routePath(string $key): string;      // static::$routePath ?? $key
```

`$routePath` is relative to the resource's own prefix, and may contain `{record}` for a page that operates on one record — the registrar passes that segment to `render()` exactly as it does for the view and edit pages.

```php
protected static ?string $routePath = '{record}/audit';   // /admin/posts/{record}/audit
protected static ?string $routePath = 'archive';          // /admin/posts/archive
protected static ?string $routePath = null;               // /admin/posts/audit — the page key
```

A path that collides with one another resource has already claimed in the same panel throws `PanelRegistrationException::collidingRoutePath()` at boot. Parameter names are erased before comparing, so `{record}` and `{parentRecord}` count as the same segment — Laravel does not distinguish them either. See [Routing](../concepts/routing.md).

## Reaching the record

A page whose route carries `{record}` adds `PandaPanel\Resources\Concerns\InteractsWithRecord`:

```php
use PandaPanel\Resources\Concerns\InteractsWithRecord;

protected function resolveRecord(int|string|null $key = null): Model;   // resolves, authorizes, remembers
protected function getRecord(): Model;                                  // throws LogicException if none
protected function hasRecord(): bool;
protected function authorizeRecord(Model $record): bool;                // canView() by default
```

Resolution runs through `Resource::query()` like every other lookup, so a record outside the resource scope is a 404 rather than something a custom page can reach around. The record is held for the request, so reading it in three places is one query.

Ask for a different ability by overriding one method:

```php
use Illuminate\Database\Eloquent\Model;

protected function authorizeRecord(Model $record): bool
{
    return static::$resource::canEdit($record);
}
```

A failed check is a 403 raised inside `resolveRecord()`, before the page renders anything. Full detail in [Model binding](model-binding.md).

## Titles, headings and subheadings

```php
public function getTitle(?Model $record = null): string;
public function getHeading(?Model $record = null): string;
public function getSubheading(?Model $record = null): ?string;
```

Each reads its static property first and falls back to the page's own default. The defaults per base class:

| Page | `title` | `heading` | `subheading` |
| --- | --- | --- | --- |
| `ListRecords` | plural label | title | — |
| `CreateRecord` | `New {label}` | title | — |
| `ViewRecord` | record title | title | label |
| `EditRecord` | `Edit {record}` | record title | `Edit {label}` |
| `ManageRelatedRecords` | manager title | title | owner's record title |
| `ResourcePage` | plural label | title | — |

A custom page extending `ResourcePage` directly inherits the resource's plural label as its title, so it has a heading without declaring one. The heading follows the title unless a page separates the two, which only the edit page does: the breadcrumb above already says the page is an edit.

Declare the property when the text is fixed, override the getter when it depends on something a property cannot say:

```php
use Illuminate\Database\Eloquent\Model;

public function getSubheading(?Model $record = null): ?string
{
    return $record === null
        ? null
        : sprintf('%d revisions', $record->revisions()->count());
}
```

The record is passed on pages that have one and is `null` on pages that do not, so a getter must handle both.

### The three keys together

```php
/**
 * @return array{title: string, heading: string, subheading: string|null}
 */
protected function headingMetadata(?Model $record = null): array;
```

Spread it into the page metadata rather than calling the three getters by hand — every built-in page does, which is why none of them repeats the fallback logic.

## The metadata a page ships

Vue reads one `page` prop on every panel screen, typed as `PageMetadata`: `title`, `heading`, `subheading`, `breadcrumbs`, `headerActions`, `scope`, `subNavigation`, `cluster`. `ResourcePage` has a helper for each part.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Support\Breadcrumb;

/**
 * @return array<string, mixed>
 */
protected function pageMetadata(Model $record): array
{
    return [
        ...$this->headingMetadata($record),
        'breadcrumbs' => $this->serializeBreadcrumbs([
            ...$this->baseBreadcrumbs(),
            $this->recordCrumb($record, $this->recordTitle($record)),
            Breadcrumb::make('Audit')->current(),
        ]),
        'headerActions' => [],
        'scope' => static::renderHookScope(),
        'cluster' => $this->clusterNavigation(),
        'subNavigation' => $this->subNavigation($record, 'audit'),
    ];
}
```

| Helper | Signature | What it gives you |
| --- | --- | --- |
| `baseBreadcrumbs()` | `protected function baseBreadcrumbs(): array` | Dashboard → (parent trail) → resource index |
| `parentBreadcrumbs()` | `protected function parentBreadcrumbs(): array` | The owner's trail, empty for a resource that is not nested |
| `recordCrumb()` | `protected function recordCrumb(Model $record, string $title): Breadcrumb` | A crumb linking to the view page, or plain text when there is none or the user may not open it |
| `serializeBreadcrumbs()` | `protected function serializeBreadcrumbs(array $crumbs): array` | The array Vue reads |
| `recordTitle()` | `protected function recordTitle(Model $record): string` | `Resource::recordTitle()` for this page's resource |
| `resourceMetadata()` | `protected function resourceMetadata(): array` | `slug`, `label`, `pluralLabel`, `indexUrl`, `parentKey` |
| `actionEndpoints()` | `protected function actionEndpoints(): array` | The seven action URLs the frontend posts to |
| `clusterNavigation()` | `protected function clusterNavigation(): ?array` | The cluster bar, or null |
| `subNavigation()` | `protected function subNavigation(?Model $record, string $currentPage): array` | The links between this record's pages, plus their position |
| `panel()` | `protected function panel(): Panel` | The current panel, or `PanelRegistrationException` |
| `dashboardUrl()` | `protected function dashboardUrl(): string` | The panel's own dashboard |
| `relationTables()` | `protected function relationTables(Request $request, Model $record): array` | Every relation manager the resource declares, authorized |

`actionEndpoints()` is on every resource page rather than only the list, because a view page's infolist carries actions too, and they run through the same set. See [Actions](../actions/overview.md).

### Sub-navigation

```php
protected function subNavigation(?Model $record, string $currentPage): array;
protected function subNavigationPosition(): SubNavigationPosition;
```

The items are built by `PandaPanel\Support\RecordSubNavigation` from the resource's own `pages()` map: a `view` key when `canView()` allows it, an `edit` key when `canEdit()` does, plus every `ManageRelatedRecords` page whose manager allows `canViewAny()`. One link is not navigation, so a record that can reach only one page gets an empty list.

Custom keys do not appear there. The map inside `RecordSubNavigation` holds `view` and `edit` and nothing else, and a relation page is discovered through the manager it names — a custom page has no ability the map could ask about. Link to it from a header action or a table row action instead.

The position comes from `Resource::subNavigationPosition()` when the resource states one and from the panel otherwise. See [Sub-navigation](../pages-navigation/sub-navigation.md).

### Render hooks

```php
public static function renderHookScope(): string;   // 'resource:{slug}'
```

Every page of a resource shares its scope, so a hook scoped to `resource:posts` appears on its list, view, create, edit and custom pages alike. It is a slug, never a class name: nothing in page metadata may name a PHP class. See [Render hooks](../panels/render-hooks.md).

## Page widgets

Any resource may declare widgets once, and any resource page may place widgets above or below its own content.

```php
use PandaPanel\Resources\Resource;
use PandaPanel\Widgets\Widget;

final class PostResource extends Resource
{
    /** @return list<class-string<Widget>> */
    public static function getWidgets(): array
    {
        return [PostsThisWeek::class];   // header of the index page
    }

    /** @return list<class-string<Widget>> */
    public static function getHeaderWidgets(string $page): array
    {
        return $page === 'view'
            ? [PostEngagement::class]
            : parent::getHeaderWidgets($page);
    }
}

/**
 * @return list<class-string<Widget>>
 */
public function headerWidgets(): array
{
    return [
        ...parent::headerWidgets(),
        DraftWarnings::class,
    ];
}

/**
 * @return list<class-string<Widget>>
 */
public function footerWidgets(): array
{
    return [];
}
```

`Resource::getWidgets()` is an index-page shortcut. `Resource::getHeaderWidgets($page)` and `Resource::getFooterWidgets($page)` default to that behavior and `[]`, respectively. Page methods can override or merge with `parent::headerWidgets()` and `parent::footerWidgets()`.

The page turns them into props with one call:

```php
use PandaPanel\Widgets\PageContext;

protected function widgetProps(?PageContext $context = null): array;
```

It returns three keys — `headerWidgets`, `footerWidgets`, `widgetData` — which is what the resource page components read. Spread it into the render:

```php
return Inertia::render('panel/resources/View', [
    'page' => $this->pageMetadata($record),
    ...$this->widgetProps(PageContext::forRecord($record)),
    // ...
]);
```

### The context

`PandaPanel\Widgets\PageContext` is what separates a page widget from a dashboard widget: a list page hands over its own query, a record page hands over its record.

```php
use PandaPanel\Widgets\PageContext;

// What the page hands over.
PageContext::forRecord($record);                        // a record page
PageContext::forQuery(fn () => PostResource::query());  // a list page

// What the widget reads, through Widget::context().
$this->context()->record();   // ?Model — null on a list page
$this->context()->query();    // ?Builder — null on a record page
$this->context()->count();    // int — memoized, so three widgets share one query
```

`Widget::context()` throws when the widget was rendered without one: a widget reading a record it was never given is on the wrong page, and saying so is more useful than a zero. See [Widgets](../widgets/overview.md).

## Pages that carry a form

Two helpers on the base do the work `CreateRecord` and `EditRecord` do, and are available to any page that renders a `FormSchema`.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PandaPanel\Forms\FormSchema;

protected function fillForm(FormSchema $schema, ?Model $record = null): array;
protected function validateStepFor(Request $request, FormSchema $schema, ?Model $record = null): JsonResponse;
```

`fillForm()` serializes the schema and runs the fill hooks — `beforeFill`, `mutateFormDataBeforeFill`, `afterFill` — over a flat `name => value` map, so a page shaping one field does not have to know how the schema is nested. See [Lifecycle hooks](lifecycle-hooks.md).

`validateStepFor()` validates one wizard step and answers JSON. A form with no wizard answers 400 rather than pretending to check something, and an out-of-range step index answers 422.

## Transactions

```php
protected static ?bool $hasDatabaseTransactions = null;

public static function hasDatabaseTransactions(): ?bool;
```

`null` inherits the panel. Set it on a page whose write differs from the rest of the panel — one that also calls an external service, say, where holding a transaction open is worse than not having one. The persist step and the `after*` hooks share that transaction, so a hook that throws rolls the write back.

## Relation pages

`PandaPanel\Resources\Pages\ManageRelatedRecords` gives one of a record's relations a page of its own — the same manager a record page shows inline, with its own route and its own place in the record's sub-navigation.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Panels\Admin\Resources\Posts\PostResource;
use App\Panels\Admin\Resources\Posts\RelationManagers\CommentsRelationManager;
use PandaPanel\Resources\Pages\ManageRelatedRecords;

final class ManagePostComments extends ManageRelatedRecords
{
    protected static string $resource = PostResource::class;

    protected static string $relationManager = CommentsRelationManager::class;
}
```

```php
public static function pages(): array
{
    return [
        'index' => ListPosts::class,
        'view' => ViewPost::class,
        'edit' => EditPost::class,
        'comments' => ManagePostComments::class,
    ];
}
```

| Member | Signature | Behaviour |
| --- | --- | --- |
| `$relationManager` | `protected static string` | Required. The manager this page shows |
| `relationManager()` | `public static function relationManager(): string` | The class above |
| `relationPageKey()` | `public static function relationPageKey(): string` | `$relationManager::key()`, which is what sub-navigation matches on |
| `routePath()` | `public static function routePath(string $key): string` | `'{record}/'.$key` unless `$routePath` says otherwise |

The page is not a second registration. It aborts 404 unless `Resource::relationManagers()` already lists the manager it names, so a page cannot be a way to reach a relation the resource never declared. It renders `panel/resources/ManageRelated`, and answers 403 when the manager refuses the owner.

`php artisan make:panel-relation-manager comments --panel=Admin --resource=Post --page` generates the manager and this page together. See [Relation pages](../relations/relation-pages.md).

## Several record pages

Several pages on one record is several keys. Each gets its own route name and its own path:

```php
public static function pages(): array
{
    return [
        'index' => ListPosts::class,
        'view' => ViewPost::class,
        'edit' => EditPost::class,
        'audit' => AuditPost::class,          // GET {record}/audit
        'history' => PostHistory::class,      // GET {record}/history
        'comments' => ManagePostComments::class,
    ];
}
```

They share the resource's scope, its labels, its policy, and its render hook scope. Nothing else about them has to agree.

## Notes

- **A custom page has no `handle()` route.** Only one GET is registered per custom key. A page that needs to write should post to an [action endpoint](../actions/overview.md) or to a route of your own.
- **`ResourcePage` names no Vue component.** The four standard pages each declare a `$component`; a custom page passes its own to `Inertia::render()`.
- **`render()` receives route parameters by name.** A page whose `$routePath` contains `{record}` declares `render(string $record)`, or `render(Request $request, ?string $record = null)` if it also wants the request.
- **`getRecord()` before `resolveRecord()` is a `LogicException`.** Resolve once at the top of `render()`.
- **Custom pages do not appear in record sub-navigation.** Only `view`, `edit`, and relation pages do.
- **Two resources claiming one path shape fail at boot**, not at request time — including a `ManageRelatedRecords` page and a nested resource that would share a shape. Two *pages of the same resource* sharing a shape do not throw: the claim is recorded against the resource, so one page silently shadows the other.
- **Widget props are three keys, not one.** A component that reads `widgets` (like `panel/Page`) will not see `headerWidgets` and `footerWidgets`.

## See also

- [List, create, view and edit pages](crud-pages.md)
- [Creating resources](creating-resources.md)
- [Model binding](model-binding.md)
- [Lifecycle hooks](lifecycle-hooks.md)
- [URLs and route names](urls-routes.md)
- [Singular resources](singular-resources.md)
- [Nested resources](nested-resources.md)
- [Relation pages](../relations/relation-pages.md)
- [Widgets](../widgets/overview.md)
- [Sub-navigation](../pages-navigation/sub-navigation.md)
- [Render hooks](../panels/render-hooks.md)
- [Routing](../concepts/routing.md)
- [Inertia pages](../frontend/inertia-pages.md)
