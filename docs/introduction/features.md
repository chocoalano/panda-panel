# Feature Overview

Everything the package ships, by class name, so you can tell at a glance whether a thing exists
before going looking for its page. Each section links to the guide that documents it properly. If a
class is not named here, it is not in the package.

## The smallest complete panel

```php
namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Admin')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'));
    }
}
```

Registered in `config/panda-panel.php` under `panels`. Everything below is reached from that one
class.

## Panels

`PandaPanel\Core\Panel`, configured through `PandaPanel\Core\PanelProvider::panel()`.

| Group | Methods |
| --- | --- |
| Identity | `id`, `name`, `path`, `domain` |
| Access | `middleware`, `authMiddleware`, `auth`, `canAccess`, `requireTwoFactor` |
| Front door | `login`, `registration`, `passwordReset`, `emailVerification` |
| Registration | `resources`, `pages`, `widgets`, `discoverResources`, `discoverPages`, `discoverWidgets` |
| Presentation | `navigationGroups`, `brandName`, `brandLogo`, `icon`, `favicon`, `darkMode`, `colors`, `cssHooks`, `maxContentWidth` |
| Shell | `sidebar`, `topNavigation`, `sidebarWidth`, `collapsedSidebarWidth`, `navigation`, `topbar`, `breadcrumbs`, `sidebarComponent`, `topbarComponent`, `userMenuItems` |
| Landing page | `dashboard`, `dashboards` |
| Built-ins | `settings`, `notifications`, `broadcasting`, `globalSearch` |
| Behaviour | `databaseTransactions`, `strictAuthorization`, `unsavedChangesAlerts`, `bootUsing`, `configureActions` |
| Navigation behaviour | `prefetch`, `fullPageUrls`, `errorNotification`, `hideErrorNotification` |
| Extension | `renderHook`, `subNavigationPosition`, `assets`, `plugins` |
| Tenancy | `tenant`, `tenantUrlUsing` |

Readers are `get`-prefixed (`getId()`, `getPath()`, `getSidebar()`); setters keep the bare name.
Discovery paths, navigation groups, assets and render hooks accumulate rather than overwrite.

```php
$panel
    ->databaseTransactions()          // on by default
    ->strictAuthorization()           // off by default
    ->unsavedChangesAlerts()          // on by default
    ->prefetch('hover')               // 'hover' (default), 'mount', 'click', or false
    ->sidebar(collapsible: true, defaultOpen: true, variant: 'sidebar', appearance: 'inset')
    ->globalSearch(enabled: true, limit: 50, debounce: 300, keyBindings: ['mod+k'])
    ->errorNotification(403, 'Not allowed', 'Ask an administrator.')
    ->hideErrorNotification(404);
```

→ [Defining Panels](../panels/defining-panels.md), [Panel API](../panels/api.md)

## Resources

`PandaPanel\Resources\Resource`. One class per model per panel.

| Member | Signature |
| --- | --- |
| model | `protected static string $model` |
| slug, labels | `protected static ?string $slug`, `$navigationLabel`, `$pluralLabel` |
| navigation | `$navigationIcon`, `$navigationGroup`, `$navigationSort`, `$activeNavigationIcon`, `$cluster` |
| eager loads | `protected static array $with` |
| soft deletes | `protected static bool $softDeletes` |
| tenancy | `protected static ?string $tenantRelationship` |
| global search | `protected static array $globalSearchAttributes`, `$globalSearchLimit`, `$globalSearchSort` |
| schemas | `table(TableSchema): TableSchema`, `form(FormSchema): FormSchema`, `infolist(InfolistSchema): InfolistSchema` |
| routing | `pages(): array`, `relationManagers(): array`, `integrations(Integrations): Integrations` |
| query | `query(): Builder`, `globalSearchQuery(): Builder` |
| lookups | `resolveRecord($key): Model`, `findRecord($key): ?Model`, `findRecords(array $keys): Collection` |
| urls | `url(string $page = 'index', $record = null, $panel = null, $parent = null): string`, `routeName(string $page = 'index', $panel = null): string` |
| authorization | `canViewAny`, `canView`, `canCreate`, `canEdit`, `canDelete`, `canDeleteAny`, `canRestore`, `canRestoreAny`, `canForceDelete`, `canForceDeleteAny` |

`PandaPanel\Resources\ResourceConfiguration::for(UserResource::class)` reconfigures one class per
panel: `slug`, `label`, `pluralLabel`, `navigationLabel`, `navigationGroup`, `navigationIcon`,
`navigationSort`, `registerNavigation`, `modifyQueryUsing`.

