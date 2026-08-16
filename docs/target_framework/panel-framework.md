# Panel Framework

The reference for `panda-panel`: Laravel owns registration,
routing, authorization, queries, validation, and metadata; Vue owns
rendering. Inertia is the only bridge.

`PandaPanel\*` is the package. `App\Panels\*` is your application's own
panels — every example that names one is copied from
[`examples/`](../examples/), which the test suite runs against, so nothing
here is a snippet that was never executed.

---

## 1. Architecture

A request travels through the panel layer like this:

```text
request
  ↓  web middleware        ResetPanelContext clears any previous panel
  ↓  panel route group     panel middleware, then ResolvePanel:{id}
  ↓  PanelContext          the current panel, request-scoped
  ↓  page or resource page authorize → build metadata → serialize
  ↓  Inertia               shared props (panel, navigation) + page props
  ↓  Vue                   PanelLayout → page component → renderers
```

Two backend namespaces:

- `PandaPanel\*` — reusable framework internals.
- `App\Panels\*` — this application's panels.

Three frontend locations, and the split is not optional because
`@inertiajs/vite` only globs `resources/js/pages/**`:

| Location | Role | Inertia-resolvable |
| --- | --- | --- |
| `resources/js/panel/**` | layouts, components, renderers, composables, registries, types | no |
| `resources/js/pages/panel/**` | framework-generic pages | yes |
| `resources/js/pages/Panels/{Panel}/**` | application-specific pages and custom widgets | yes |

### Boundaries that hold everywhere

- **Metadata only.** Schemas serialize to scalars and arrays. A closure is
  evaluated on the server and only its result is sent.
- **Authorization is server-side.** Hiding a button or a navigation item is
  a convenience. Routes, actions, pages, and widgets each authorize
  independently.
- **One query.** `Resource::query()` is the single source for list, view,
  edit, update, delete, bulk, and action lookups.
- **The URL is the table state.** Page, per-page, search, sort, direction,
  and filters live in the query string, so back, forward, refresh, and
  bookmark behave.
- **Nothing dynamic from a request.** Icons and custom components resolve
  through build-time registries; a name that is not registered renders
  nothing rather than being fetched.
- **Cache class names, never answers.** The manifest holds class strings.
  Authorization results, badges, active state, record data, and widget data
  are computed per request.

---

## 2. Creating panels

A panel provider configures one panel. The id is derived from the class name
(`AdminPanelProvider` → `admin`).

```php
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Administrator')
            ->brandName((string) config('app.name'))
            ->icon('shield')
            ->auth()
            ->navigationGroups([
                'User Management',
                'System',
            ])
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'))
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
```

Register it in `app/Providers/PanelServiceProvider.php`:

```php
protected array $panels = [
    AdminPanelProvider::class,
    AppPanelProvider::class,
];
```

Panels are listed by hand on purpose: registration order is visible in one
place, and adding a panel is a deliberate edit. The classes *inside* a panel
are discovered.

### Panel API

| Group | Methods |
| --- | --- |
| Identity | `id`, `name`, `path`, `domain` |
| Access | `middleware`, `authMiddleware`, `auth`, `canAccess` |
| Registration | `resources`, `pages`, `widgets`, `discoverResources`, `discoverPages`, `discoverWidgets` |
| Presentation | `navigationGroups`, `brandName`, `brandLogo`, `icon`, `favicon`, `darkMode`, `sidebar`, `maxContentWidth` |
| Landing page | `dashboard` |
| Built-ins | `settings` |
| Behaviour | `databaseTransactions`, `strictAuthorization`, `unsavedChangesAlerts`, `bootUsing`, `broadcasting` |
| Navigation behaviour | `prefetch`, `fullPageUrls`, `errorNotification`, `hideErrorNotification` |
| Extension | `renderHook`, `subNavigationPosition`, `assets`, `globalSearch` |

Readers are prefixed `get` (`getId()`, `getPath()`). Setters keep the bare
names, because PHP cannot overload and a combined setter/getter returning
`string|static` is exactly the kind of magic this framework avoids.

Discovery paths and navigation groups **accumulate** rather than overwrite,
which is what would let a future module contribute to a panel without core
changes.

### Behaviour

```php
$panel
    ->databaseTransactions()        // on by default
    ->strictAuthorization()         // off by default
    ->unsavedChangesAlerts()        // on by default
    ->bootUsing(fn (Panel $panel) => /* every request into this panel */);
```

**Transactions** resolve most-specific-first: an action's
`->databaseTransaction(bool)`, then a page's `$hasDatabaseTransactions`, then
the panel, then on. `null` at any level means "did not decide" rather than
"off", which is what lets a page override the panel in either direction.
Outside a panel — a controller called directly, a queued job — the answer is
on, because a write that silently stopped being atomic would be the worst
default. `DeleteBulkAction` is transactional whatever the panel says: all or
nothing is the guarantee it advertises.

**Strict authorization** turns a missing policy, or a policy missing the
ability being checked, into a `PanelAuthorizationException` instead of a
denial. A policy defining `before()` is exempt, since it can answer for every
ability. Every `can*()` on `Resource` routes through `authorize()`, so this is
one check rather than eight.

**Boot callbacks** accumulate and run in `ResolvePanel`, after the access
check — a user refused the panel never triggers its boot work.

### Navigation behaviour

```php
$panel
    ->prefetch('hover')                       // 'hover' (default), 'mount', 'click', or false
    ->fullPageUrls('/admin/exports/*')        // must be a real browser navigation
    ->errorNotification(403, 'Not allowed', 'Ask an administrator.')
    ->hideErrorNotification(404);
```

There is no `spa()`: Inertia is already a single-page application. What
carries over from Filament's version of it is **prefetching**, which panel
navigation links pass to Inertia's `<Link>`, and **URL exceptions**, which
here mean links that must leave the SPA. A full-page item renders a plain
anchor and is never prefetched — there would be no point fetching a document
the client cannot use. Matching happens on the server, so the decision
arrives with the link rather than being re-derived on every render.

**Error notifications** are keyed by status and shipped with the panel. On a
failed request the shell shows a toast instead of Inertia's error overlay.
Three outcomes: an entry shows it and suppresses the overlay, an entry set to
null suppresses both silently, and a status with no entry is left to Inertia
— a status the panel has nothing to say about is better shown raw than
swallowed. Defaults cover 403, 404, 419, 429, 500, and 503; a panel replaces
one without restating the rest.

### Broadcasting

Reverb, Echo, and `routes/channels.php` are installed. A panel subscribes to
its own notifications unless it says otherwise:

```php
$panel->broadcasting(false);
```

Send one from anywhere — a controller, a queued job that finishes ten minutes
later:

```php
PanelNotification::dispatch($user, 'Export finished', 'success');
```

It lands as the same toast a flash message does, so the user sees one thing
whether the answer arrived in the response or long after it.

The channel is `App.Models.User.{id}`, built by `PanelNotification::channelFor()`
so the two sides cannot drift, and authorized by `routes/channels.php` — a
user can only ever receive their own, whatever the frontend asks for. The
channel is shipped per request rather than in the panel definition, because
it depends on who is asking.

`configureEcho()` in `app.ts` only configures; nothing connects until a
component subscribes. That is what makes `broadcasting(false)` cost no
connection rather than opening one and ignoring it.

Development needs the websocket server running: `php artisan reverb:start`.
Without it the browser retries in the background and the panel works exactly
as before, minus the live notifications.

### Global search

A command palette above every panel page, opened with `mod+k` or the header
button.

Opt-in per resource: a resource is not searched until it says which
attributes may be.

```php
protected static array $globalSearchAttributes = ['name', 'email', 'author.name'];
protected static int $globalSearchLimit = 5;    // within the panel's limit
protected static int $globalSearchSort = 0;     // ties break on slug

public static function globalSearchResultDetails(Model $record): array;
public static function globalSearchResultUrl(Model $record): string;   // view → edit → index
public static function globalSearchQuery(): Builder;                    // starts from query()
```

A dotted attribute searches the relation it names. Attributes are a
whitelist, so nothing from the request ever reaches a column name.

Panel side:

```php
$panel->globalSearch(enabled: true, limit: 50, debounce: 300, keyBindings: ['mod+k']);
```

The palette is disabled — and absent — when the panel turns it off *or* when
no resource opted in: a palette that can only ever answer nothing is worse
than no palette.

Three guarantees hold: `canViewAny()` is checked before a resource is
queried, searching starts from `Resource::query()` so a tenant or permission
scope narrows it exactly as it narrows a list, and every result is reduced on
the server to a title, details, and a URL. The frontend decides nothing about
what a record is or where it lives.

The endpoint answers JSON at `{panel}/search`, behind the panel's own
middleware. Re-rendering the current page to answer a keystroke would be
absurd — the wizard's step check answers JSON for the same reason.

The palette is keyboard-driven: `mod+k` opens it, the arrows walk the
flattened result list in the order it is drawn, wrapping at either end, and
Enter visits the highlighted result.

### Panel assets

```php
$panel->assets('resources/css/panels/admin.css');
```

Vite entrypoints appended to the application's own, emitted on that panel's
pages and nowhere else — not on another panel, and not on the starter kit's
pages. They accumulate, so a module can add a stylesheet without displacing
the panel's.

Two edits, deliberately: the path must also appear in `vite.config.ts`'s
`input`, or Vite has nothing to serve and the page fails with a manifest
error. That failure is the right one — a declared asset that was never built
is a mistake — but it is why this is not a single-line change.

The list never crosses to the frontend. The browser gets the tags, not what
produced them.

### Record sub-navigation

The pages of one record link to each other. `Resource::pages()` decides what
exists, the policy decides what is offered, and the panel decides where it
sits:

```php
use PandaPanel\Enums\SubNavigationPosition;

$panel->subNavigationPosition(SubNavigationPosition::Start);   // Top (default), Start, End
```

A resource overrides it with `protected static ?SubNavigationPosition
$subNavigationPosition`. `null` takes the panel's, so a resource states one
only when it differs.

Every item is authorized for the record it points at: a page the policy
refuses is absent rather than a link that answers 403, and the route enforces
the same rule independently. Two rules keep it quiet: a page with no record
has no sub-navigation, and neither does a record with only one reachable page
— one link is not navigation.

`Top` renders as a tab strip, `Start` and `End` as a rail beside the content.
The arrangement lives in `PanelRecordLayout`, shared by the view and edit
pages, so a position is honoured in one place rather than in each page.

### Render hooks

```php
use PandaPanel\Enums\RenderHook;

$panel->renderHook(
    RenderHook::HeaderEnd,
    'Panels/Admin/Hooks/Announcement',   // a build-time registry key
    ['message' => 'Maintenance at 5pm'], // serializable props
    [UserResource::class],               // optional: only this resource's pages
);
```

Eight points: `body.start`, `body.end`, `sidebar.start`, `sidebar.end`,
`header.start`, `header.end`, `page.start`, `page.end`. The enum is closed on
purpose — a hook registered against a name the shell does not render would
silently do nothing.

Filament injects Blade here. Nothing renderable crosses the wire in this
framework, so a hook names a Vue component under
`resources/js/pages/Panels/{Panel}/Hooks/` and carries serializable props. An
unregistered name renders nothing rather than throwing: a decorative
injection must not be able to break the page it decorates.

**Scopes** narrow a hook to particular pages. Pass resource or page classes
and they are reduced to slugs at registration, so no class name is ever
serialized: `UserResource::class` becomes `resource:users`, and a page
becomes `page:{slug}`. Every page reports its own scope in `page.scope`, and
a resource's list, view, create, and edit pages share one. An empty scope
list means every page in the panel.

The filtering happens in Vue rather than on the server, and that is forced:
shared props are built in middleware, before the request reaches a page, so
the shell knows which page it is rendering and the middleware does not.

`->sidebar(variant: 'header')` switches the shell to top navigation.
`->sidebar(appearance: 'floating')` styles the side rail: `inset` (the
default) floats the content pane inside the sidebar background, `floating`
detaches the rail as a rounded card, `sidebar` keeps it flush against the edge.
The header shell ignores it.

### Reaching the current panel

```php
panel();          // the panel for this request, or null
panel('admin');   // an explicit panel; throws if unknown
```

---

## 3. Creating resources

