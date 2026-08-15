# Why Panda Panel

This page is the argument, not the tour. It says which problems the framework's shape exists to
solve, and which it does not. Read it before adopting the package — if none of these are your
problems, a hand-written CRUD controller is a smaller answer, and if the first one is, nothing else
here matters as much.

## The one-line case

An application whose frontend is Vue should not run a second component model to get an admin panel.

```php
namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class UserResource extends Resource
{
    protected static string $model = User::class;

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
            TextInput::make('email')->email()->required()->maxLength(255),
        ]);
    }

    /** @return array<string, class-string> */
    public static function pages(): array
    {
        return ['index' => ListUsers::class];
    }
}
```

That is PHP. What renders it is a Vue component you can open, read and edit, compiled by the same
Vite build as the rest of the application, styled by the same Tailwind theme, and typed by the same
`tsconfig`. There is no second build, no second state model, and no second set of components to
learn.

## One component model

The decision that produced everything else: PHP describes, Vue renders, Inertia carries. Schemas
serialize to scalars and arrays. A closure is evaluated on the server and only its result crosses.

```php
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('name')
    ->tooltip(static fn (Model $record): ?string => $record->getAttribute('email'))
    ->url(static fn (Model $record): ?string => UserResource::url('view', $record));
```

`tooltip()`, `url()`, `formatUsing()` and `extraAttributes()` all take closures, and every one of
them runs during serialization. What arrives in the browser is a cell holding a string and an href.
Nothing executable crosses the wire, which is why a panel definition can hold application logic
without that logic becoming part of the client bundle.

## Authorization is answered where the data is

Every resource ability resolves to an ordinary Laravel policy. Nothing in a policy needs to know a
panel exists.

| `Resource` method | Gate ability |
| --- | --- |
| `canViewAny(): bool` | `viewAny` |
| `canView(Model $record): bool` | `view` |
| `canCreate(): bool` | `create` |
| `canEdit(Model $record): bool` | `update` |
| `canDelete(Model $record): bool` | `delete` |
| `canDeleteAny(): bool` | `deleteAny` |
| `canRestore(Model $record): bool` | `restore` |
| `canRestoreAny(): bool` | `restoreAny` |
| `canForceDelete(Model $record): bool` | `forceDelete` |
| `canForceDeleteAny(): bool` | `forceDeleteAny` |

Hiding a button is a convenience. Routes, actions, pages and widgets each authorize independently,
and every one of those checks is covered by a test that requests the URL directly.

A missing policy therefore denies. That is the intended default, and it is also the thing that hides
mistakes, so a panel can demand that policies be answerable:

```php
$panel->strictAuthorization();   // off by default
```

Under it, a model with no policy — or a policy with no method for the ability being asked — raises
`PandaPanel\Exceptions\PanelAuthorizationException` instead of quietly refusing. A policy defining
`before()` is exempt, since it can answer for every ability. Every check runs through
`PandaPanel\Support\PolicyGate::allows()`, so this is one rule rather than ten.

## One query, scoped once

`Resource::query()` is the single entry point for every record a resource can reach: list, view,
edit, update, delete, bulk, action lookup and global search.

```php
use Illuminate\Database\Eloquent\Builder;

public static function query(): Builder
{
    return parent::query()->where('team_id', currentTeamId());
}
```

Override it once and a record outside the scope is a 404 on every route, not a filtered row on one
of them. A page that queries the model directly is a bug, and a test asserts against it.

The same seam carries tenancy. A resource names the relationship that leads to the tenant and the
scope is applied for it:

```php
protected static ?string $tenantRelationship = 'team';
```

Outside a tenant, a scoped resource raises rather than running unscoped, because an unscoped query
returns every tenant's records and looks like a working page. Console and queued work enters one
explicitly:

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($tenant, fn () => InvoiceResource::query()->count());
```

## The URL is the table state

Page, per-page, search, sort, direction, filters, visible columns, grouping and the active tab all
live in the query string:

```text
/admin/users?search=ada&sort=name&direction=asc&perPage=25&page=2&filters[verified]=true&tab=admins
```

Back, forward, refresh and bookmark work without a client-side store, and a support ticket can carry
a link to exactly what somebody was looking at. Every value is put back through the table schema
before it reaches the builder, so an unknown sort column, an out-of-range `perPage` or an
unrecognised filter is ignored rather than executed.

The cost is a server round trip per interaction. That is stated plainly in
[Package Limits and Tradeoffs](tradeoffs.md), because it is the trade this design makes and not a
detail.

## Panels do not leak into each other

Isolation is structural, not conventional:

- Each panel has its own resource, page, widget and navigation registries.
- Routes are registered per panel; a resource not registered in a panel has no route there.
- `Resource::url()` throws when asked for a URL in a panel that does not register it.
- The action endpoint exists on every panel and resolves the named resource against **that panel's**
  registry, so a valid session on one panel cannot address another panel's resource through it.

```php
UserResource::url();                          // /admin/users, in the current panel
UserResource::url('edit', $user);             // /admin/users/3/edit
UserResource::url('index', null, 'app');      // the app panel's URL, or a throw
```

The same class can also mean something different in each panel without a subclass:

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People')
        ->navigationGroup('Company')
        ->modifyQueryUsing(fn (Builder $query) => $query->where('is_admin', false)),
]);
```