Resource pages, all in `PandaPanel\Resources\Pages`: `ListRecords`, `CreateRecord`, `ViewRecord`,
`EditRecord`, `ManageRelatedRecords`, and the `ResourcePage` base.

→ [Creating Resources](../resources/creating-resources.md), [Resource Pages](../resources/resource-pages.md)

## Tables

`PandaPanel\Tables\TableSchema`, plus `TableQuery` for the URL-driven state and `ArrayTableData` for
tables over data that is not in the database.

Columns — `PandaPanel\Tables\Columns\*`:

| Class | Renders |
| --- | --- |
| `TextColumn` | text, with `formatUsing`, `tooltip`, `url` |
| `NumberColumn` | formatted numbers |
| `BadgeColumn` | a mapped badge via `colors()` and `labels()` |
| `BooleanColumn` | a yes/no mark |
| `DateColumn`, `DateTimeColumn` | formatted dates |
| `ImageColumn` | an image, `circular()` |
| `IconColumn` | a registry icon |
| `ColorColumn` | a colour swatch |
| `CustomColumn` | your own Vue component |
| `ToggleColumn`, `CheckboxColumn`, `TextInputColumn`, `SelectColumn` | editable cells, extending the abstract `EditableColumn` |

Filters — `PandaPanel\Tables\Filters\*`: `SelectFilter`, `TernaryFilter`, `BooleanFilter`,
`DateFilter`, `FormFilter`, `QueryBuilderFilter`, `TrashedFilter`, and the abstract `Filter`.
The query builder filter is composed from `Constraints\{TextConstraint, NumberConstraint,
DateConstraint, BooleanConstraint}`.

Summaries — `PandaPanel\Tables\Summaries\*`: `Count`, `Sum`, `Average`, `Range`, and the abstract
`Summarizer`. Grouping is `PandaPanel\Tables\Group`; filter tabs are `PandaPanel\Tables\Tab`.

Enums — `PandaPanel\Tables\Enums\*`: `ColumnType`, `BadgeColor`, `Alignment`, `ColumnPin`,
`FilterType`, `ConstraintOperator`, `RecordActionsPosition`, `RelationshipAggregate`,
`SortDirection`.

```php
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\TernaryFilter;

$table
    ->columns([
        TextColumn::make('name')->searchable()->sortable()->toggleable(false),
        TextColumn::make('email')->searchable(),
    ])
    ->filters([TernaryFilter::make('verified')])
    ->defaultSort('created_at', SortDirection::Descending);
```

State lives in the query string: `search`, `sort`, `direction`, `perPage`, `page`, `filters[...]`,
`columns`, `group`, `tab`. The schema is the whitelist; anything unrecognised is ignored.

→ [TableSchema Basics](../tables/overview.md), [Columns](../tables/columns.md), [Filters](../tables/filters.md)

## Forms

`PandaPanel\Forms\FormSchema`.

Fields — `PandaPanel\Forms\Components\*`, all extending the abstract `Field`:

| Text and numbers | Choice | Date and time | Rich | Composite |
| --- | --- | --- | --- | --- |
| `TextInput` | `Select` | `DatePicker` | `RichEditor` | `Repeater` |
| `Textarea` | `Radio` | `DateTimePicker` | `MarkdownEditor` | `Builder` |
| `NumberInput` | `Checkbox` | `TimePicker` | `CodeEditor` | `KeyValue` |
| `PasswordInput` | `CheckboxList` | | `FileUpload` | `TagsInput` |
| `HiddenInput` | `Toggle` | | `ColorPicker` | `CustomField` |
| | `ToggleButtons` | | `Slider` | |

Layouts — `PandaPanel\Forms\Layouts\*`: `Section`, `Grid`, `Tabs`, `Tab`, `Wizard`, `Step`,
`Callout`, `EmptyState`, `Relationship`, `CustomComponent`.

Prime (display-only) — `PandaPanel\Forms\Prime\*`: `Text`, `Icon`, `Image`.

Enums — `PandaPanel\Forms\Enums\*`: `FieldType`, `CalloutTone`, `CodeLanguage`, `ConditionOperator`.

Every field separates rendering, validation and persistence:

```php
use PandaPanel\Forms\Components\TextInput;

TextInput::make('name')
    ->label('Full name')
    ->required()
    ->maxLength(255)
    ->rules(['alpha_dash'])
    ->hiddenOn(['view'])
    ->live(onBlur: true)
    ->dehydrateTo('display_name');
```