```php
final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $navigationIcon = 'users';

    protected static ?string $navigationGroup = 'User Management';

    protected static int $navigationSort = 10;

    public static function table(TableSchema $table): TableSchema
    {
        return UsersTable::configure($table);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return UserForm::configure($schema);
    }

    /**
     * @return array<string, class-string>
     */
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

### One class in several panels

The same resource class can sit in more than one panel and mean something
slightly different in each:

```php
$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People')
        ->navigationLabel('Directory')
        ->navigationGroup('Company')
        ->modifyQueryUsing(fn (Builder $query) => $query->where('is_admin', false)),
]);
```

Without this, a class shared by two panels would have to agree with itself
everywhere, and the only way out would be a subclass that exists purely to
change a label.

**The registry owns the effective slug, not the class.** `Resource::slug()`
answers for the current panel and falls back to the class's own
`defaultSlug()` outside one; route registration asks
`ResourceRegistry::slugFor()`, because during boot there is no current panel
to ask. `Resource::url(panel: $other)` builds the URL that panel would.

`modifyQueryUsing()` narrows `Resource::query()`, and every read goes through
that one query — list, view, edit, delete, bulk, action lookup, global
search. A record the panel may not reach is a **404**, not a filtered row. A
resource overriding `query()` must call `parent::query()` or the panel's
narrowing is silently dropped.

Deliberately **not** a way to register one class twice inside a single panel.
A panel keys resources by slug, and two registrations of one class would make
`Resource::url()` ambiguous, with no way to say which was meant. Two
different classes configured onto one slug throw at registration.

### The central query

Override it once and every page, action, and lookup inherits the scope:

```php
public static function query(): Builder
{
    return parent::query()->where('team_id', currentTeamId());
}
```

Declare eager loads so serializing a column cannot lazy load per row:

```php
protected static array $with = ['role'];
```

### Soft deletes

```php
protected static bool $softDeletes = true;
```

One flag, three consequences, and they only make sense together:

- **A record page can reach a trashed record.** `resolveRecord()` lifts
  `SoftDeletingScope` — and nothing else, so tenant, module, and permission
  scopes still apply. Without this a deleted record could never be opened,
  and so could never be restored: the only route to it is the one the default
  scope hides.
- **The restore and force-delete actions become answerable.** The action
  endpoint looks records up through `Resource::findRecord()`, which is
  trashed-aware for the same reason.
- **`TrashedFilter` has something to reveal.**

The index still hides trashed records until the filter asks. That is the
whole difference between the list and the lookup: an index shows what is
current, a record page was asked for one record by key and should answer
about it.

```php
->filters([TrashedFilter::make('trashed')])
->recordActions([
    DeleteAction::make(UserResource::class),
    RestoreAction::make(UserResource::class),
    ForceDeleteAction::make(UserResource::class),
])
->bulkActions([
    DeleteBulkAction::make(UserResource::class),
    RestoreBulkAction::make(UserResource::class),
    ForceDeleteBulkAction::make(UserResource::class),
])
```

`RestoreAction` and `ForceDeleteAction` are hidden for a record that is not
trashed, so a row shows either restore or delete and never both. Declaring
them without the filter produces two buttons that can never appear —
`make:panel-resource --soft-deletes` generates all three together for exactly
that reason.

Declared *and* corroborated: the resource says it soft deletes and the model
has to actually use the trait. Detecting it from the model alone would
silently grow restore actions on a model that uses `SoftDeletes` for
something the panel was never meant to expose. Two resources over one model
can differ — one declaring it, one not.

`TrashedFilter` takes three values and rejects everything else, so an
unrecognised `?filters[trashed]=` is a no-op rather than a widened query. It
lifts the scope by hand rather than through the `withTrashed` macro, because
the macros only exist on a builder the trait extended.

### URLs

Always route-name based, always panel-aware:

```php
UserResource::url();                       // /admin/users
UserResource::url('create');
UserResource::url('view', $user);
UserResource::url('edit', $user, 'admin'); // explicit panel
```

Asking for a URL in a panel that does not register the resource throws.
That is what makes panel isolation provable rather than accidental.

---

## 4. Resource tables

```php
return $table
    ->columns([
        ImageColumn::make('avatar')->label('')->circular()->toggleable(false),

        TextColumn::make('name')->searchable()->sortable()->toggleable(false),

        TextColumn::make('email')->searchable()->sortable(),

        BadgeColumn::make('email_verified_at')
            ->label('Status')
            ->formatUsing(static fn (mixed $value): string => $value === null ? 'unverified' : 'verified')
            ->labels(['verified' => 'Verified', 'unverified' => 'Unverified'])
            ->colors([
                'verified' => BadgeColor::Success,
                'unverified' => BadgeColor::Warning,
            ])
            ->sortable(),

        DateTimeColumn::make('created_at')->label('Registered')->sortable(),
    ])
    ->filters([
        BooleanFilter::make('verified')
            ->label('Email verification')
            ->column('email_verified_at')
            ->nullable()
            ->labels('Verified', 'Unverified'),

        DateFilter::make('registered')->label('Registered between')->column('created_at'),
    ])
    ->recordActions([
        ViewAction::make(UserResource::class),
        EditAction::make(UserResource::class),
        DeleteAction::make(UserResource::class),
    ])
    ->bulkActions([
        DeleteBulkAction::make(UserResource::class),
    ])
    ->defaultSort('created_at', SortDirection::Descending)
    ->searchPlaceholder('Search by name or email...')
    ->emptyState(
        heading: 'No users match this view',
        description: 'Adjust the search or filters, or add a new user.',
        icon: 'users',
    );
```

Column types: `TextColumn`, `NumberColumn`, `BadgeColumn`, `BooleanColumn`,
`DateColumn`, `DateTimeColumn`, `ImageColumn`.

Filter types: `SelectFilter`, `BooleanFilter`, `DateFilter`.

### Filter tabs

A list page may declare tabs. A tab is a named scope on the resource's own
query, never a query of its own, so a tenant or permission scope still
applies to whatever it shows:

```php
public function tabs(): array
{
    return [
        'all' => Tab::make('all')->badge(fn () => User::query()->count()),
        'admins' => Tab::make('admins', 'Administrators')
            ->icon('shield')
            ->query(fn (Builder $query) => $query->where('is_admin', true)),
    ];
}
```

The active tab lives in the URL like every other piece of table state, and an
unknown key falls back to the first exactly as an unknown sort column is
ignored. Badges may be closures, resolved on the server so only the scalar
crosses. Page widgets receive the tab-scoped query, so a widget counts what
the user is looking at.

### Reordering records

```php
$table->reorderable('position');
```

This also fixes the sort to that column ascending: an order the user arranged
only means something while the table is showing it.

Dragging posts the resulting **key order** to the panel's reorder endpoint;
position in that list is the order. The client never invents a value for a
column it knows nothing about. The endpoint authorizes `canEdit()` on every
record before touching any, and writes them in one transaction — a list that
half-reordered would be worse than one that did not move.

### The schema is the whitelist

Sorting, searching, and filtering read only what a column or filter declared.
Everything else in the URL is ignored:

- `?sort=password` on a non-sortable column — ignored.
- `?perPage=100000` — clamped to the declared options.
- `?filters[verified]=maybe` — rejected, and absent from the echoed state.
- `?search=%` — the LIKE wildcards are escaped, so it matches literally.

`state` is echoed back as what the server *applied*, not what was requested,
so a rejected filter never renders as active.

Declaring `bulkActions()` turns row selection on: a bulk action with no way
to select would be useless.

### Column manager, summaries, grouping, and table actions

```php
return $table
    ->columns([
        TextColumn::make('reference')->toggleable(false),
        NumberColumn::make('total')->summarize([Sum::make(), Average::make()]),
        ToggleColumn::make('is_active'),
        SelectColumn::make('status')->options(['open' => 'Open', 'done' => 'Done']),
    ])
    ->reorderableColumns()
    ->persistColumnsInSession()
    ->groups([Group::make('status')->titleUsing(fn (Order $r) => Str::headline($r->status))])
    ->defaultGroup('status')
    ->headerActions([Action::make('export')->tableAction(fn () => Excel::queue(...))])
    ->toolbarActions([Action::make('refresh')->tableAction(fn () => Cache::forget('orders'))])
    ->emptyStateActions([Action::make('seed')->tableAction(fn () => Order::seedDemo())])
    ->emptyStateComponent('Panels/Admin/EmptyStates/NoOrders');
```

The column manager opens as a popover by default and as a dialog with
`columnManagerInModal()` — a long column list is easier to work in with the
page dimmed, and dragging to reorder needs the room.

**The column manager** puts visibility and order in server state, validated
and optionally remembered: an unknown name is dropped and a column the table
did not make toggleable stays visible however the request asks. A column the
arrangement did not mention keeps its declared place, so adding one to a table
does not leave it invisible for everyone who had already arranged the old
ones. TanStack no longer keeps its own copy — that would be a second source of
truth for the same question.

**Editable columns** (`ToggleColumn`, `CheckboxColumn`, `TextInputColumn`,
`SelectColumn`) are a write endpoint wearing a table's clothes, so they are
held to every rule a form is and one more. Only an `EditableColumn` can be
written and only the attribute it names; `validationRules()` is the server's;
`Resource::canEdit()` is asked for the row being written; and
`disabledUsing()` is asked again on the way in, because a disabled control is
not a permission.

**Summaries** are computed by the database over the *filtered* query, not by
adding up the rows on screen — a total that changed when you paged would be a
different number wearing the same label. A summarizer that genuinely wants the
page says `->perPage()`. The query is cloned and reordered, so a summary
cannot leave an `order by` on the builder the table just paginated.

**Grouping** is presentation, not aggregation. It does not change which
records the query returns, so paging still works and a band can be split
across two pages exactly as any run of rows can — the honest behaviour for a
server-paginated table, where collapsing the whole result into groups would
mean fetching all of it. What it does change is the ordering: an active group
sorts before anything else, so rows arrive together.

**Table actions** act on the table rather than a row, so they resolve and
authorize with no record — the way a bulk action's authorization is asked
before anything is selected. Header, toolbar, and empty-state actions are
three places and one lookup: the endpoint that runs them does not care which
bar an action was rendered in.

**Column actions** make a whole cell run something:

```php
TextColumn::make('reference')->action(
    Action::make('approve')->action(fn (Order $record) => $record->approve()),
),
```

Resolved per record, so a cell the user may not act on renders as an ordinary
value rather than a button that answers 403. A column may have an action *or*
a `url()`, never both — a cell that went somewhere and did something would be
a coin toss.

`recordActionsPosition()` puts the row's buttons before the columns, after
them, or inside the last cell (`AfterCells`) for a table narrow enough that a
column of its own would be most of it.

**Bulk authorization and notifications.** `authorizeEachUsing()` is checked for
every record before any is touched, whatever the handler does — "may run this"
is not "may run this on these", and all-or-nothing has to be decided before
the first write. `successMessageUsing(fn (int $count) => ...)` gets the count,
because after a bulk run that is the part somebody wants.

**Panel-level defaults.** `Panel::configureActions()` applies house style to
every action the panel builds — every destructive action confirming, every
action carrying an icon — without each schema repeating it. Applied as the
action is made, so the schema still wins. Request-scoped through the current
panel rather than a static configurator, so two panels can differ and nothing
leaks between requests.

**Group summaries** are computed per band over the *whole* band, not over the
rows of it on this page — one query per band on screen, which is a handful.
They render under the band they describe, the way a column of numbers reads.

### Tables over data that is not in the database

```php
$data = ArrayTableData::make($schema, $records, $request);
$page = $data->paginate();

return ['rows' => $data->rows($page), 'state' => $data->state()];
```

An API response, a config file, a computed report. Same columns, same search
and sort declarations, same serialization — only where the rows come from and
who does the work differ. Rows are still `Model` instances, because a column
reads its value with `data_get()`; a non-persisted model needs no table.

The honest limitation is scale: search, sort, and pagination all happen in
memory over the full set. This is for tens or hundreds of rows. Anything
larger belongs in a query, where the database can do the work.

### Filters

```php
return $table->filters([
    TernaryFilter::make('email_verified_at')
        ->nullable()
        ->labels('Verified', 'Unverified', 'Anyone')
        ->default(TernaryFilter::TRUE),

    FormFilter::make('created')
        ->form(fn (FormSchema $schema) => $schema->schema([
            DatePicker::make('from'),
            DatePicker::make('until'),
        ]))
        ->query(fn (Builder $query, array $data) => $query
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))),

    QueryBuilderFilter::make('conditions')->constraints([
        TextConstraint::make('name'),
        NumberConstraint::make('total'),
        DateConstraint::make('created_at'),
        BooleanConstraint::make('is_active'),
    ]),
])
->persistFiltersInSession()
->deferFilters()
->filtersApplyLabel('Run')
->showFiltersResetAction(false);
```

**Ternary** is three states where the third is an *answer* the table means
rather than an empty control — "Anyone", not a cleared filter. Each branch can
own its query, so the two answers need not be inverses: "has a manager" and
"has none" are `whereHas` and `whereDoesntHave`.

**Form filters** put a whole `FormSchema` behind one filter, for a question
that takes more than one answer. The value reaching `query()` is the
*validated* form data — the schema is the whitelist exactly as it is on a
resource form, so a key it never declared is discarded before the closure sees
it, and an all-blank form narrows nothing.

**The query builder** lets the user compose conditions. Everything about a
rule is checked against a declaration before it reaches the query: the column
must be a declared `Constraint`, the operator must be one that constraint
supports, and the value must be one it accepts. Nothing is concatenated from
the request — the column string comes from the `Constraint` object and the
comparison from a closed enum. A rule failing any check is dropped, not
repaired: a query the user did not describe is worse than no rule.

Rules are ANDed, and bounded by `maxRules`. Nested and/or groups are
deliberately absent — they need a recursive schema on both sides and a UI to
match, and a flat list answers the question most tables are actually asked.
Reach for `FormFilter` when the shape is known and a custom `query()` when it
is not.

**Defaults** apply while the request says nothing about filters at all. Once
any filter is present, an absent one means the user removed it rather than
never having set it — otherwise a default could never be cleared. A default
reports as *active*, because it is a decision the table made.

**Persistence** remembers the filter map as a whole, not filter by filter:
"which filters are set" is one decision, and remembering them individually
would make removing one indistinguishable from never setting it. Same session
key rules as sort and search.

**`modifyBaseQueryUsing()`** runs before the search and outside the constraint
grouping, for a filter that has to change what the query *is* — lifting a
global scope, joining a table the other constraints then read. A filter that
declares only a base-query modifier applies no ordinary constraint: it has
already said where its work happens, and falling through would invent a
`where` on a column named after the filter.

**Filter indicators** are built on the server, because only a filter knows
what its value means: `1` is "Verified", not "1".

Filters never narrow a record lookup. They live in `TableQuery::paginate()`,
not in `Resource::query()`, so a record filtered off the list is still
openable by URL — including one hidden by a base-query modifier.

### Column state

Everything a column can say about a cell, and where each piece lives:

| On the definition (sent once) | Per record (sent per row) |
| --- | --- |
| `placeholder`, `width`, `headerTooltip`, `wrapHeader`, `alignment`, `headerAlignment` | `tooltip`, `url`, `extraAttributes` |

That split is the rule: anything that can vary by record accepts a closure and
is evaluated on the server; anything that cannot is a plain value sent once
rather than repeated on every row.

```php
TextColumn::make('title')
    ->placeholder('Untitled')
    ->default('—')
    ->width('16rem')
    ->wrapHeader()
    ->headerTooltip('The title as published')
    ->alignment(Alignment::Start)
    ->tooltip(fn (Post $record): string => $record->slug)
    ->url(fn (Post $record): string => PostResource::url('view', $record))
    ->extraAttributes(fn (Post $record): array => ['data-status' => $record->status]),
