# Comparison With Filament Concepts

A translation table for developers arriving from Filament. The vocabulary was borrowed on purpose,
so most concepts have a direct counterpart here; none of the code was, so nothing is
copy-compatible. Read this to map what you already know onto the class names in this package, and to
see where the two deliberately part.

## The same resource, written here

```php
namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'users';

    protected static ?string $navigationGroup = 'User Management';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required(),
        ]);
    }

    /** @return array<string, class-string> */
    public static function pages(): array
    {
        return [
            'index' => ListUsers::class,
            'create' => CreateUser::class,
            'view' => ViewUser::class,
            'edit' => EditUser::class,
        ];
    }
}
```

The shape is familiar. Every namespace is `PandaPanel\*`, every schema class is this package's own,
and the renderer behind it is a Vue SFC in your `resources/js`.

## Concept mapping

| Concept | Here |
| --- | --- |
| Panel | `PandaPanel\Core\Panel`, configured in `PandaPanel\Core\PanelProvider::panel()` |
| Panel registration | `config/panda-panel.php` → `panels` |
| Resource | `PandaPanel\Resources\Resource` |
| Resource pages | `PandaPanel\Resources\Pages\{ListRecords, CreateRecord, ViewRecord, EditRecord}` |
| Table | `PandaPanel\Tables\TableSchema` + `PandaPanel\Tables\Columns\*` |
| Table filters | `PandaPanel\Tables\Filters\*` |
| Form | `PandaPanel\Forms\FormSchema` + `PandaPanel\Forms\Components\*` |
| Form layout | `PandaPanel\Forms\Layouts\{Section, Grid, Tabs, Wizard, ...}` |
| Infolist | `PandaPanel\Infolists\InfolistSchema` + `PandaPanel\Infolists\Components\*` |
| Action | `PandaPanel\Actions\Action` and the built-ins beside it |
| Bulk action | the same class, declared in `->bulkActions([...])` |
| Relation manager | `PandaPanel\Resources\RelationManager` |
| Cluster | `PandaPanel\Clusters\Cluster` |
| Widget | `PandaPanel\Widgets\{StatsWidget, TableWidget, ChartWidget, CustomWidget}` |
| Custom page | `PandaPanel\Pages\Page` |
| Global search | `protected static array $globalSearchAttributes` + `$panel->globalSearch()` |
| Notifications | `PandaPanel\Notifications\Notification`, `PandaPanel\Broadcasting\PanelNotification` |
| Tenancy | `$panel->tenant()` + `protected static ?string $tenantRelationship` |
| Plugin | `PandaPanel\Plugins\Plugin` |
| Render hook | `$panel->renderHook(RenderHook::HeaderEnd, 'Panels/Admin/Hooks/Announcement')` |
| Generators | `make:panel`, `make:panel-resource`, `make:panel-page`, `make:panel-widget`, `make:panel-relation-manager` |
| Deploy-time cache | `php artisan panel:cache`, registered as an `optimize` hook |

## What was borrowed, and what was not

Borrowed: the **vocabulary**, the fluent schema builders, the resource page split, and the discovery
model. Filament's developer experience for Panel, Resource, Page, Widget, Navigation, Form, Table
and Action was the explicit target.

Not borrowed: any code. Filament renders through Livewire, and this application's frontend is Vue
with shadcn-vue and strict TypeScript. Running Livewire for the panel and Vue for everything else
would mean two component models, two state models and two build stories in one application. Copying
Filament's source was also rejected: the value being borrowed is the *shape* of the API, not an
implementation built around Livewire's lifecycle.

That has one practical consequence worth stating first: **no Filament plugin, theme or custom field
works here.** They are Livewire components. A field, column or widget you need has to be written
against this package's classes, with a Vue component under `resources/js/pages/Panels/**`.

## Where the two deliberately part

### Setters keep bare names; readers are `get`-prefixed

```php
$panel->id('admin');        // setter
$panel->getId();            // reader
$panel->path('admin');
$panel->getPath();
```

PHP cannot overload, and a combined setter/getter returning `string|static` is exactly the kind of
magic this framework avoids. It is more verbose in places; that is the trade.

### Panels are listed, their contents are discovered

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

Registration order is visible in one place, and it decides where a signed-in user lands when the
request does not name a panel. Inside a panel nothing is listed: resources, pages and widgets are
discovered from declared paths, and explicit registration still works and merges with discovery.

### Pages are real controllers

Resource pages are routed as `[Page::class, 'render']` for the GET and `[Page::class, 'handle']` for
the write verb. There is no component lifecycle, no server-held component state, and no closure in
any route — which is what keeps `php artisan route:cache` working.

The write verbs get their own route names, because Laravel requires names to be unique:
`resources.users.store` for the create POST and `resources.users.update` for the edit PUT.

### One action endpoint per panel

An action request carries an action name, a resource slug and record keys. Nothing else. The backend
resolves the panel, looks the resource up in **that panel's** registry, finds the action in that
resource's schema, loads records through `Resource::query()`, authorizes, and only then runs the
handler.