→ [FormSchema Basics](../forms/overview.md), [Validation](../forms/validation.md), [State Lifecycle](../forms/state-lifecycle.md)

## Infolists

`PandaPanel\Infolists\InfolistSchema`, rendered by `ViewRecord`.

Entries — `PandaPanel\Infolists\Components\*`, extending the abstract `Entry`: `TextEntry`,
`BadgeEntry`, `BooleanEntry`, `DateTimeEntry`, `KeyValueEntry`, `IconEntry`, `ImageEntry`,
`ColorEntry`, `CodeEntry`, `RepeatableEntry`, `CustomEntry`.

Layouts — `PandaPanel\Infolists\Layouts\*`: `Section`, `Grid`, `Tabs`, `Tab`. The discriminant is
`PandaPanel\Infolists\Enums\EntryType`.

→ [Infolists](../infolists/overview.md), [Entries](../infolists/entries.md)

## Actions

`PandaPanel\Actions\Action` and the built-ins beside it.

| Kind | Classes |
| --- | --- |
| CRUD | `CreateAction`, `ViewAction`, `EditAction`, `DeleteAction`, `ReplicateAction` |
| Soft deletes | `RestoreAction`, `ForceDeleteAction` |
| Bulk | `DeleteBulkAction`, `RestoreBulkAction`, `ForceDeleteBulkAction` |
| Data | `ImportAction`, `ExportAction` |
| Relations | `PandaPanel\Actions\Relations\{AttachAction, DetachAction, DetachBulkAction, AssociateAction, DissociateAction, CreateRelatedAction, EditRelatedAction, DeleteRelatedAction, RestoreAction, RestoreBulkAction, ForceDeleteAction, ForceDeleteBulkAction}` |

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

Action::make('approve')
    ->label('Approve')
    ->icon('check')
    ->variant(ActionVariant::Default)
    ->requiresConfirmation(heading: 'Approve this record?')
    ->successMessage('Record approved.')
    ->action(static fn (Model $record) => $record->approve());
```

Three kinds: a link (`->url()`), a callback (`->action()`), and a form (`->schema()`) whose dialog
is fetched when it opens. Modal behaviour lives on `PandaPanel\Actions\Support\Modal`. Enums:
`ActionType`, `ActionVariant`, `ModalWidth`, `SpreadsheetFormat`.

→ [Actions](../actions/overview.md), [Modals](../actions/modals.md), [Action Forms](../actions/forms.md)

## Relations

`PandaPanel\Resources\RelationManager`, with `RelationTable` and `RelationForm` for its two schemas,
and `ManageRelatedRecords` for a relation on a page of its own. A resource may also be nested under
another through `parentResource()`, in which case `ResolveParentRecord` binds the parent before any
controller runs.

→ [Relation Managers](../relations/relation-managers.md), [Nested vs Relation Manager](../relations/nested-vs-relation-manager.md)

## Widgets

Four types, all extending `PandaPanel\Widgets\Widget`:

| Type | Base class | Implements |
| --- | --- | --- |
| stats | `StatsWidget` | `stats(): list<Stat>` |
| table | `TableWidget` | `table(TableSchema)`, `query(): Builder` |
| chart | `ChartWidget` | `labels(): list<string>`, `series(): list<ChartSeries>` |
| custom | `CustomWidget` | `$component`, `data(): array` |

Support classes — `PandaPanel\Widgets\Support\*`: `Stat`, `ChartSeries`, `ChartOptions`,
`ColumnSpan`, `WidgetFilters`. Enums: `ChartVariant`, `StatColor`, `WidgetType`.

```php
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;
use PandaPanel\Widgets\Enums\StatColor;