```

`placeholder()` and `default()` are not the same thing. A default *state*
stands in for a null value before the cell is formatted, so it goes through
whatever `formatUsing()` does; a placeholder is presentation for the absence
itself and is rendered instead of a cell. Placeholder lives on the base class
so an empty date and an empty text column read the same way.

`extraAttributes` is spread onto an element, so it takes scalars only and
refuses anything starting with `on` — an event handler there would be a way
to put executable content on a page from a schema.

Alignment is logical (`start`, `center`, `end`, `justify`) so a right-to-left
locale flips without every table being rewritten; `left` and `right` are
accepted and mean what they always meant. `width` is a CSS length applied
inline, never a Tailwind class — `w-${n}` would have to be interpolated, and
an interpolated class does not exist in the bundle.

The per-row extras travel in `cellMeta` beside `cells`, not inside them, so a
cell value stays exactly the shape its guard narrows and a table using none of
this ships an empty map.

### Frozen columns

Keeps a column in view while the rest of the table scrolls sideways. Available
on **every** column type, because it lives on `Column`:

```php
TextColumn::make('reference')->frozen(),                  // to the left edge
TextColumn::make('balance')->frozen(ColumnPin::End),      // to the right
```

The reason a wide table is usable at all: without it, scrolling out to column
fourteen takes the name that identifies the row off the screen, and every cell
after that is a value with nothing attached to it.

**A pinned column is drawn at the edge it is pinned to**, whatever position it
was declared in. This is not a stylistic choice — a sticky cell is offset by
the total width of the frozen columns before it, so a frozen column left
sitting in the middle would be offset over the top of the ones it was declared
after. Moving it is what pinning means in every other table that offers it, and
it is visible, which the alternative — a column that quietly declines to
freeze — is not.

Freezing a column freezes everything structural on the same side of it: the
reorder handle and the selection checkbox to the left, and the row actions to
the right if the table asked for them.

```php
return $table
    ->columns([...])
    ->frozenActions();   // off by default
```

The actions are opt-in on their own because pinning costs horizontal room and a
table narrow enough not to scroll gains nothing from it.

**Offsets are measured, not declared.** A frozen column does not have to state
a `width()`: the browser reads the width each header cell actually took and
re-reads it whenever it changes. Adding declared widths up in PHP would be
wrong exactly when it matters — a column sized to its content is the normal
case, and frozen columns drifting a pixel out of line on a long name is worse
than never freezing at all.

Three details the rendering depends on, each of which is a visible bug when it
is missing:

- A frozen cell is `bg-inherit`, so it is opaque — a transparent sticky cell
  has the scrolling content pass underneath it — while still taking the row's
  own hover and selected background rather than being the one cell that never
  highlights.
- The last frozen cell on each side carries a hairline and a short gradient, so
  the seam is something the eye can find instead of a place where columns
  appear to teleport.
- The header, the per-column search row, and the summary footers are all pinned
  with the body. Pinning one and not the others is a table whose columns stop
  lining up the moment it scrolls.

**Pinning drops itself when it would take more than 60% of the visible table.**
On a phone, three pinned columns can leave a strip too narrow to read the rest
through, and the user cannot scroll out of it because the pinned columns are
the ones in the way. Above the threshold the table behaves like an ordinary
one. It is checked on every resize, not decided once.

### Icon, colour, and custom columns

```php
IconColumn::make('status')
    ->icons(['published' => 'check', 'draft' => 'pencil'])
    ->colors(['published' => BadgeColor::Success]),

IconColumn::make('is_active')->boolean(),

ColorColumn::make('brand_color')->copyable(),

CustomColumn::make('trend')
    ->component('Panels/Admin/Columns/Sparkline')
    ->state(fn (Post $record): array => ['points' => $record->view_counts]),
```

An icon column sends a registry *key*, resolved through the same build-time
icon registry as everything else, so a table cannot ask the browser for an
icon that was not compiled in and `panel:icons` can find the names by reading
the source. Colours reuse the `BadgeColor` palette rather than a second
vocabulary that would drift.

A colour column validates its value as hex, `rgb()`, or `hsl()` before
sending it. That matters more here than elsewhere: the value ends up in an
inline `background-color`, and an unvalidated string there is arbitrary CSS
from a database row.

A custom column names a component under
`resources/js/pages/Panels/{Panel}/Columns/`, resolved through a build-time
registry exactly like a custom widget. Its `state()` must serialize to
scalars and arrays like any other cell.

### Sorting and searching

```php
return $table
    ->columns([
        TextColumn::make('reference')->searchable(individually: true),
        TextColumn::make('status')->sortUsing(
            fn (Builder $query, SortDirection $direction) => $query
                ->orderByRaw("field(status, 'urgent', 'open', 'closed') {$direction->value}"),
        ),
    ])
    ->defaultSort('created_at', SortDirection::Descending)
    ->defaultSortOptionLabel('Newest first')
    ->searchDebounce(500)
    ->searchOnBlur()
    ->splitSearchTerms(false)
    ->persistSearchInSession()
    ->persistSortInSession();
```

**Custom sort.** `sortUsing()` takes over ordering entirely and makes the
column sortable. For an order the schema cannot express as a column name — a
`CASE` over a status, a JSON path, a computed distance. It receives the
direction already validated against the enum.

**Term splitting** is on by default: each word of a multi-word search must
match somewhere, so "ada lovelace" finds the record whose first name is in one
column and surname in another. Turn it off where the phrase *is* the value —
a reference, a serial, an address.

**Individual column search** gives a column its own box in a second header
row, ANDed with everything else: it answers "of these rows, which have this in
that column". Only a column that declared `individually: true` can be searched
this way, so a request naming any other column searches nothing.

**Debounce and blur** are the server's decision, because how expensive a
search is, is the server's knowledge. A table over a large join wants the user
to finish typing; a small one can answer every keystroke.

**Session persistence** is off by default — returning to a table and finding
it filtered by something typed yesterday is surprising unless it is a table
somebody works in rather than passes through. The key is built from the panel
id and the resource slug (and the relation key for a relation table), never
from anything in the request: a key a caller could influence would let one
table read another's remembered state.

Two rules make it safe to restore. The request wins whenever it says anything
at all, *including* that a value is now empty — otherwise clearing a search
would be undone by the thing that remembered it. And a remembered value goes
through the same validation a fresh one does, so a stale session naming a
column the table no longer has is ignored exactly as a hand-typed one would
be.

A table rendered without session middleware remembers nothing rather than
failing.

### Relationship columns

```php
NumberColumn::make('posts_count')->counts('posts')->sortable(),
BooleanColumn::make('posts_exists')->exists('posts'),
NumberColumn::make('orders_sum_total')->sum('orders', 'total'),

TextColumn::make('author_name')->sortableByRelation('author', 'name'),
TextColumn::make('author.name')->searchable(),
```

Three different things, kept apart because the query needs different work
from each:

- **Aggregates** are computed in the select, so the column shapes the query
  before any row is read (`TableSchema::applyColumnQueries()`). One query for
  the whole page whatever the row count — reading it per record is the thing
  eager loading exists to prevent. The result lands on the attribute Eloquent
  generates (`posts_count`), derived with Eloquent's own rule so the column
  reads exactly what the query wrote. That attribute is a real column of the
  result set, so sorting it is an ordinary `ORDER BY`.
- **Sorting by a related column** orders by a correlated subquery, never a
  join: a join against a to-many relation multiplies rows and quietly breaks
  both the page size and the total.
- **Searching a relation** is `whereHas`. A dotted searchable name is routed
  there automatically — `author.name` is not a column of this table, and
  matching it as one is a SQL error rather than an empty result. The search
  stays grouped, so it never widens an existing filter constraint.

---

## 5. Resource forms

```php
return $schema
    ->columns(2)
    ->schema([
        Section::make('Personal Information')
            ->columns(2)
            ->schema([
                TextInput::make('name')->required()->maxLength(255),

                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->rulesUsing(static fn (?Model $record): array => [
                        $record === null
                            ? Rule::unique('users', 'email')
                            : Rule::unique('users', 'email')->ignore($record->getKey()),
                    ]),

                Toggle::make('verified')
                    ->label('Email verified')
                    ->columnSpan(2)
                    ->formatUsing(static fn (mixed $value, ?Model $record): bool => $record instanceof User
                        && $record->email_verified_at !== null)
                    ->dehydrateTo('email_verified_at')
                    ->mutateUsing(static fn (mixed $value, ?Model $record): mixed => $value === true
                        ? ($record?->email_verified_at ?? Date::now())
                        : null),
            ]),

        Section::make('Security')
            ->columns(2)
            ->schema([
                PasswordInput::make('password')
                    ->confirmed()
                    ->rules(['min:8'])
                    ->columnSpan(2)
                    ->when(
                        $schema->getPage() === 'create',
                        static fn (PasswordInput $field): PasswordInput => $field->required(),
                        static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
                    ),
            ]),
    ]);
```

Fields — text and numbers: `TextInput`, `Textarea`, `PasswordInput`,
`NumberInput`, `HiddenInput`, `Slider`, `ColorPicker`, `TagsInput`,
`KeyValue`.
Choices: `Checkbox`, `Toggle`, `Select`, `Radio`, `CheckboxList`,
`ToggleButtons`.
Dates: `DatePicker`, `DateTimePicker`, `TimePicker`.
Long text: `RichEditor`, `MarkdownEditor`, `CodeEditor`.
Structured: `Repeater`, `Builder`, `FileUpload`.
Bespoke: `CustomField`.

Layouts: `Section`, `Grid`, `Tabs`/`Tab`, `Wizard`/`Step`, `Callout`,
`EmptyState`, `Relationship`, `CustomComponent`.

Content-only "prime" components, which hold no value and submit nothing:
`Prime\Text`, `Prime\Icon`, `Prime\Image`.

### Column spans

A container is divided by `columns()`; a field says how much of that division
it takes.

```php
Section::make('Details')
    ->columns(3)
    ->schema([
        TextInput::make('first_name'),          // one column
        TextInput::make('last_name'),
        TextInput::make('title')->columnSpan(2),
        Textarea::make('bio')->columnSpanFull(), // the whole row
    ]),
```

`columnSpanFull()` rather than `columnSpan(3)`: the number that means "all of
them" belongs to the container, and a field that spelled it out would silently
become two thirds the day somebody made that section four columns. It crosses
the wire as the string `'full'` and becomes `col-span-full` — the whole row at
every width, with no arithmetic to keep in step with the column count.

Both are on fields and on infolist entries — `Field` and `Entry` — and on
neither the schema nor the layouts. A layout already takes the whole row
wherever it appears. The schema is the root, and a span called on it raises a
`BadMethodCallException` naming the field it belonged on.

#### Columns are responsive, and spans are clamped per breakpoint

A declared count is the count on a wide screen, never on a phone:

| `columns(n)` | base | `md` (768px) | `lg` (1024px) |
| --- | --- | --- | --- |
| 1 | 1 | 1 | 1 |
| 2 | 1 | 2 | 2 |
| 3 | 1 | 2 | 3 |
| 4 | 1 | 2 | 4 |

One column on a phone always: two 160px controls side by side are two controls
nobody can fill in. Never more than two at `md`, because a panel spends about
256px of those 768 on its sidebar.

A span is clamped against *that* table rather than against the declared count,
separately at each breakpoint — so `columnSpan(3)` inside `columns(4)` is two
columns at `md` and three at `lg`. Clamping against the declared count instead
was a real defect: it emitted `md:col-span-4` into a grid that is two columns
wide at `md`, and `grid-column: span 4` over two tracks creates two implicit
ones and overflows the container sideways.

Counts above four are clamped to four. The renderer has literal Tailwind
classes for one through four — an interpolated `grid-cols-${n}` compiles to
nothing — and an unclamped count used to fall through to the one-column
fallback, so `columns(6)` rendered a single stack.

Both halves of this live on opposite sides of the wire: `ColumnCount` in PHP
and `panel/lib/grid.ts` in the browser. `FrontendContractTest` reads the
TypeScript tables and fails if they and the clamp ever disagree.

### Three separable concerns

A field declares how it renders, what validates it, and whether it persists.
Keeping them apart is what makes the password field work:

- `required()` on create, optional on edit.
- Still validated when filled.
- `optionalWhenFilled()` makes it **not persist** when blank, so the stored
  hash is never overwritten with an empty string.

`dehydrateTo()` maps a field onto a different attribute when the form name
and the column name differ, instead of inventing a column so they line up.

Validation is Laravel's. `required` on a field is a UX marker; removing it in
the browser changes nothing. Only declared fields are validated, and only
fields that dehydrate are persisted, so an extra key in the request body is
discarded rather than mass-assigned.

`password_confirmation` is not a field. `confirmed()` makes the renderer draw
the second input and makes the schema add the matching rule, so the pair
cannot drift.

### Showing, hiding, and disabling

Four separate questions, deliberately not one:

```php
TextInput::make('slug')->visibleOn(['edit']),          // which page
TextInput::make('slug')->hiddenOn(['create']),
TextInput::make('email')->disabledOn(['edit']),        // shown, not editable