```text
panel.{id}.actions.record    panel.{id}.actions.bulk      panel.{id}.actions.table
panel.{id}.actions.infolist  panel.{id}.actions.cell      panel.{id}.actions.reorder
panel.{id}.actions.form      panel.{id}.actions.submit
```

Each resolves against the schema that declared the action it names, and those are separate
whitelists on purpose: a view page's actions are not a table's.

An action's form is fetched when its dialog opens rather than serialized into every row:

```php
Action::make('note')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('note')->required()->maxLength(1000),
    ]))
    ->action(static function (Model $record, array $data): void {
        $record->notes()->create(['body' => $data['note']]);
    });
```

A table of twenty records would otherwise ship twenty copies of the same form to open at most one.

### Render hooks name a Vue component, never markup

Filament's render hooks inject Blade. Nothing renderable crosses the wire in this framework, so a
hook names a build-time registry key and carries serializable props:

```php
use PandaPanel\Enums\RenderHook;

$panel->renderHook(
    RenderHook::HeaderEnd,
    'Panels/Admin/Hooks/Announcement',   // resources/js/pages/Panels/Admin/Hooks/Announcement.vue
    ['message' => 'Maintenance at 5pm'],
    [UserResource::class],               // optional scope
);
```

The enum is closed on purpose — a hook registered against a name the shell does not render would
silently do nothing. An unregistered *component* name renders nothing rather than throwing: a
decorative injection must not be able to break the page it decorates. Scopes are reduced to slugs at
registration, so `UserResource::class` becomes `resource:users` and no class name is ever serialized.

### There is no `spa()`

Inertia is already a single-page application, so the setting has nothing to turn on. What carries
over from Filament's version of it is prefetching, and URL exceptions — which here mean links that
must leave the SPA:

```php
$panel
    ->prefetch('hover')                       // 'hover' (default), 'mount', 'click', or false
    ->fullPageUrls('/admin/exports/*');       // a real browser navigation
```

Matching happens on the server, so the decision arrives with the link rather than being re-derived
on every render. A full-page item renders a plain anchor and is never prefetched — there would be no
point fetching a document the client cannot use.

### Errors are toasts the panel ships

```php
$panel
    ->errorNotification(403, 'Not allowed', 'Ask an administrator.')
    ->hideErrorNotification(404);
```

Defaults cover 403, 404, 419, 429, 500 and 503. Three outcomes: an entry shows a toast and
suppresses Inertia's error overlay, an entry set to null suppresses both silently, and a status with
no entry is left to Inertia — a status the panel has nothing to say about is better shown raw than
swallowed.

### The table is server-side, and the URL is its state

```text
/admin/users?search=ada&sort=name&direction=asc&perPage=25&page=2&filters[verified]=true&tab=admins
```

Each interaction is a server round trip. In exchange there is no duplicated client store, and back,
forward, refresh and bookmark work. TanStack Table is registered for the column model, visibility
and row selection only.

### Charts are dependency-free SVG

`ChartVariant` covers `Bar`, `Line`, `Area` and `Doughnut`; `ChartOptions` covers legend, grid,
stacked, filled, curved, point labels, a pinned range and a value format. No charting library is
installed, so there are no tooltips, no zoom and no animation. Anything beyond `ChartOptions` is a
`CustomWidget`, which is honest about being bespoke.

## Things you may be looking for that are not here

| | Status |
| --- | --- |
| Livewire, Blade, Alpine | not used anywhere; every screen is an Inertia response |
| Filament plugins, themes, custom fields | incompatible by construction |
| Client-side sorting, filtering, pagination | server-side by design |
| A charting library | replaced by inline SVG; see above |
| React or Svelte renderers | the server half is framework-agnostic, but none is written and none is planned |
| A browser test runner | client-side interaction is covered by types, the build, and server-side request tests |
| `Select::relationship()` coverage | implemented, but covered by types and guard clauses rather than a feature test — no model in `examples/` has a relation worth selecting |

## Notes

- `Action::form(Closure)` and `Action::schema(Closure)` are two different things here.
  `schema()` gives the action a form of its own, built per record and fetched when the dialog opens.
  `form()` points the dialog at a URL you produce. Reach for `schema()` unless you are wiring a
  bespoke endpoint.
- A resource class may be registered in several panels and mean something different in each, through
  `PandaPanel\Resources\ResourceConfiguration`. It may **not** be registered twice inside one panel:
  a panel keys resources by slug and `Resource::url()` would be ambiguous.
- Cluster prefixes change the path and never the route name, so adopting a cluster is a non-breaking
  change: every `Resource::url()` already written keeps working.
- Authorization is ordinary Laravel policies. Nothing in a policy needs to know a panel exists, and
  a freshly generated resource 403s until its model has one.

## See also

- [Why Panda Panel](why-panda-panel.md) — the reasoning behind these departures
- [Feature Overview](features.md) — the full class list to map onto
- [Architecture at a Glance](architecture.md) — routing, middleware and registries
- [Inertia and Vue Approach](inertia-vue.md) — what replaces Livewire
- [Package Limits and Tradeoffs](tradeoffs.md) — the costs, stated
- [Actions](../actions/overview.md), [Render Hooks](../panels/render-hooks.md), [Clusters](../pages-navigation/clusters.md)