final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2];

    /** @return list<Stat> */
    public function stats(): array
    {
        return [
            Stat::make('Total users', User::query()->count())->icon('users'),
            Stat::make('Verified', User::query()->whereNotNull('email_verified_at')->count())
                ->color(StatColor::Success)
                ->chart([4, 9, 7, 12, 18, 21]),
        ];
    }
}
```

`canView()` is checked before `data()` runs, so an unauthorized widget never executes a query.
Charts are drawn by dependency-free inline SVG; no charting library is installed.

→ [Widgets](../widgets/overview.md), [Charts](../widgets/charts.md), [Filters](../widgets/filters.md)

## Pages and navigation

`PandaPanel\Pages\Page` is a standalone page: no records, no table. `PandaPanel\Pages\Dashboard` is
the default panel root, and `dashboards([...])` registers more. The three account pages in
`PandaPanel\Pages\Settings` — `ProfileSettings`, `SecuritySettings`, `AppearanceSettings` — ship
with every panel unless `settings(false)` says otherwise.

`PandaPanel\Clusters\Cluster` groups resources and pages under one path prefix without changing any
route name. `PandaPanel\Enums\RenderHook` names the eight injection points, `SubNavigationPosition`
the three record sub-navigation positions, and `ClusterPosition` where a cluster's own bar sits.

→ [Custom Pages](../pages-navigation/custom-pages.md), [Clusters](../pages-navigation/clusters.md), [Render Hooks](../panels/render-hooks.md)

## Authentication

Fortify owns every auth endpoint. A panel may grow its own front door at its own path, carrying its
own brand, whose forms post to Fortify's routes:

```php
$panel->login()->registration()->passwordReset()->emailVerification()->requireTwoFactor();
```

Emailed codes as a second factor are `PandaPanel\Auth\{EmailCodeChallenge, EmailCodeFactor}`,
enforced by the `RequireEmailCode` middleware. `RequireTwoFactor` enforces a panel's
`requireTwoFactor()`. `PandaPanel\Contracts\PanelUser` is an optional contract on the user model —
a rule about the *account* asked alongside each panel's own `canAccess`, and both must agree.

`php artisan panel:user` creates an account that can sign in.

→ [Fortify](../authentication/fortify.md), [Two Factor](../authentication/two-factor.md), [User Model](../authentication/user-model.md)

## Tenancy

```php
use Illuminate\Http\Request;

$panel->tenant(Team::class, fn (Request $request) => Team::query()
    ->where('slug', $request->route('team'))
    ->first());