TextInput::make('reason')->visible(                    // the record
    static fn (?Model $record): bool => $record?->isRejected() === true,
),

TextInput::make('other')                               // another field's value
    ->visibleWhen('kind', ConditionOperator::Equals, 'other'),
```

The first three and the closure are answered on the server, once, while the
schema is built. A hidden field is not merely invisible: it is absent from the
payload, absent from the rules, and absent from what dehydrates — so a request
that sends it cannot make it exist.

`visibleWhen()` / `hiddenWhen()` are different in kind. They have to react as
somebody types, which means being evaluated in the browser — and **nothing
executable crosses the wire**, so the server sends a *description* of the
comparison and the compiled-in frontend performs it. The operators are a
closed set (`ConditionOperator`): equals, in, filled, greater than, truthy,
and their opposites. The same `Condition` object answers on the server, which
is what keeps the rendered form and the validation pass from disagreeing.

The cost is that a condition has to be expressible as one of those operators.
That is the point. Anything else is a server-side `visible()` closure, which
is honest about being evaluated once and not reacting. There is no
`hiddenJs()` here and there will not be.

`inlineLabel()` puts the label beside the control rather than above it.

### Reactive state

A field marked `live()` asks the server to rebuild the schema after it
changes:

```php
Select::make('country')->live(),
Select::make('region')->options(...),   // depends on the country
TextInput::make('note')->live(onBlur: true, debounce: 1000),
```

For dependencies the declarative conditions cannot express — options that come
from another field's value, a total computed from three of them. Off by
default, because a round trip per keystroke is the wrong default; the
conditions above cost no request at all.

The browser posts the values and which field changed to the panel's
`form-state` endpoint and gets a schema back. That endpoint validates nothing
and writes nothing: it answers what the form should *look* like. Authorization
is still the page's — describing a create form requires `canCreate()` — and
submitted keys the schema does not declare are discarded before anything reads
them.

### Lifecycle hooks

```php
TextInput::make('name')
    ->formatUsing(...)          // shapes the value on the way in
    ->afterStateHydrated(...)   // observes it; the return value is ignored
    ->afterStateUpdated(...)    // runs when a live field changes
    ->dehydrateStateUsing(...)  // shapes the value on the way out
    ->dehydrated(false),        // or keeps it out of the write entirely
```

`afterStateUpdated` runs only for a field that declared itself `live()`,
whatever the request claims changed.

`dehydrated(false)` and validation are separate questions: a field can be
required and still never reach a column.

### Repeaters, builders, and files

A `Repeater` holds a list of entries edited by one sub-schema; a `Builder`
holds a list of blocks that each name their own. Both validate per entry
(`items.*.title`), and both dehydrate through the fields that describe an
entry — so a key the sub-schema never declared is discarded exactly as it is
at the top level. A builder entry whose `type` is not a declared block is
dropped.

`FileUpload` stores in its own request, before the form is submitted:

```php
FileUpload::make('avatar')
    ->disk('public')
    ->directory('avatars')
    ->acceptedTypes(['image/png', 'image/jpeg'])
    ->maxSize(1024),
```

The request names a resource and a field, never a disk or a directory — those
come from the field's declaration, looked up in the schema that declared it.
The field's limits are applied to the real file rather than to what the
browser claimed, and on submit the path is checked again: it must exist, live
under the declared directory, and not climb out of it. Removing a file from a
form does not delete it, because the form has not been submitted and the
record may still be using it.

### Frontend validation

Each field ships the subset of its rules a browser can honestly check —
required, email, url, numeric, min, max, confirmed — derived from the same
declarations the server validates with. Rules needing the database are
deliberately absent: a frontend that guessed at `unique` would be confidently
wrong.

It saves a round trip and nothing more. The server validates everything again,
and a field hidden by a condition is skipped on both sides.

### Custom fields and components

```php
CustomField::make('rating')->component('Panels/Admin/Fields/StarRating'),
CustomComponent::make('Panels/Admin/Schemas/Banner')->schema([...]),
```

The component name is a build-time registry key resolved from
`resources/js/pages/Panels/**/Fields/` and `.../Schemas/`, never markup and
never a path — the same rule custom columns and widgets follow. An
unregistered name renders a placeholder; a custom *component* still renders
its children, because the wrapper is decoration and the fields inside it are
the form.

Adding a field type means adding the PHP class, the TypeScript definition, and
a branch in `resources/js/panel/forms/FormField.vue`. The switch is
exhaustive, so a definition without a renderer is a compile error — and a
test asserts every `FieldType` case reached the TypeScript union, which the
compiler cannot see.

---

## 6. Resource pages

```php
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
```

Bases: `ListRecords`, `CreateRecord`, `ViewRecord`, `EditRecord`.

Each is routed twice where it needs a write verb:

| Page | Routes | Route name |
| --- | --- | --- |
| `index` | `GET /` | `panel.{id}.resources.{slug}.index` |
| `create` | `GET create`, `POST create` | `.create`, `.store` |
| `view` | `GET {record}` | `.view` |
| `edit` | `GET {record}/edit`, `PUT {record}/edit` | `.edit`, `.update` |

`render()` handles the GET, `handle()` the write. Both are real controller
methods, so panel routes stay `route:cache`-able.

### Titles and subheadings

Every resource page carries the same three declarations a standalone `Page`
does — `$title` for the browser tab, `$heading` for the line above the
content, `$subheading` for the line beneath it:

```php
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Team directory';

    protected static ?string $subheading = 'Everyone with an account.';
}
```

Each is optional, and each falls back to what the page said before:

| Page | `title` | `heading` | `subheading` |
| --- | --- | --- | --- |
| `ListRecords` | plural label | title | — |
| `CreateRecord` | `New {label}` | title | — |
| `ViewRecord` | record title | title | label |
| `EditRecord` | `Edit {record}` | record title | `Edit {label}` |
| `ManageRelatedRecords` | manager title | title | owner's record title |

`$heading` follows `$title` unless the page separates them, which only the
edit page does: the breadcrumb above already says the page is an edit, so
heading the record twice with the verb reads as a mistake.

When the text depends on something a static property cannot say — the record,
a count, the tenant — override the getter instead. The record is passed on
the pages that have one and is null on the pages that do not:

```php
public function getSubheading(?Model $record = null): ?string
{
    return $record === null ? null : 'Editing '.$record->email;
}
```

A custom page extending `ResourcePage` directly gets the resource's plural
label as its default title, so it has a heading without declaring one.

### Infolists

A record's read-only presentation, deliberately separate from `FormSchema`
rather than a mode of it:

```php
public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return $schema->columns(2)->schema([
        Section::make('Account')->columns(2)->schema([
            TextEntry::make('name'),
            BadgeEntry::make('status')->colors(['active' => BadgeColor::Success]),
            BooleanEntry::make('email_verified_at')->labels('Verified', 'Unverified'),
            DateTimeEntry::make('created_at')->since(),
        ]),
    ]);
}
```

A form validates, dehydrates, and hides fields per page; an infolist does
none of that. Sharing one class would mean every entry carrying rules it can
never use, and a view page quietly depending on what the edit form happens to
declare. The password on the user view page is now *absent* rather than
filtered — an infolist that never reads it cannot leak it.

Entries: `TextEntry`, `BadgeEntry`, `BooleanEntry`, `DateTimeEntry`,
`KeyValueEntry`, `IconEntry`, `ImageEntry`, `ColorEntry`, `CodeEntry`,
`RepeatableEntry`, `CustomEntry`.

Layouts: `Section`, `Grid`, `Tabs`/`Tab`.

Dot notation reads through relations. `visible()` is evaluated on the server,
and a section, grid, or tab left empty by hidden entries renders nothing
rather than a bare heading.

A resource that declares no infolist keeps the old form-derived view page, so
adopting one is opt-in.

An `ImageEntry` resolves its URL on the server from the disk it names, so the
browser never turns a disk into a link; a disk with no public URL answers null
and the placeholder shows instead. A `ColorEntry` validates against the same
pattern the colour field accepts, because that value ends up inside a `style`
attribute.

#### Repeatables

```php
RepeatableEntry::make('lines')
    ->itemLabel('Line')
    ->schema([
        Grid::make(3)->schema([
            TextEntry::make('product'),
            TextEntry::make('quantity'),
        ]),
    ]),
```

The items are whatever the value holds: a relation's records, or the rows of a
JSON column. A row that is not a record is wrapped in an `InfolistRow` so the
children are always handed a model — see that class for why widening every
signature to `Model|array` was the worse option.

A repeatable counts as one entry rather than as its children: they belong to
an item, not to the record.

#### Actions on an infolist

```php
return $schema
    ->actions([Action::make('approve')->action(...)])          // the record
    ->schema([
        Section::make('Invitation')
            ->headerActions([Action::make('resend')->action(...)])   // the group
            ->schema([
                TextEntry::make('email')->action(Action::make('verify')),  // one value
            ]),
    ]);
```

The same `Action` a table row uses, so a thing that can be done to a record is
described once however it is reached. They run through the panel's
`actions/infolist` endpoint, which resolves them against
`Resource::infolist()` — a *different* whitelist from the table's, so an
action shown on a view page cannot be run from a list that never offered it.

An entry inside a repeatable carries no action: a wrapped row has no key, and
an action pointing at one would name a record the endpoint could never find.

### Wizards

A create or edit form split into steps:

```php
Wizard::make([
    Step::make('Identity')->icon('user')->schema([...]),
    Step::make('Access')->schema([...]),
])->submitLabel('Create user')
```

Stepping is presentation only: `fields()` walks the steps, so validation and
dehydration see the flat form it is. Moving a field between steps cannot
change what is written.

**Per-step validation** happens as the user moves on. Pressing Next posts to
`{resource}/create/step` (or `{record}/edit/step`) with the step index, and
the server validates *only that step's fields*:

```php
$schema->validationRulesForStep($step, $record);   // a subset of validationRules()
```

Derived, never declared. The step already knows which fields it holds, so
validating one is a subset of validating all — there is no second definition
that could drift from the first, and no rule that applies half-way but not at
the end. A confirmation field is validated with the password it belongs to.

The endpoint answers JSON, like the search endpoint and for the same reason:
re-rendering the page would throw away what the user has typed. The page
class is bound by the route, never resolved from the request. A page whose
form has no wizard answers 400 rather than pretending to check something.

The final submit still validates everything, and the wizard jumps to the
first step holding a rejected field — so an error is never hidden behind a
step the user walked past. If the step endpoint is unreachable, Next moves on
anyway: a step that cannot be checked must not trap the user, and the submit
catches it.

### Custom and multiple pages

A key in `pages()` that is not one of the four standard ones is a custom
page: one GET route at the path it declares.

```php
final class AuditUser extends ResourcePage
{
    use InteractsWithRecord;

    protected static ?string $routePath = '{record}/audit';   // defaults to the page key

    public function render(string $record): Response
    {
        $model = $this->resolveRecord($record);
        // ...
    }
}
```

`InteractsWithRecord` resolves through `Resource::query()` like every other
lookup, so a record outside the scope is a 404 rather than something a custom
page can reach around, and authorizes with `canView()` unless the page
overrides `authorizeRecord()`. The record is held for the request, so reading
it three times is one query.

Several record pages are just several keys: `'audit'`, `'history'`,
`'permissions'`. Each gets its own route name and appears wherever the
resource lists its pages.

### Page widgets

Any resource page may place widgets above or below its content:

```php
public function headerWidgets(): array
{
    return [SignupsThisWeek::class];
}
```

They receive a `PageContext`, which is what separates them from dashboard
widgets: a list page hands over its own query, a record page hands over its
record. So a widget counts what the user is looking at rather than the whole
table.

```php
$this->context()->record();   // null on a list page
$this->context()->query();    // null on a record page
$this->context()->count();    // memoized: three widgets, one query
```

`context()` throws when the widget was rendered without one — a widget
reading a record it was never given is on the wrong page, and saying so is
more useful than a zero.

### Singular resources

A resource with exactly one record — application settings, the current
tenant:

```php
protected static bool $singular = true;
```

Its pages carry no `{record}`, because there is nothing to choose between:
`/app-settings/edit`, not `/app-settings/{record}/edit`. The record comes
from `resolveSingularRecord()`, which goes through `query()` like everything
else and can be overridden to create the row on first visit.

### Lifecycle hooks

Two kinds, and the split is the point. A `mutate*` hook takes data and
returns it. Every other hook returns nothing and exists for side effects and
for halting. Conflating them would leave two places to change a value and no
obvious place to stop.

```text
fill:    beforeFill → mutateFormDataBeforeFill → afterFill

create:  beforeValidate → validate → afterValidate → beforeCreate
         → mutateFormDataBeforeCreate → mutateFormDataBeforeSave → beforeSave
         → handleRecordCreation → afterCreate → afterSave

update:  beforeValidate → validate → afterValidate
         → mutateFormDataBeforeSave → beforeSave → handleRecordUpdate
         → afterSave
```

`handleRecordCreation()` and `handleRecordUpdate()` are the write itself.
Override one to persist through a service, a factory, or a relationship
rather than a bare save. They and the `after*` hooks share one transaction,
so a throwing hook rolls the write back.

```php
protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
{
    $data['name'] = Str::title($data['name']);

    return $data;
}
```

`$this->halt()` stops the lifecycle from any hook. Nothing is written and the
user goes back where they were. It is not an error: a halt is a decision the
page made, so it never surfaces as a 500.

Three more overrides shape what happens afterwards:

```php
protected function getRedirectUrl(Model $record): string;