## The frontend is yours

The panel's Vue components are published into `resources/js` on install. They are in your
repository, in your build, and editable. That is forced by the component registries — each is an
`import.meta.glob` over the application's own tree, so a component the build never saw cannot
resolve however a request spells its name — and it is also the point: a component you cannot read
the source of is one you cannot debug.

The cost is that `composer update` cannot improve a file you now own, and `vendor:publish` cannot
tell a stale file from an edited one. `panel:assets` can, because `.panel-assets.json` records what
each file looked like when it was published:

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # write only the files you have never touched
```

| On disk | In package | Reported as | `--update` |
| --- | --- | --- | --- |
| unchanged | unchanged | current | — |
| unchanged | changed | out of date | written |
| changed | unchanged | yours | left alone |
| changed | changed | conflict | never written |

## Explicit over magic

Panels are listed in `config/panda-panel.php` rather than discovered, because registration order
decides where a user lands when the request does not name a panel, and a reader should see that in
one place. The classes *inside* a panel are discovered, because listing every resource by hand is
the boilerplate worth removing.

Fluent setters keep bare names (`->id()`, `->path()`); readers are `get`-prefixed (`getId()`,
`getPath()`). PHP cannot overload, and a combined accessor returning `string|static` is exactly the
kind of magic this framework avoids.

## Tests can ask the real machinery

The package ships helpers as global functions through composer's autoloaded `files`, so an
application's suite needs no import and no base class:

```php
panelTable(UserResource::class)
    ->search('Grace')
    ->sort('name', 'asc')
    ->assertCanSeeRecord($grace)
    ->assertCanNotSeeRecord($ada)
    ->assertCount(1);

panelForm(UserResource::class)
    ->assertFieldIsRequired('name')
    ->assertDehydratesTo(['name' => 'Ada', 'unknown' => 'x'], ['name' => 'Ada']);

panelRecordActions(UserResource::class)->assertExists('edit');
panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
panelBulkActions(UserResource::class)->call('delete');
panelInfolistActions(UserResource::class)->assertVisible('impersonate', $user);

fakePanelNotifications();
assertPanelNotificationSentTo($user, 'Saved.');
```

Every one goes through the same schemas, queries and actions the application does. They are a nicer
way to *ask*, never a second implementation of the answer — a helper that computed its own idea of
what a table shows would pass while the table was broken.

## When not to reach for it

- **Your frontend is Blade, React or Svelte.** Every panel screen is an Inertia response rendered by
  a Vue SFC. The server half serializes to plain arrays and is framework-agnostic, but no other
  renderer is written and none is planned.
- **You need one CRUD screen.** A controller and a Blade view is less machinery than a panel.
- **You are on Laravel 11 or below.** Not supported, and not installable — see
  [Compatibility Matrix](../getting-started/compatibility.md).
- **You want a client-side table.** Sorting, filtering and pagination are server-side by design.
  TanStack Table is used for the column model, visibility and row selection only.

## Notes

- Adopting the package does not move your authentication. Fortify keeps every auth endpoint; a
  panel's own login page posts to Fortify's routes rather than duplicating rate limiting,
  two-factor, passkeys and session handling.
- Two starter kit addresses change behaviour and both stay addresses: `/dashboard` redirects a
  signed-in user into the first panel they can enter (`home_redirect.enabled => false` turns it
  off), and `/settings/*` is untouched by the package.
- `strictAuthorization()` is worth turning on in a test environment even when production runs
  without it: a policy method somebody forgot to write is the failure it is designed to name.

## See also

- [Overview](overview.md) — what the package is and how to install it
- [Feature Overview](features.md) — everything that exists, by class name
- [Architecture at a Glance](architecture.md) — how a request becomes a screen
- [Comparison With Filament Concepts](filament-comparison.md) — what was borrowed and what was not
- [Package Limits and Tradeoffs](tradeoffs.md) — the costs of every decision above
- [Authorization](../concepts/authorization.md) and [Resource Authorization](../resources/authorization.md)
- [Tenancy Concepts](../tenancy/concepts.md)
- [Testing Helpers](../testing/helpers.md)