$panel->tenantUrlUsing(fn (Team $team) => "https://{$team->slug}.example.com/app");
```

A resource opts in by naming the relationship that leads to the tenant
(`protected static ?string $tenantRelationship`). The user model implements
`PandaPanel\Contracts\HasPanelTenants`; a tenant model may implement
`PandaPanel\Contracts\PanelTenant`. `PandaPanel\Tenancy\Tenancy` is the entry point outside a
request. `ResolveTenant` middleware is added only to a panel that declared tenancy.

→ [Tenancy Concepts](../tenancy/concepts.md), [Resource Scoping](../tenancy/resource-scoping.md)

## Notifications

`PandaPanel\Notifications\Notification` builds one; `NotificationAction` adds a button to it;
`PandaPanel\Broadcasting\PanelNotification` dispatches one from anywhere, including a queued job.
`PanelDatabaseNotification` is the persisted form the bell reads, and `ShareFlashToast` maps
Laravel's conventional flash keys onto the same single toast channel.

```php
PanelNotification::dispatch($user, 'Export finished', 'success');
```

The bell's endpoints are `panel.{id}.notifications.{index,read,clear}`. Broadcasting is on by
default and costs no connection until a component subscribes; `broadcasting(false)` turns it off.

→ [Toast](../notifications/toast.md), [Notification Centre](../notifications/notification-center.md), [Broadcasting](../notifications/broadcasting.md)

## Global search

A command palette above every panel page, opened with `mod+k`. Opt-in per resource:

```php
protected static array $globalSearchAttributes = ['name', 'email', 'author.name'];
```

`PandaPanel\Search\{GlobalSearch, GlobalSearchResult}` do the work, answering JSON at
`panel.{id}.search`. A dotted attribute searches the relation it names, and attributes are a
whitelist, so nothing from the request reaches a column name. The palette is absent when the panel
turns it off *or* when no resource opted in.

→ [Search](../search/overview.md), [Searchable Resources](../search/searchable-resources.md)

## Import and export

```php
ExportAction::make(UserExporter::class, UserResource::class);   // the filtered list
ExportAction::bulk(UserExporter::class, UserResource::class);   // the selection
ImportAction::make(UserImporter::class, UserResource::class);
```

`PandaPanel\Actions\Exports\{Exporter, ExportColumn, ExportRun}` and
`PandaPanel\Actions\Imports\{Importer, ImportColumn, ImportRun}`. Above `Exporter::queueAfter()` the
work runs as `PandaPanel\Jobs\{RunPanelExport, RunPanelImport}` and arrives as a notification with a
download link. CSV and XLSX are read and written by `PandaPanel\Support\Spreadsheet\{Csv, Xlsx}`
without a spreadsheet dependency. Files land on a private disk under a per-user directory.

→ [Export Action](../import-export/export-action.md), [Importers](../import-export/importers.md)

## Integrations

Outbound HTTP fired on a resource's writes, configured at runtime on a screen the resource opts into
with `integrations()->isEnabled(true)`. `PandaPanel\Integrations\*` holds `PanelIntegration`,
`IntegrationObserver`, `IntegrationDispatcher`, `IntegrationSignature`, `IntegrationTemplate`,
`Trigger`, `OutboundUrl` and `PanelIntegrationDelivery`; `PandaPanel\Jobs\SendPanelIntegration`
delivers.

Two gates guard every destination, both in `config/panda-panel.php`: `integrations.allowed_hosts`
is empty by default, so nothing is reachable until a destination is added, and
`integrations.block_private_networks` refuses hosts resolving into private, loopback or link-local
ranges.

## Plugins

`PandaPanel\Plugins\Plugin`, with `PluginMetadata` and `PluginCompatibility`. A plugin does exactly
what a panel provider can do and nothing more.

```php
$panel->plugins([ReportingPlugin::make()->withCharts()]);
```

| Phase | When | What belongs there |
| --- | --- | --- |
| `register(Panel $panel): void` | while the panel is being configured | resources, pages, widgets, navigation groups |
| `boot(Panel $panel): void` | after the panel is resolved, per request | anything needing the container, the user, or a URL |
| `publishes(): array` | only on `panel:publish` | files the plugin copies into the application |

→ [Plugin Concepts](../plugins/concepts.md), [Creating Plugins](../plugins/creating-plugins.md)

## Frontend

Published into the application on install: `resources/js/panel/**` (layouts, components, renderers,
composables, registries, types), `resources/js/pages/panel/**` (framework-generic pages), and
`resources/js/pages/Panels/**` (your own pages, widgets, columns, fields and modals).
`resources/css/panda-panel.css` is the Tailwind 4 stylesheet.

Custom components resolve through build-time `import.meta.glob` allowlists over
`resources/js/pages/Panels/**`, by directory: `Columns/`, `Fields/`, `Schemas/`, `Entries/`,
`Modals/`, `Widgets/`, `Hooks/`, `Shell/`, `EmptyStates/`. A name that was not compiled in cannot be
reached, whatever the request says.

→ [Component Tree](../frontend/component-tree.md), [Component Registries](../concepts/component-registries.md)

## Commands

| Command | Purpose |
| --- | --- |
| `panel:install` | publish, scaffold a panel, register it, verify the frontend |
| `make:panel` | a panel provider and its directories |
| `make:panel-resource` | a resource with its pages, table and form |
| `make:panel-page` | a standalone page |
| `make:panel-widget` | a stats, table, chart or custom widget |
| `make:panel-relation-manager` | a relation manager, optionally with its page |
| `panel:user` | an account that can sign into a panel |
| `panel:cache` / `panel:clear` | the discovery manifest, registered as `optimize` hooks |
| `panel:icons` | rewrite the icon registry from the icons your panels declare |
| `panel:assets` | which published components are behind, and update the safe ones |
| `panel:publish` | copy a plugin's assets into the application |
| `panel:plugins` | what is installed, on which panel, at which version |

→ [CLI Reference](../cli/panel-install.md)

## Testing

Global functions, autoloaded through composer's `files`: `panelTable()`, `panelForm()`,
`panelInfolistLabels()`, `panelRecordActions()`, `panelTableActions()`, `panelBulkActions()`,
`panelInfolistActions()`, `fakePanelNotifications()`, `assertPanelNotificationSentTo()`,
`assertNoPanelNotifications()`, `assertPanelNotificationStoredFor()`,
`assertNoPanelNotificationsStoredFor()`. The classes behind them are `PandaPanel\Testing\*`.

→ [Testing Setup](../testing/setup.md), [Helpers](../testing/helpers.md)

## Notes

- Widget base classes are abstract, as are `Column`, `EditableColumn`, `Field`, `FormComponent`,
  `Entry`, `InfolistComponent`, `Filter` and `Summarizer`. `Action` is concrete and is used directly
  through `Action::make()`.
- There is no client-side routing inside a panel and no separate SPA API. Every screen is an Inertia
  response.
- Charts have no tooltips, zoom or animation. Anything beyond `ChartOptions` is a `CustomWidget`,
  which is honest about being bespoke.
- `Select::relationship()` exists and is covered by types and guard clauses rather than by a feature
  test, because no model in `examples/` has a relation worth selecting.

## See also

- [Overview](overview.md) — installation and the shape of a panel
- [Why Panda Panel](why-panda-panel.md) — the reasoning behind the shape
- [Architecture at a Glance](architecture.md) — how these classes reach a screen
- [Package Limits and Tradeoffs](tradeoffs.md) — what is deliberately absent
- [API Reference](../api/core.md)