/** Return null to say nothing at all. */
protected function createdNotification(Model $record): ?array;   // ['type' => ..., 'message' => ...]
protected function savedNotification(Model $record): ?array;
```

### Create another

A create page offers a second submit that saves and returns to an empty form:

```php
protected static bool $canCreateAnother = true;             // the default
protected static bool $preservesDataOnCreateAnother = false;
```

Preserving is off by default: the common case is a run of different records,
and a form that silently keeps the previous one invites saving it twice. The
frontend only reports which button was pressed; what "another" means is the
server's decision.

Deletion has no page hooks: it runs through the action endpoint, which
executes without a page instance. Use `Action::before()` and
`Action::after()` instead.

Hooks are for shaping data and small side effects. Substantial business
logic belongs in `App\Actions` or `App\Services` and is called from a hook.

---

## 7. Relations

A record's related records are shown by a **relation manager**: a table with
its own schema, actions, and authorization, scoped to one owner.

```php
final class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([TextColumn::make('title')->searchable()->sortable()])
            ->filters([TrashedFilter::make('trashed')])
            ->recordActions([
                EditRelatedAction::make(UserResource::class, self::class, $owner),
                RestoreAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([TextInput::make('title')->required()]);
    }
}
```

Register it on the resource, and nowhere else:

```php
public static function relationManagers(): array
{
    return [PostsRelationManager::class];
}
```

Every relation table, endpoint, and page resolves through that list. A
manager the resource does not name cannot be addressed by a request that
names it.

The owner travels with `table()` and `form()` because a relation table's
actions are about the pair, not about the related record alone: whether a row
may be detached is a question with two subjects.

### The relation is the scope

`RelationManager::query($owner)` starts from `$owner->{relationship}()` and is
the only way to reach a related record — exactly the role `Resource::query()`
plays for a resource. A key belonging to another owner resolves to nothing
rather than to somebody else's row, so no page or action has to check.

### Two authorization questions

They are kept apart because they have different subjects.

| Ability | Asked of |
| --- | --- |
| `viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete` | the **related record**'s policy |
| `attachAny`, `detach`, `associateAny`, `dissociate` | the **owner**'s policy |

Whether a tag may be pinned to a post is the post's business, not the tag's.
Reaching a relation at all also requires `Resource::canView()` on the owner:
without it the relation endpoint would be a way around a refused view.

`canViewAny()` runs before the query, so a refused manager is absent from the
page *and* costs nothing.

### Operations

| Action | Relation | What it does |
| --- | --- | --- |
| `CreateRelatedAction` | any | creates through the relation, so no form declares a foreign key |
| `EditRelatedAction` | any | edits the record, and its pivot row where there is one |
| `DeleteRelatedAction` | any | deletes the record itself |
| `AttachAction` / `DetachAction` | many-to-many | adds and removes the join row, leaving both records |
| `AssociateAction` / `DissociateAction` | one-to-many | writes and nulls the child's foreign key |
| — | — | attach and associate are mutually exclusive: each is hidden for the shape the other belongs to |
| `RestoreAction` / `ForceDeleteAction` | soft-deleting | undoes or completes a soft delete |

Attach and detach are offered only on a many-to-many, where there is a join
row to add and remove. "Detaching" a `hasMany` child means nulling its foreign
key, which is a different decision with a different name.

### Pivot attributes

```php
public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
{
    return $schema->schema([TextInput::make('role')->maxLength(50)]);
}
```

Pivot fields render and submit under `pivot.` — `pivot.role` — which is what
keeps a `role` column on the join table from overwriting a `role` column on
the record. Only declared fields are validated and persisted; an extra key in
the request body is discarded exactly as it is on a resource form.

Read a pivot column in the table with the same dotted path:
`TextColumn::make('pivot.role')`.

Relation tables paginate through the relation rather than through its query,
because `BelongsToMany::paginate()` is what selects and hydrates the pivot. A
builder taken out of the relation produces rows whose pivot columns all read
as null.

### Soft deletes

`protected static bool $softDeletes = true` opts a manager in. Declared
rather than detected: a related model that uses `SoftDeletes` for something
else should not silently grow a filter the manager never meant to offer.

`TrashedFilter` is what puts a deleted record on screen, and the restore and
force-delete actions are hidden for a record that is not trashed. Add the
filter alongside them or they can never appear.

`resolveRecord()` reaches trashed records for a manager that soft deletes: a
lookup that could not see one could never restore it. Each action still
authorizes independently.

### Relation pages

The same manager, given a route and a place in the record's sub-navigation:

```php
final class ManageUserPosts extends ManageRelatedRecords
{
    protected static string $resource = UserResource::class;

    protected static string $relationManager = PostsRelationManager::class;
}

// In UserResource::pages()
'posts' => ManageUserPosts::class,
```

It routes to `{record}/posts` and joins the sub-navigation automatically,
authorized through the manager. Which of the two to use is a question about
the relation, not about the framework: a handful of rows belongs beside the
record, and a table somebody will page and search through deserves a URL.

### Table state on a record page

Several relation tables share one page, so each reads its state from its own
namespace: `?relations[posts][page]=2`. Sorting one leaves the others where
they were. The namespace is sent as `stateKey` so the frontend never
reconstructs it.

### Nested resources

A resource whose records only exist beneath a parent record:

```php
final class PostResource extends Resource
{
    protected static ?string $parentResource = UserResource::class;

    protected static ?string $parentRelationship = 'posts';
}
```

Every page moves under the parent — `/admin/users/3/posts/7/edit` — and
`Resource::query()` starts from the parent's relation, so a record under
another parent is a 404 without any page checking. It is absent from the
sidebar, because there is no "all posts" to open.

`ResolveParentRecord` binds the parent from the route, resolving it through
the *parent* resource's own `query()` and `canView()`: a parent the user could
not have opened is a 404 here too.

`Resource::url()` uses the request's own parent, so links between a nested
resource's pages need no extra argument; pass `parent:` for a link to a
different one. The action endpoints carry no parent segment, so a nested
resource's table sends `parentKey` with every action it posts.

Two resources cannot claim one path, and registration refuses it. A
`ManageRelatedRecords` page at `projects/{record}/tasks` and a nested resource
at `projects/{parentRecord}/tasks` are the same shape to the router —
parameter names are not part of matching — so one of them would simply be
unreachable. `PanelRouteRegistrar` compares normalized path shapes per panel
and throws at boot rather than letting that be discovered as a page that
renders the wrong thing. Give one of them a different slug.

### Relation fields on a resource form

A `Select` backed by a relation resolves its options on the server:

```php
Select::make('author')->relationship('author', 'name'),   // BelongsTo
Select::make('tags')->relationship('tags', 'name'),       // BelongsToMany
```

A `BelongsTo` writes the foreign key — the field is named after the relation
and persists to `author_id`, with no `->dehydrateTo()` beside it. A
`BelongsToMany` becomes a multiple select and is synced after the record is
saved, because a pivot row needs a key that does not exist until the record
does.

Validation is `exists` on the related table, never `in` of the rendered
options: the option list is one bounded page, and a real key that sorted past
the limit is still a real key. A static option list is still a whitelist.

`->searchable()` makes the rest of that table reachable. The form ships an
`optionsUrl` carrying its own context — which resource, which page, and for a
relation form which owner and operation — and the field appends only its name
and the search term, so a keystroke cannot change which form is being asked
about. The field is resolved out of the schema that declared it, so a request
can only search a field that exists on a form the user can already open, and
it never names a column, a table, or a model.

Without it a relation select is a dead end past 50 rows: it would validate a
key it had no way to show.

For a `HasOne`, `MorphOne`, or `BelongsTo` edited inline, use a relation
group:

```php
Relationship::make('profile')->schema([
    TextInput::make('bio'),
]),
```

Its children are namespaced under the relation (`profile.bio`), which Laravel
validates as nested keys natively and which comes back as an error under the
same dotted key the field renders with. The write happens after the owner is
saved and inside the same transaction — forced for `HasOne` and `MorphOne`,
and kept on the same path for `BelongsTo` so there is one place a related
record is written rather than two that can drift.


---

## 8. Standalone pages

```php
final class Settings extends Page
{
    protected static ?string $title = 'Settings';

    protected static ?string $subheading = 'Application-wide configuration.';

    protected static ?string $slug = 'settings';

    protected static string $component = 'Panels/Admin/Pages/Settings';

    protected static ?string $navigationIcon = 'settings';

    protected static ?string $navigationGroup = 'System';

    protected static int $navigationSort = 100;

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return ['settings' => [/* ... */]];
    }
}
```

Route name: `panel.{id}.pages.{slug}`.

A page needs no Vue file: leaving `$component` at its default
(`panel/Page`) renders the generic page shell. Set it when the page has
something bespoke to draw.

Override `canAccess()`, `breadcrumbs()`, `headerActions()`, or `widgets()` as
needed. `canAccess()` is enforced by the route, so a page hidden from the
sidebar is still refused by URL.

`$middleware` appends to the page's route, for what authorization cannot
express: `RequirePassword` has to redirect to a confirmation screen, while
`canAccess()` can only answer yes or no and would turn that into a 403.

`routePath()` is separate from `slug()`: the slug is the route name and the
registry key, the path is what the address bar shows. Overriding it is how a
page lands on a nested path such as `settings/profile`.

### Built-in settings pages

Every panel gets three account pages, in its own shell and under its own
path:

| Page | Path | Notes |
| --- | --- | --- |
| `ProfileSettings` | `{panel}/settings/profile` | Name and email |
| `SecuritySettings` | `{panel}/settings/security` | Password, 2FA, passkeys; behind `RequirePassword` |
| `AppearanceSettings` | `{panel}/settings/appearance` | Theme, entirely client-side |

They render only. Writing still goes to the application's own
`ProfileController` and `SecurityController`, which redirect `back()`, so
there remains one place that updates a profile no matter which panel the
form was submitted from.

The starter kit's `/settings/*` URLs are kept as aliases: they redirect to
these pages in the first panel the user can enter, so existing links and
Wayfinder output keep working.

Turn them off for a panel that has no business showing them:

```php
$panel->settings(false);
```

---

## 9. Widgets

Four types, one contract.

```php
final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [
            Stat::make('Total users', User::query()->count())->icon('users'),

            Stat::make('Verified', User::query()->whereNotNull('email_verified_at')->count())
                ->icon('shield')
                ->color(StatColor::Success)
                ->description('Confirmed email address'),
        ];
    }
}
```

| Type | Base class | Implements |
| --- | --- | --- |
| stats | `StatsWidget` | `stats(): list<Stat>` |
| table | `TableWidget` | `table(TableSchema)`, `query(): Builder` |
| chart | `ChartWidget` | `labels(): list<string>`, `series(): list<ChartSeries>` |
| custom | `CustomWidget` | `$component`, `data(): array` |

`canView()` is checked **before** `data()` runs, so an unauthorized widget
never executes its queries.

Every widget also has `$heading`, `$description`, `$pollingInterval`, and an
optional filter form — see below.

### Stats

```php
Stat::make('Revenue', 12045)
    ->format(prefix: '£', decimals: 2)   // "£12,045.00"
    ->icon('receipt')
    ->color(StatColor::Success)
    ->trend('up', 12.4)
    ->chart([4, 9, 7, 12, 18, 21])       // a sparkline under the figure
    ->url(OrderResource::url()),
```

Formatting happens on the server because a figure is a number *and* how it
should be read — "1,204" and "£1,204" and "1,204 ms" are three different
statements. A value that is already a string is left exactly as the widget
wrote it.

A sparkline is the context a single number never has: "412" says nothing
about whether that is a good week. A stat with a `url()` is a link to what it
counts, and the destination authorizes for itself when it is followed.

### Charts

```php
protected static ChartVariant $variant = ChartVariant::Area;
protected static int $maxHeight = 200;

public function options(): ChartOptions
{
    return ChartOptions::make()->legend(false)->curved()->filled();
}
```

Variants: `Bar`, `Line`, `Area`, `Doughnut`. Options: legend, grid, stacked,
filled, curved, point labels, a pinned `range()`, and a value `format()`.

Rendered by a dependency-free inline SVG. That is a deliberate trade rather
than a gap: what crosses the wire is a *description* of a chart, and the
renderer that draws it was compiled in. A charting library configured from the
server would mean configuration crossing as behaviour, which is the one thing
that never happens here.

The cost is that a chart has to be expressible in `ChartOptions`. Anything
beyond it is a `CustomWidget`, which is honest about being bespoke.

### Table widgets

```php
public function table(TableSchema $table): TableSchema
{
    return $table->columns([
        TextColumn::make('name')->searchable()->sortable(),
    ])->defaultSort('created_at', SortDirection::Descending);
}

public function query(): Builder
{
    return User::query()->select(['id', 'name', 'created_at']);
}
```

The same `TableSchema` a resource index uses, and the same `TableQuery`:
search, sorting, and pagination all work. The state is namespaced by widget id
(`widgets[recent-users][page]`), which is what makes two table widgets on one
dashboard possible — the same arrangement a relation manager uses.

Still not a resource index: no bulk actions, no column manager, no filter
tabs. A widget is a summary you can look through, not a second place records
are managed from.

### Widget filters and polling

```php
protected static ?int $pollingInterval = 60;

public function filterSchema(): FormSchema
{
    return FormSchema::make()->schema([
        Select::make('months')->options([...])->default('6'),
    ]);
}

public static function filtersInModal(): bool
{
    return true;   // for more than a control or two
}
```

Read inside the widget with `$this->filter('months', 6)`. The values live in
the query string under `widgets[{id}][...]`, are narrowed by the schema that
declared them — the whitelist, exactly as on a form — and are persisted per
page in the session. The request wins whenever the parameter group is
*present*; absence is the only case that falls back to what was stored, and a
filter cleared to nothing stays cleared.

Polling is off by default. It is a request every interval for every open tab,
which is worth it for a queue depth and absurd for a total that changes twice
a day. A poll is a partial reload of the props the page already sends, because
a widget's data *is* a prop of the page it sits on.

### Dashboards

```php
->dashboards([
    Dashboard::class,            // the panel root
    AccountsDashboard::class,    // its own route, navigation item, and filters
])
```

Each is a `Page`, so each authorizes, appears in navigation, and carries its
own filters independently. A dashboard that also lives under a discovered
path is registered once, not twice.

A dashboard's own `filterSchema()` filters every widget on it at once. A
widget's own filter wins over the page's for the keys it declares — a widget
that declares `months` means *its* months.

### Lazy widgets

```php
protected static bool $lazy = true;
```

The definition ships with null data and one deferred prop carries every lazy
payload, keyed by widget id. The renderer shows a skeleton until it lands.

### Responsive grid

`$columnSpan` accepts an int, `'full'`, or a per-breakpoint array. An
undeclared breakpoint inherits the one below it; anything the frontend has
no class for is clamped. The grid is
`grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4` and every span
class is written out in full, because an interpolated `md:col-span-${n}`
would not exist in the bundle.

### Custom widget components

Put the component under `resources/js/pages/Panels/{Panel}/Widgets/`. It is
resolved through a build-time `import.meta.glob`, so a name that was not
compiled in cannot be reached however it arrives. An unknown name renders a
neutral fallback.

Charts use a dependency-free inline SVG (line and bar). No charting library
is installed.

---

## 10. Actions

```php
Action::make('approve')
    ->label('Approve')
    ->icon('check')
    ->variant(ActionVariant::Default)
    ->requiresConfirmation(
        heading: 'Approve this record?',
        description: 'The author will be notified.',
        button: 'Approve',
    )
    ->successMessage('Record approved.')
    ->authorize(static fn (?Model $record): bool => $record !== null && Gate::allows('approve', $record))
    ->before(static fn (Model $record) => Log::info('approving', ['id' => $record->getKey()]))
    ->action(static fn (Model $record) => $record->approve())
    ->after(static fn (Model $record) => event(new RecordApproved($record)));
```

Three kinds:

- **Link** (`->url()`) — navigates to a server-produced URL.
- **Callback** (`->action()`) — posts its name to the panel action endpoint.
- **Form** (`->schema()`) — opens a dialog whose schema is fetched when it
  opens, and whose submit runs the action with the validated data.

Built-ins: `ViewAction`, `EditAction`, `DeleteAction`, `DeleteBulkAction`,
`CreateAction`, `ReplicateAction`, `RestoreAction`, `ForceDeleteAction`,
`ImportAction`, `ExportAction`, and the bulk forms of the last four.

### Modals

```php
Action::make('approve')
    ->modalHeading('Approve this order')
    ->modalSubmitLabel('Approve it')
    ->modalWidth(ModalWidth::Large)
    ->slideOver()
    ->modalContent('Panels/Admin/Modals/Explanation', ['tone' => 'warning'])
    ->modal(static function (Modal $modal): void {
        $modal->stickyHeader()->stickyFooter()->closeByClickingAway(false);
    })
    ->registerModalActions([Action::make('explain')->action(...)]);
```

Everything about how the dialog behaves lives on a `Modal` held beside the
action, so the two are not one class with thirty setters. A slide-over is the
same content in a different place, which is why nothing inside it has to know
which it is in. Custom content is a build-time registry key under
`resources/js/pages/Panels/{Panel}/Modals/` — never markup, the same rule
custom columns, fields, and widgets follow.

`closeByClickingAway(false)` is worth reaching for on a long form, where
losing what was typed to a stray click is the failure people actually hit.

An action registered with `registerModalActions()` is reachable **only**
through the action that declared it. It is not rendered beside the trigger and
is not found by the table's own lookup, so "runnable from this dialog" never
becomes "runnable by name from anywhere".

### Forms on actions

```php
Action::make('note')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('note')->required()->maxLength(1000),
    ]))
    ->action(static function (Model $record, array $data): void {
        $record->notes()->create(['body' => $data['note']]);
    });
```

The schema is built per record and fetched when the dialog opens — a table of
twenty records would otherwise ship twenty copies of a form to open at most
one. A schema holding a `Wizard` is a stepped dialog and needs nothing else
said.

The data reaching the handler has been validated and dehydrated by that
schema, so an extra key in the request body is discarded exactly as it is on a
resource form. A handler that takes one argument never sees the second, which
is why declaring a form is additive: every action written before this existed
runs exactly as it did.

Endpoints: `GET panel.{id}.actions.form` describes the dialog,
`POST panel.{id}.actions.submit` runs it. Both resolve the action against the
scope named in the request — `record`, `table`, `bulk`, or `infolist` — and
each is a different whitelist.

### Import and export

```php
ExportAction::make(UserExporter::class, UserResource::class),   // the filtered list
ExportAction::bulk(UserExporter::class, UserResource::class),   // the selection
ImportAction::make(UserImporter::class, UserResource::class),
```

An `Exporter` is a class rather than a closure because a queued export runs in
a different process from the request that asked for it, and only a class name
crosses that gap. The same reasoning applies to an `Importer`.

```php
final class UserExporter extends Exporter
{
    public static function columns(): array
    {
        return [
            ExportColumn::make('name'),
            ExportColumn::make('email'),
            ExportColumn::make('updated_at')->enabledByDefault(false),
        ];
    }

    public static function query(Builder $query): Builder
    {
        return $query->reorder('id');   // and eager loads for relation columns
    }
}
```

The dialog offers the columns and the format; both are ordinary fields, so a
column name that is not one of the exporter's is refused by the same `in:`
rule any other choice field uses. Columns are written in the order the
exporter declared them, never the order they were ticked — a file whose
columns move cannot be diffed against last week's.

Which records: the selection for a bulk export, and otherwise the list as the
table state describes it, through the same `TableQuery` the list itself uses.
The client sends that state with the request; the server puts every value back
through the table's schema, which is the whitelist, so the worst a crafted
payload can describe is a list the user could have navigated to.

Above `Exporter::queueAfter()` records the export is queued and arrives as a
notification with a download link; below it, it runs in the request and the
link comes back on the response. Both go through `ExportRun`, so the file is
the same either way.

Files land on a **private** disk under `panel-exports/{user}/`, and the
download endpoint builds that segment from whoever is asking — one user cannot
name another's export however they spell the path. A public disk would put a
copy of records at a URL anybody can guess.

An importer declares where each cell lands and what it must be:

```php
ImportColumn::make('email')
    ->guess(['e-mail', 'email address'])
    ->required()
    ->rules(['email', 'max:255'])
    ->castUsing(static fn (string $value): string => mb_strtolower(trim($value))),

ImportColumn::make('company')->relationship('company', 'name')->createRelated(),
```

The headings are guessed from the file and the mapping step lets a person
correct the guess; a column with no match is left unmapped rather than pointed
at the first column. `relationship()` resolves a name to a foreign key and is
`BelongsTo` only — those are the relations whose value is a column of the row
being imported.

A row that fails is collected, not thrown: an import of a thousand rows where
the four-hundredth has a bad date imports nine hundred and ninety-nine and
writes the rest to a failure report with a reason column, which is a file to
correct and re-upload as it stands. Each row is its own transaction, so one
bad row undoes neither the good rows before it nor the related record it had
just created.

Override `Importer::resolve()` to match an existing record and the import
becomes an update — which is what makes re-uploading a corrected report work.

CSV and XLSX are both read and written without a spreadsheet dependency; see
`PandaPanel\Support\Spreadsheet\Xlsx` for what that costs and what it
deliberately does not do.

### How execution stays safe

The request carries an action name, a resource slug, and record keys. Nothing
else. The backend then:

1. resolves the panel for this request;
2. looks the resource up in **that panel's** registry — a resource from
   another panel does not exist here;
3. finds the action in that resource's table schema — an action the resource
   never declared does not exist;
4. loads records through `Resource::query()` — a key outside the scope
   resolves to nothing;
5. authorizes;
6. runs the handler inside a transaction.

A row action the policy refuses is absent from that row, and the endpoint
re-checks anyway. A bulk action authorizes every record before touching any
of them, so a selection containing one forbidden record changes nothing.

Endpoints: `panel.{id}.actions.record`, `.bulk`, `.table`, `.infolist`,
`.form`, and `.submit`. Each resolves against the schema that declared the
action it names, and those are separate whitelists on purpose.

---

## 10b. Integrations

Outbound HTTP on a resource's writes, configured at runtime on a screen laid
out like Postman. Off unless the resource says otherwise:

```php
public static function integrations(Integrations $integrations): Integrations
{
    return $integrations->isEnabled(true);
}
```

`isEnabled(false)` is the default, and it is the default for a reason: turning
this on gives whoever can reach the screen the ability to make *your server*
issue HTTP requests to a destination they type in. Enabling it registers the
model observer and adds `/{panel}/{resource}/integrations`. It creates no
integrations, so an enabled resource with none configured behaves exactly as
before.

### The six triggers

| Trigger | Eloquent event | Sent |
| --- | --- | --- |
| `before_create` | `creating` | inline |
| `after_create` | `created` | queued |
| `before_update` | `updating` | inline |
| `after_update` | `updated` | queued |
| `before_delete` | `deleting` | inline |
| `after_delete` | `deleted` | queued |

Model events rather than page hooks, which is what makes them universal: a
record written by a resource form, a table action, a bulk action, an importer,
a console command or a queued job passes through all six. Hanging them off the
resource pages would have covered the edit screen and nothing else — and
deletion, which has no page hooks at all, would have had none.

What they do not catch, and cannot: `Model::query()->update()` and `->delete()`
never hydrate a model and so fire no events. That is Eloquent's behaviour, not
this framework's, and the panel itself never writes that way.

**An integration is a notification, not a gate.** A `before` trigger fires just
before the write and its response is recorded and dropped; a non-2xx, a
timeout, a DNS failure — none of them cancels anything. The alternative was
considered and rejected: an endpoint going down should not also mean nobody can
save. `after` triggers are queued and retried; `before` ones are sent inline
with a short timeout, because the payload they describe is gone the moment the
write completes.

```php
$integrations->isEnabled(true)
    ->triggers([Trigger::AfterCreate, Trigger::AfterUpdate])   // all six by default
    ->timeout(3);                                             // for the inline ones
```

### What the receiving system is told

```json
{
  "trigger": "after_update",
  "resource": "orders",
  "occurred_at": "2026-08-15T09:12:44+00:00",
  "record": { "id": 41, "status": "shipped" },
  "changed": ["status"]
}
```

Attributes rather than a serialized model, so nothing arrives because it
happened to be eager loaded, and **hidden attributes are excluded** — a
password hash does not leave the building because somebody added a webhook.

A blank body sends that payload as it is. A body written by hand is passed
through `{{ record.field }}` substitution, which resolves dotted paths against
the payload and does nothing else. It is deliberately not Blade: a body typed
into a form is untrusted input, and compiling untrusted input as Blade is
remote code execution with extra steps.

### Signing, so the receiver can trust it

Every request carries an HMAC over its own body. A webhook receiver has no
other way to know who called it — the URL is often guessable, and an endpoint
that acts on whatever is posted to it is an endpoint anybody can drive.

```
X-Panel-Signature: t=1755250000,v1=9f86d0818...
X-Panel-Delivery:  0f9c1a1e-8f4e-4a1b-9b3f-2c7d5e6a1b2c
```

`v1` is `hash_hmac('sha256', "{timestamp}.{body}", $secret)`. The timestamp is
**inside** the signed string, not merely beside it: a signature over the body
alone stays valid forever, and a request recorded once could then be replayed
at any time by anyone who saw it.

`X-Panel-Delivery` is stable across the retries of one delivery, so a receiver
can make its own handling idempotent — which matters, because `after` triggers
are queued and retried.

A secret is generated when an integration is created and encrypted at rest, so
signing is never something somebody forgot to turn on and a database dump does
not hand over the ability to forge a request. Rotate it from the Signing tab;
the next send uses the new one, so update the receiving system first.

Verifying, on the receiving end:

```php
use PandaPanel\Integrations\IntegrationSignature;

if (! IntegrationSignature::verify(
    $request->header('X-Panel-Signature', ''),
    config('services.panel.webhook_secret'),
    $request->getContent(),          // the raw body, not the parsed array
)) {
    abort(403);
}
```

It is shipped rather than only documented because `hash_equals` is the detail
hand-rolled verifications get wrong: a `===` on a hex string leaks how much of
the signature was right through how long the comparison took.

### History, and why it is bounded

Every attempt is recorded — status, duration, the bodies, the error — and shown
on the History tab. Headers are **never** recorded: they hold the API keys
these requests carry, and a log of them would be a credential store nobody
meant to create.

A row per write is a table that outgrows the records it describes, so it is
bounded twice, and both bounds are applied immediately after each delivery
rather than by anything you have to schedule:

```php
'history' => [
    'enabled' => true,
    'keep_per_integration' => 50,   // a hard cap
    'retention_days' => 30,         // and a window
],
```

The cap is the one that makes the guarantee true: it holds in an application
with no scheduler at all, and bounds the table at cap × integrations however
much traffic there is. The window exists for the opposite case — a handful of
integrations that fire twice a year and would otherwise keep rows from three
years ago.

The summary on the integration itself (`last_status`, `last_error`) is kept
separately and is not part of history: it is one column, it is what the list
colours itself with, and reading it beats aggregating a child table on every
render.

### Two gates on every destination

This screen makes the server request a URL a user typed, which is server-side
request forgery by construction. Both of these must pass, when the integration
is saved *and* again immediately before each request:

```php
// config/panda-panel.php
'integrations' => [
    'allowed_hosts' => ['api.example.com', '*.partner.io'],
    'block_private_networks' => true,
],
```

`allowed_hosts` is empty by default, so nothing is reachable until a
destination is added — adding one is a deploy rather than a form submission.
Patterns are `Str::is()` and anchored at both ends, so `*.partner.io` does not
match `partner.io.attacker.test`.

`block_private_networks` refuses any host resolving into the private, loopback
or link-local ranges. `169.254.169.254` is the one that matters most: it is the
unauthenticated cloud metadata endpoint that hands out IAM credentials, and it
stays blocked even with `allowed_hosts` set to `*`. A host that does not
resolve at all is allowed — no request can reach it anyway, and refusing would
break split-horizon DNS and container hostnames for nothing.

Reaching the screen needs three things: the resource opted in, the user passes
the resource's own `viewAny`, and the user passes the
`manage-panel-integrations` gate. The last **denies when no gate is defined**,
so an application that has not decided who may do this has decided nobody may.

---

## 10a. Notifications

Three places a notification can go, and they compose:

```php
Notification::make('export-ready')
    ->title('Your export is ready')
    ->body('1,204 records')
    ->success()
    ->persistent()                 // also store it, so it can be read later
    ->actions([
        NotificationAction::make('download')
            ->label('Download')
            ->url($url),
    ])
    ->send($user);
```

- **Toast** — broadcast to the user's open panels. Transient: if nobody was
  looking it never happened, which is right for "Saved." and wrong for a job
  that finished ten minutes after the request that started it.
- **Database** — `->persistent()` writes a row through Laravel's own
  `notifications` table, so `unreadNotifications`, `markAsRead()`, and the
  rest work without a line of code, and a notification sent by anything else
  in the application shows up in the panel's bell.
- **Both** — the default when a notification is persistent. `->broadcast(false)`
  turns the toast off for a case where the response already carries one, so
  the same message is not shown twice.

`->persistent()` is off by default. Most notifications answer something the
user just did, and a bell that fills up with "Saved." is a bell nobody reads.

An action is a **label and a URL**, never an action name to resolve. A
notification outlives the page that sent it — the schema that declared an
action may not exist next week — so what crosses is a link, and whatever it
points at authorizes for itself when it is followed. Following one marks the
notification read by default.

### The bell

The unread count comes down on **every** panel request rather than being
polled, so the badge is right after any navigation without a second round
trip. The list itself is fetched only when the bell is opened: a notification
nobody looked at costs nothing.

Endpoints: `panel.{id}.notifications.index`, `.read`, `.clear`. Every one is
scoped to `$request->user()`'s own rows by construction, so the scope *is* the
authorization — there is no id a request could send that would reach somebody
else's. An id belonging to another user matches nothing rather than 403s,
which is the same outcome and one fewer thing to leak.

A stored row is JSON somebody wrote a week ago, which makes it as untrusted as
a request body: a colour that is not one of the four falls back, and an action
that does not parse is dropped rather than rendered.

Turn the bell off for a panel with `->notifications(false)`. The endpoints
stay — a job can still write a notification the user reads in another panel —
but the control is absent rather than present and empty.

---

## 11. Authorization

Resource helpers delegate to the Gate:

| Method | Ability |
| --- | --- |
| `canViewAny()` | `viewAny` |
| `canView($record)` | `view` |
| `canCreate()` | `create` |
| `canEdit($record)` | `update` |
| `canDelete($record)` | `delete` |
| `canDeleteAny()` | `deleteAny` |
| `canRestore($record)` | `restore` |
| `canRestoreAny()` | `restoreAny` |
| `canForceDelete($record)` | `forceDelete` |
| `canForceDeleteAny()` | `forceDeleteAny` |

The `*Any` abilities are what a bulk action asks before it has a record to ask
about; each record is then authorized individually before any is written, so a
selection containing one forbidden record changes nothing. Under
`strictAuthorization()` a policy that omits one of these fails loudly rather
than reading as a working deny.

Panel access is a predicate on the panel:

```php
->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin)
```

An authenticated user who fails it gets **403**, not a redirect.

Pages use `canAccess()`, widgets use `canView()`. Every one of these is
checked on the route or before data resolution, never only in the sidebar.

---

## 11a. Clusters

A set of resources and pages that belong together, under one prefix:

```php
final class OperationsCluster extends Cluster
{
    protected static ?string $slug = 'ops';
    protected static ?string $navigationIcon = 'settings';
    protected static ?string $activeNavigationIcon = 'shield';
    protected static ClusterPosition $position = ClusterPosition::Header;
}

// On the member, never listed on the cluster:
protected static ?string $cluster = OperationsCluster::class;
```

Everything in it lives under `/admin/ops/...`, and every page in it renders
the cluster's own sub-navigation — so moving between siblings never means
going back to the sidebar. `ClusterPosition` puts that bar under the header,
beside the content in a right-hand column, or nowhere but the sidebar.

**Route names are untouched by the prefix.** A resource is still
`panel.admin.resources.roles.index`, so every `Resource::url()` already
written keeps working and only the path it produces changes. That is what
makes adopting a cluster a non-breaking change.

Membership is declared by the member so a class carries its own place in the
panel. The cluster is listed once in the sidebar, expanding to its members
rather than sitting beside them, and it points at the first member the user
may actually see. A cluster with nothing visible renders nothing.

---

## 11b. Navigation and layout

```php
->navigationGroups([
    'Content',
    NavigationGroup::System,     // a backed enum works too
    'Access' => 'System',        // nests Access under System
])
->topNavigation()                // a top bar instead of a side rail
->sidebarWidth('18rem', '4rem')  // CSS lengths, not sizes
->navigation(false)              // remove the rail entirely
->topbar(false)
->breadcrumbs(false)
->sidebarComponent('Panels/Admin/Shell/Sidebar')   // a replacement
->topbarComponent('Panels/Admin/Shell/Topbar')
->userMenuItems([
    ['label' => 'Support', 'url' => '/support', 'icon' => 'info'],
])
```

An enum is worth reaching for once more than one class names the same group: a
mistyped string is a second group that looks like the first, while a mistyped
enum case does not compile.

Widths are CSS lengths because they become custom properties. A class built by
interpolation would not exist in the bundle — the same reason every colour and
size in this framework is an enum.

Turning a piece of the shell off removes it rather than hiding it. A
replacement sidebar or topbar is a build-time registry key under
`resources/js/pages/Panels/{Panel}/Shell/`, handed the same navigation the
built-in one gets; an unregistered name falls back to the built-in bar, because
a mistyped component must not strand somebody on a page they cannot leave.

`activeNavigationIcon` gives an item a second icon worn while it is the active
one. It is sent with every item whether or not it currently is, so the swap
happens on a client-side navigation without a round trip.

`usePanelShell()` refetches part of the shell — `reloadNavigation()`,
`reloadTopbar()`, `reloadShell()` — as a partial reload of the shared props.
There is no endpoint that answers "what does the sidebar look like now",
because it would have to re-resolve the panel, the user, and the URL to say
anything true, which is what a request already does.

---

## 11c. Panel authentication

```php
->auth()                    // the panel needs a signed-in user
->login()                   // ...and has its own front door
->registration()
->passwordReset()
->emailVerification()
->requireTwoFactor()
```

`login()` gives the panel its own login page at its own path, carrying its
brand. The form posts to **Fortify's** endpoint: duplicating the login POST per
panel would mean duplicating rate limiting, two-factor, passkeys, and session
handling — four things that must never disagree between two doors into the same
application.

It also changes where a guest is *sent*. That redirect is wired in
`bootstrap/app.php` through `redirectGuestsTo()`, not in a provider: the
framework registers its own default inside the same hook when the HTTP kernel
resolves, so anything a provider sets is overwritten on every request.

### Where a signed-in user lands

The other direction, and the one an install gets wrong by default. A Laravel
Vue starter kit ships a `/dashboard` route with a placeholder page, and Fortify
points its post-login redirect at it. Installing a panel changes neither, so
the first screen after signing in is the placeholder and the panel is somewhere
you have to know the URL of.

`RedirectPanelHome` takes that path over: a signed-in user who lands on it is
sent to the first panel they can enter.

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

Middleware on the `web` group rather than a route this package registers —
`/dashboard` is the application's, and a package competing for the same URI
would be relying on registration order to win. The route, its name, and its
page component all stay where they are, so turning this off gives the screen
back exactly as it was.

The paths are `Request::is()` patterns. A path a panel is itself mounted on is
ignored, which is what stops a panel at `/dashboard` redirecting to itself
forever, and a request that is not a GET, wants JSON, or comes from a guest is
left alone — a guest belongs at the login, which is the guest redirect's
question rather than this one's.

`requireTwoFactor()` holds a user at the security page until they have one.
Middleware rather than a check per page, because the point is the pages a user
has *not* reached. A passkey counts — a panel that demanded an authenticator
app from somebody already using a hardware key would be demanding a downgrade.

### Emailed codes as a second factor

For somebody who will not install an authenticator app — weaker than TOTP and
much stronger than nothing, which is the choice it actually competes with.

Turned on from the security page, which is behind `RequirePassword`, so
enabling it is already somebody who just proved they are the account holder.

It is a **session challenge**, not part of the login POST. Fortify owns signing
in — its rate limiting, its passkeys, its TOTP — and reaching into that
pipeline to add a channel would mean owning a fork of it. This works the way
password confirmation does: `RequireEmailCode` holds every page until the
session carries the mark, so a new session on a new device is challenged even
though the password was right. The mark lives in the session, so it dies with
it; a second factor that survived signing out would not be one.

The code lives in the cache, hashed, for ten minutes. Not a table: a row that
outlives a cache flush is a row somebody has to remember to prune, and an
expiry the storage enforces cannot be forgotten. Hashed because a cache a
support engineer can read is a cache that can be read, and a code in it is a
password for one login.

Two rate limits, because they are two different attacks — five sends an hour (a
mailbox is not a place to be flooded) and five guesses a minute (six digits is
a million tries at machine speed). A correct code is spent the moment it is
accepted; a wrong guess never spends it.

`requireTwoFactor()` accepts it, alongside TOTP and passkeys.

### Multi-tenancy

Not built in. `docs/panel-tenancy.md` is a guide to putting this framework on
top of [`stancl/tenancy`](https://tenancyforlaravel.com) with a database per
tenant, identified by subdomain — which extension points to use, what order to
do it in, and the two mistakes that look like they work.

The short version: `Panel::middleware()` puts the tenancy identification ahead
of `ResolvePanel`, `Panel::domain()` keeps a central panel off tenant
subdomains, and with a database per tenant `Resource::query()` needs no
scoping at all because the connection *is* the boundary.

### Who may enter

Two questions, and both must agree:

```php
// A rule about the panel:
->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true)

// A rule about the user, on the model:
final class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->suspended;
    }
}
```

A closure on the panel is right for "this one is for administrators"; the
contract is right for "this account is suspended" — written on the model it
applies to every panel at once and cannot be forgotten when a new one is added.
A panel that says yes cannot overrule a user model that says no. A user model
implementing neither is refused nothing.

---

## 12. Navigation

Built per request from the panel's registries. There is no hardcoded array
anywhere.

- Groups follow the order declared by `navigationGroups()`; undeclared groups
  are appended alphabetically.
- An item the user cannot see is dropped before its badge is evaluated.
- Empty groups disappear.
- Exactly one item is active: an exact path match wins, otherwise the longest
  matching prefix, so `/admin/users/3/edit` keeps Users active.
- Active state is decided on the server and sent as a boolean.

Icons are string keys resolved through `resources/js/panel/icons/registry.ts`.
Add a key there when a panel needs a new icon; an unknown key renders no icon.

---

## 13. Discovery

```php
->discoverResources(app_path('Panels/Admin/Resources'))
->discoverPages(app_path('Panels/Admin/Pages'))
->discoverWidgets(app_path('Panels/Admin/Widgets'))
```

Neither panel in this application registers a class by hand.

- Class names come from Composer's PSR-4 prefixes, not from parsing files.
- Only concrete classes implementing the expected contract are included;
  abstract bases, value objects, and enums in the same tree are skipped.
- Results are sorted, so two machines produce the same manifest.
- Explicit registration still works and merges with discovery.

---

## 14. Caching

```bash
php artisan panel:cache
php artisan panel:clear
```

The manifest is `bootstrap/cache/panels.php`, written atomically, holding
class names only:

```php
return array (
  'admin' =>
  array (
    'resources' => array ( 0 => 'App\\Panels\\Admin\\Resources\\Users\\UserResource' ),
    'pages' => array ( 0 => 'App\\Panels\\Admin\\Pages\\Settings' ),
    'widgets' => array ( /* ... */ ),
  ),
);
```

With a manifest present, discovery does not run: no filesystem scan, no
reflection, nothing per request.

Never cached: authorization results, navigation active state, badge values,
record data, widget data. Those depend on the user and the URL, so caching
them would serve one person's answers to everybody.

The commands are registered as `optimize` hooks, so `php artisan optimize`
and `optimize:clear` include them.

---

## 15. Artisan commands

### Icons

```bash
php artisan panel:icons          # rewrite the registry from the source
php artisan panel:icons --check  # fail if it is out of date, for CI
```

Lucide ships 1768 icons; a panel uses a couple of dozen, and only those
belong in the bundle. The command scans `app/` for every shape an icon name
is declared in — `->icon('x')`, `$navigationIcon = 'x'`, the `icon:` named
argument, and `'icon' => 'x'` in a serialized array — checks each against the
icons Lucide actually ships, and rewrites
`resources/js/panel/icons/registry.ts`.

Write whatever Lucide name you want and run it. A name Lucide does not have
fails the command by name, which is the only warning you get: an unregistered
icon renders nothing at all, with no error.

The registry stays a build-time allowlist. Generating it changes who
maintains the list, not what it guarantees.

```bash
php artisan make:panel Admin
php artisan make:panel Admin --path=back-office

php artisan make:panel-resource User --panel=Admin
php artisan make:panel-resource User --panel=Admin --simple
php artisan make:panel-resource User --panel=Admin --no-view
php artisan make:panel-resource User --panel=Admin --soft-deletes
php artisan make:panel-resource Account --panel=Admin --model="App\Models\User"

php artisan make:panel-page Settings --panel=Admin
php artisan make:panel-page Settings --panel=Admin --component

php artisan make:panel-widget UserStats --panel=Admin --type=stats
php artisan make:panel-widget Recent --panel=Admin --type=table
php artisan make:panel-widget Growth --panel=Admin --type=chart
php artisan make:panel-widget Health --panel=Admin --type=custom

php artisan make:panel-relation-manager posts --panel=Admin --resource=Users
php artisan make:panel-relation-manager tags --panel=Admin --resource=Posts --type=belongs-to-many
php artisan make:panel-relation-manager posts --panel=Admin --resource=Users --soft-deletes --page
```

`--type` is an option rather than something inferred from a name because the
relation's *shape* decides which actions belong on it: a `hasMany` is created
and deleted, a `belongsToMany` is attached and detached. Naming the wrong one
produces a manager offering an operation the relation cannot perform, which
is visible, rather than one silently missing.

Every flag changes the output. There is no `--view`, because the view page is
generated by default and `--no-view` removes it; a flag that did nothing
would read as supported.

Nothing is overwritten without `--force`. `make:panel` prints the line to add
to `PanelServiceProvider` rather than editing it silently.

Generated code passes this project's Pint and PHPStan runs, and a test
asserts that.

---

## 16. Frontend architecture

```text
resources/js/panel/
  layouts/      PanelLayout, SidebarPanelLayout, HeaderPanelLayout
  components/   PanelSidebar, PanelNavigation, PanelHeader, PanelBreadcrumb,
                PageHeader, EmptyState, LoadingState
  tables/       DataTable, DataTableCell, DataTableToolbar,
                DataTableFilters, DataTablePagination, DataTableBulkActions
  forms/        FormRenderer, FormComponentRenderer, FormSection, FormGrid,
                FormField, fields/*
  widgets/      WidgetGrid, WidgetRenderer, StatsWidget, TableWidget,
                ChartWidget, CustomWidget, WidgetFallback, registry
  actions/      ActionButton, ActionGroup, ActionDialog
  composables/  usePanel, useNavigation, usePanelPage, useResource, useActions
  icons/        registry
  types/        panel, navigation, breadcrumb, page, table, form, action,
                widget, cellGuards, widgetGuards
```

Every panel page declares its own layout, so nothing has to be wired in
`resources/js/app.ts`:

```ts
defineOptions({ layout: PanelLayout });        // and PanelBlankLayout for auth
```

The one thing an application can still get wrong is overwriting that choice.
This is the shape to avoid:

```ts
page.default.layout = AppLayout;               // replaces the panel shell
page.default.layout ??= AppLayout;             // correct
```

An unconditional assignment puts every panel screen inside the application's
own shell, with the host sidebar and the panel navigation nowhere, at HTTP 200
and with nothing logged. `panel:install` reads `app.ts` and refuses to finish
quietly when it finds one, naming the file and the line.

### Rules that keep the frontend honest

- **No `any`.** Metadata unions are discriminated on `type` and every switch
  ends in an exhaustive `never` check, so a new PHP type without a Vue
  renderer is a compile error.
- **Validate, do not assert.** Values crossing from PHP are narrowed by
  guards (`cellGuards.ts`, `widgetGuards.ts`, `usePanelPage.ts`), so a shape
  mismatch degrades to an empty cell instead of throwing.
- **No server state in local state.** The only local state is a search input
  (debounced), form working values, row selection, and collapsed navigation
  groups.
- **No interpolated Tailwind classes.** Column spans, badge colours, grid
  columns, and content widths all map through literal records.
- **No hardcoded panel URLs.** Every href comes from the server or from
  Wayfinder.

TanStack Table v9 is used for the column model, visibility, and row
selection only. Sorting, filtering, and pagination are server-side, so their
features are deliberately not registered.

---

## 16a. Theming and CSS hooks

Two separate things, kept separate on purpose.

### Colours are values

A panel's palette is a set of CSS custom properties. There is no closed enum
here because a colour is a *value*, not a meaning — a panel may want any
colour, and a Tailwind class cannot be built from an arbitrary one anyway.

```php
Panel::make('admin')
    ->colors(
        light: ['primary' => '#4f46e5', 'sidebar' => 'oklch(0.98 0 0)'],
        dark: ['primary' => '#818cf8'],
    );
```

The values land in a `style` attribute on the panel shell as `--primary`,
`--sidebar`, and so on, which is what the Tailwind v4 theme already reads.
Two guards apply, both silent:

- **The property must be one the stylesheet reads** (`PanelTheme::PROPERTIES`).
  A typo would otherwise be a custom property nothing consumes — a theme that
  does not apply and does not say why.
- **The value must parse as a colour** (`#rgb`, `rgb()`, `hsl()`, `oklch()`).
  Anything else is dropped, because the value goes into a `style` attribute
  and `red; content: url(...)` is a stylesheet, not a colour.

A bad value is dropped rather than refused: a panel with one bad colour
should still render with the rest of its theme.

### Hooks are meanings

`cssHooks()` adds classes to named parts of the shell. Unlike colours, the
set of names is closed — a name is a place in the layout, and a name nothing
renders is a class a panel can set and never see.

```php
Panel::make('admin')->cssHooks([
    'topbar' => 'border-b-2 border-amber-500',
    'table-row' => 'hover:bg-amber-50',
]);
```

The names, all of `CssHooks::HOOKS`:

| Hook | Rendered by |
| --- | --- |
| `shell` | `SidebarPanelLayout.vue`, `HeaderPanelLayout.vue` |
| `sidebar` | `PanelSidebar.vue` |
| `topbar` | `PanelHeader.vue` |
| `page` | the layouts |
| `page-header` | `PageHeader.vue` |
| `table`, `table-row` | `DataTable.vue` |
| `form` | `FormRenderer.vue` |
| `infolist` | `InfolistRenderer.vue` |
| `widget` | `WidgetShell.vue` |
| `modal` | `ActionModal.vue` |

Two calls append rather than replace, because both meant it. An unknown name
is dropped. `StylingTest` asserts that every name in the allowlist is
actually emitted by some component, so adding a name without wiring it up
fails the suite.

On the Vue side both come from one composable:

```ts
const { themeStyle, hook } = usePanelStyling();
```

Classes added this way must survive the Tailwind build. Arbitrary strings
from a panel provider are not in any file Tailwind scans, so either use
classes that appear elsewhere in the app or add the provider to the content
globs.

---

## 16b. Plugins

A plugin is a configurable bundle of panel configuration. It does exactly
what a panel provider can do, and nothing more — which is what stops it doing
something a panel cannot.

```php
Panel::make('admin')->plugins([
    ReportingPlugin::make()->withCharts()->group('Insights'),
]);
```

### Writing one

Extend `PandaPanel\Plugins\Plugin`:

```php
final class ReportingPlugin extends Plugin
{
    private bool $charts = true;

    public function withCharts(bool $charts = true): self
    {
        $this->charts = $charts;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ReportResource::class]);

        if ($this->charts) {
            $panel->widgets([RevenueChart::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        // Anything needing the container, the authenticated user, or a URL.
    }

    /** @return array<string, string> */
    public function publishes(): array
    {
        return [__DIR__.'/../resources/js' => resource_path('js/pages/Panels/Reporting')];
    }
}
```

The id defaults to the kebab-cased class name without the `Plugin` suffix
(`ReportingPlugin` → `reporting`). Two plugins claiming one id throws at
registration, because `hasPlugin('reporting')` has to have one answer.

### The two phases

- **`register()`** runs while the panel is being configured, inside
  `plugins()`. Resources, pages, widgets, and navigation belong here.
- **`boot()`** runs on `Panel::boot()`, after the panel is resolved and
  before the panel's own `bootUsing()` callback — so an application can undo
  what a plugin did.

Work that needs the container, the request, or the authenticated user must be
in `boot()`. `register()` runs while providers are still registering.

A panel can be asked about its plugins, which is how a resource reads its own
plugin's configuration without the panel repeating it:

```php
$panel->hasPlugin('reporting');   // bool
$panel->plugin('reporting');      // the configured instance, or null
```

### Assets

Every component registry is an `import.meta.glob` over
`resources/js/pages/Panels/**` — a build-time allowlist. A plugin's Vue
component has to live there to be resolvable at all, so it is published:

```bash
php artisan panel:publish              # every plugin in every panel
php artisan panel:publish reporting    # one plugin
php artisan panel:publish --force      # overwrite local edits
```

Without `--force` an existing file is never overwritten. A published file the
application has since edited is work, and silently replacing it is losing it.

Run `npm run build` afterwards: the glob is evaluated at build time, so a
newly published component is not in the bundle until then.

---

## 17. Testing

The suite lives under `tests/Feature/Panel/`, including a `Negative/`
directory whose whole subject is what happens when something is declared
wrongly or asked for by somebody who may not have it.

```bash
vendor/bin/pest --compact
vendor/bin/pest --filter=ResourceQuery

composer run analyse         # PHPStan
vendor/bin/pint src tests    # Pint; `composer run format-check` to check only
npm run format:check && npm run lint && npm run typecheck && npm run build
```

`composer run ci` and `npm run ci` chain each side's checks.

PHPStan runs at the level set in `phpstan.neon`, which states its own reason
for the level it is at. Read the file rather than trusting a number written
here — this block previously named four scripts that do not exist and a level
that was not the configured one.

### What a new resource should be tested for

- Unauthorized access returns 403 on every route, including the write verbs
  and the action endpoint.
- Search matches only whitelisted columns.
- An unknown or non-sortable sort column is ignored.
- `perPage` clamps; an invalid filter value is rejected.
- Records resolve through `Resource::query()`, so an out-of-scope key 404s.
- Create validates and persists only declared fields.
- Update leaves an untouched password alone.
- Delete authorizes per record; a bulk selection containing one forbidden
  record changes nothing.
- The serialized table and form contain no closures and no class names.
- The list route issues no query per row.

Assert behaviour, not status codes. `assertOk()` alone proves very little.

### Expressive helpers

Four helpers under `src/Testing/`, shipped with the package and exposed as
global functions through composer's autoloaded `files` — so they are available
in an application's own suite with no import and no base class. Each one goes
through the same code the application does: they are a nicer way to *ask*,
never a second implementation of the answer. A helper that computed its own
idea of what a table shows would pass while the table was broken.

**Tables** — `panelTable(Resource::class)`. State is set the way a URL sets
it, so anything the helper can do a request can do:

```php
panelTable(UserResource::class)
    ->filter(['verified' => TernaryFilter::FALSE])
    ->search('Grace')
    ->sort('name', 'asc')
    ->page(2)
    ->assertCanSeeRecord($grace)
    ->assertCanNotSeeRecord($ada)
    ->assertCount(1)
    ->assertRecordsInOrder([$ada, $grace])
    ->assertColumnExists('email')
    ->assertCellEquals($ada, 'email', 'ada@example.test');
```

`records()`, `keys()`, and `row()` return the underlying values when an
assertion does not fit. `row()` is the record as the frontend receives it, so
an editable column's cell is the input's state (`['value' => …, 'disabled'
=> …]`) rather than a string.

**Actions** — four scopes, matching the four places a schema declares them:

```php
panelRecordActions(UserResource::class)->assertExists('edit');
panelTableActions(UserResource::class)->call('purgeUnverified');
panelBulkActions(UserResource::class)->assertCanNotRun('delete');
panelInfolistActions(UserResource::class)->assertVisible('impersonate', $user);
```

Lookups go through the same schema the controller resolves against, so an
action the helper can find is one the endpoint can find. `call()` checks
authorization first and fails the test rather than skipping it — running an
action the user may not run would prove the handler works and nothing about
whether it is reachable. `assertVisible()` asks the question the row asks:
visible *and* authorized, because an action refused for a record is absent
from the row rather than a button that answers 403.

**Forms** — `panelForm(Resource::class)`:

```php
panelForm(UserResource::class)
    ->assertHasField('email')
    ->assertDoesNotHaveField('password_confirmation')
    ->assertFieldIsRequired('name')
    ->assertDehydratesTo(['name' => 'Ada', 'unknown' => 'x'], ['name' => 'Ada']);
```

`dehydrate()` answers where most form bugs live: a field that validates and
then does not persist, or one that persists under a different name.

**Notifications** — `fakePanelNotifications()` first, then:

```php
fakePanelNotifications();

// ... exercise the code ...

assertPanelNotificationSentTo($user, 'Saved.');
assertNoPanelNotifications();
```

`assertPanelNotificationStoredFor()` is a different question from the toast:
whether the notification can be found later. A broadcast that was not
persisted is gone the moment the tab closes, so the two assertions are not
interchangeable.

Useful facts when writing panel tests:

- Inertia puts `flash` **beside** `props` on the page object, so flash
  assertions read `viewData('page')['flash']`.
- A partial reload must send `X-Inertia-Version`, or the response is a 409.
- Calling a page controller directly needs the panel context that
  `ResolvePanel` would have set:
  `app(PanelManager::class)->setCurrentPanel(panel('admin'))`.
