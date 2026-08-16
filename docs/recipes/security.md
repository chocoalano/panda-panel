# Locking a Panel Down

A panel is a large public surface: every table parameter reaches a query builder, every action endpoint takes a record key from a payload, every export writes a file addressed by name. This page is the work of closing that surface for one panel — strict authorization, policies that answer every ability the panel asks, a query narrowing that holds on every route, tenant scoping, the upload and action endpoints, and the file downloads — followed by the tests that prove each of them. Read it before a panel carrying real data goes live, and again whenever a resource, an endpoint, or a job is added to one.

Nothing here is a separate security system. Every control is an ordinary Laravel policy, an ordinary middleware, or a whitelist the schema already declares; the work is making sure each is actually in place.

## A minimal working example

Four lines and a policy close the common holes:

```php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

return $panel
    ->path('admin')
    ->auth()                                      // a guest is redirected
    ->requireTwoFactor()                          // and a signed-in user without a second factor is held
    ->strictAuthorization()                       // a missing policy throws instead of denying
    ->canAccess(static fn (?Authenticatable $user): bool
        => $user instanceof User && $user->is_admin);   // 403, not a redirect
```

```php
final class ProductPolicy
{
    public function viewAny(User $user): bool { return $user->is_admin; }
    public function view(User $user, Product $product): bool { return $user->is_admin; }
    public function create(User $user): bool { return $user->is_admin; }
    public function update(User $user, Product $product): bool { return $user->is_admin; }
    public function delete(User $user, Product $product): bool { return $user->is_admin; }
    public function deleteAny(User $user): bool { return $user->is_admin; }
}
```

Everything below is the rest of the surface.

## The door

Two independent rules, and both must agree.

```php
public function auth(bool $verified = true): self
public function canAccess(Closure $callback): self            // Closure(?Authenticatable): bool
public function isAccessibleTo(?Authenticatable $user): bool
```

`auth()` appends `auth` and `verified` to the panel's auth middleware — it accumulates rather than replacing. `canAccess()` is a predicate on **this panel**, and a signed-in user who fails it gets **403**, never a redirect: they are already past a login, and a redirect would tell them a different panel exists.

The other half is a rule about **the account**, written on the user model so it applies to every panel at once and cannot be forgotten when a fourth panel is added:

```php
use PandaPanel\Contracts\PanelUser;
use PandaPanel\Core\Panel;

final class User extends Authenticatable implements PanelUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasVerifiedEmail() && $this->suspended_at === null;
    }
}
```

`isAccessibleTo()` asks the contract first and the closure second. A panel that says yes cannot overrule a user model that says no, and a permissive user model cannot loosen a panel's predicate. A user model implementing neither is refused nothing.

`PandaPanel\Http\Middleware\ResolvePanel` enforces it on every route the panel registers — pages, resource pages, action endpoints, search, uploads, exports. There is no page you can forget to protect.

Three more decisions belong at the door:

```php
$panel
    ->requireTwoFactor()                    // held at the security page until a second factor exists
    ->domain('admin.example.com')           // keeps the panel off every other host
    ->middleware(['web', VerifyIpAllowlist::class]);   // REPLACES the base stack — 'web' must be listed
```

`requireTwoFactor()` is middleware rather than a per-page check because the point is the pages a user has *not* reached; a check on each one is a check somebody has to remember to add to the next. A passkey counts, so somebody already using a hardware key is not asked to downgrade to a code. The security page and the account settings pages beside it are exempt, or there would be nowhere to go and do the thing being demanded.

The stack the registrar builds, in order:

```text
{panel middleware, 'web' by default}
{panel auth middleware, from auth()}
ResolvePanel:{id}          → binds the panel, then abort_unless(isAccessibleTo, 403)
RequireTwoFactor:{id}      → no-op unless requireTwoFactor()
RequireEmailCode:{id}      → for an account that turned emailed codes on
ResolveTenant:{id}         → only when the panel declared tenant()
```

## Strict authorization

```php
public function strictAuthorization(bool $strictAuthorization = true): self
public function hasStrictAuthorization(): bool      // false by default
```

A missing policy reads exactly like a policy that said no. With strict authorization on, a resource whose model has no registered policy — or whose policy is missing the ability being checked — throws `PandaPanel\Exceptions\PanelAuthorizationException` naming the model and the ability instead of quietly denying.

Every ability the panel asks goes through one place, `PandaPanel\Support\PolicyGate`:

```php
public static function allows(string $ability, Model|string $subject, array $arguments = []): bool
```

`Resource::authorize()` and `RelationManager::authorize()` both delegate here, and neither calls `Gate::allows()` directly — which is what keeps the guarantee true for the relation abilities too, and they have no `can*` method on a resource to hang it off.

A policy defining `before()` is exempt, since it can answer for every ability.

Turn it on in development at least. It is off by default because it turns a 403 into a 500, and in production a denial is the safer answer — but a resource that silently denies everything because `restoreAny` was never written is a bug that reads like a working rule.

## Policies that answer everything the panel asks

| Resource method | Ability | Subject |
| --- | --- | --- |
| `canViewAny()` | `viewAny` | the model class |
| `canView($record)` | `view` | the record |
| `canCreate()` | `create` | the model class |
| `canEdit($record)` | `update` | the record |
| `canDelete($record)` | `delete` | the record |
| `canDeleteAny()` | `deleteAny` | the model class |
| `canRestore($record)` | `restore` | the record |
| `canRestoreAny()` | `restoreAny` | the model class |
| `canForceDelete($record)` | `forceDelete` | the record |
| `canForceDeleteAny()` | `forceDeleteAny` | the model class |

Relation managers ask two more sets. Reading and writing the related *record* are abilities on that record's own policy; membership is on the **owner's**:

| Manager method | Ability | Policy |
| --- | --- | --- |
| `canAttach($owner)` | `attachAny` | the owner's |
| `canDetach($owner, $record)` | `detach` | the owner's, record as the second argument |
| `canAssociate($owner)` | `associateAny` | the owner's |
| `canDissociate($owner, $record)` | `dissociate` | the owner's, record as the second argument |

The `*Any` abilities are what a bulk action asks before it has a record to ask about. Each record is then authorized **individually before any is written**, so a selection containing one forbidden record changes nothing.

Override a `can*()` to state a rule the policy cannot — but route it back through `authorize()`, or the strict-mode guarantee stops holding for that ability:

```php
final class ProductResource extends Resource
{
    public static function canCreate(): bool
    {
        return parent::canCreate() && Product::query()->count() < 10_000;
    }
}
```

```php
protected static function authorize(string $ability, Model|string $argument): bool
{
    return PolicyGate::allows($ability, $argument);
}
```

Hiding a navigation item or a button is a convenience, never the control. Every one of those checks is asked again, on the server, at the point the thing is used.

## The query is the scope

`Resource::query()` is the single funnel. `ListRecords`, `findRecord()`, `findRecords()`, every action endpoint, `GlobalSearch`, `TableQuery`, and the exporters all go through it — so a record it excludes is a **404** everywhere rather than a filtered row on one screen.

```php
use Illuminate\Database\Eloquent\Builder;

public static function query(): Builder
{
    // parent::query() applies $with, the tenant scope, and the panel's own
    // per-panel narrowing. An override that skips it drops all three, and
    // nothing about the resulting screen says so.
    return parent::query()->where('team_id', auth()->user()?->team_id);
}
```

404 rather than 403 is deliberate: as far as this resource is concerned the record does not exist, and a 403 would confirm that it does.

The per-panel form of the same thing, for a resource shared between two panels:

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->modifyQueryUsing(static fn (Builder $query) => $query->whereKey(auth()->id())),
]);
```

## Tenant scoping

In a single-database arrangement a missing `where` is a data leak. The panel declares what a tenant is and how to find one; a resource declares the relation that leads to it.

```php
use App\Models\Workspace;
use Illuminate\Http\Request;

$panel->tenant(
    Workspace::class,
    static fn (Request $request): ?Workspace => Workspace::query()
        ->where('slug', $request->route('workspace'))
        ->first(),
);
```

```php
final class DocumentResource extends Resource
{
    protected static ?string $tenantRelationship = 'workspace';
}
```

`ResolveTenant` runs last in the stack, after the user is known and before any controller can query. Three things happen and all three must succeed: the tenant is identified (null is a **404** — the request named something that does not exist), the user is checked against it with `HasPanelTenants::canAccessPanelTenant()` (false is a **403**, deliberately not a 404: hiding which tenants exist from somebody who already named one is theatre that costs a comprehensible error), and only then is it bound.

A user model that does not implement `PandaPanel\Contracts\HasPanelTenants` belongs to nothing as far as a tenant-scoped panel is concerned, so every request is refused. That is the correct failure, and a loud one.

The important detail: a scoped resource with **no tenant bound throws** rather than running unscoped.

```php
public static function require(): Model   // PanelRegistrationException when nothing is bound
```

A resource that declared itself tenant-scoped and then ran unscoped would return every tenant's records and look like a working page. Console and queue work that legitimately runs outside a request enters a tenant explicitly:

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($workspace, static fn () => DocumentResource::query()->each(/* … */));
```

`canAccessPanelTenant()` must be an independent query, never `getPanelTenants()->contains(...)`. The list is built for a dropdown and may be sorted, trimmed, or paginated; a security answer must not change when a display decision does.

Full checklist: [Tenancy Security Checklist](../tenancy/security-checklist.md). The recipe: [Tenant Panel](tenant-panel.md).

## Actions

An action is a name in a payload. It is looked up in the schema of the resource that was named, inside the panel that was resolved for the request, so an action the resource never declared simply does not exist.

```php
public function authorize(Closure $callback): static           // Closure(?Model): bool
public function authorizeEachUsing(Closure $callback): static  // Closure(Model): bool — every record, before any write
public function visible(Closure $callback): static             // Closure(?Model): bool — drawing only
public function requiresConfirmation(...): static
```

`visible()` decides whether a button is drawn. `authorize()` decides whether it may run, and is asked again by the endpoint when it does. The first is a convenience; the second is the control.

```php
Action::make('publish')
    ->authorize(static fn (?Model $record): bool => $record !== null
        && ProductResource::canEdit($record))
    ->authorizeEachUsing(static fn (Model $record): bool => ProductResource::canEdit($record))
    ->action(static fn (Model $record) => $record->forceFill(['is_published' => true])->save());
```

Six endpoints, and each is a **different whitelist**. An action declared in one place does not exist in another, however the request spells it:

| Path | Looked up in | Notes |
| --- | --- | --- |
| `POST /{panel}/actions/record` | the table's record actions | one record, resolved through `Resource::findRecord()` |
| `POST /{panel}/actions/bulk` | the table's bulk actions | each record authorized before any is written |
| `POST /{panel}/actions/table` | the table's header, toolbar and empty-state actions | no record |
| `POST /{panel}/actions/infolist` | `Resource::infolist()` | a view page's actions are not a table's |
| `POST /{panel}/actions/cell` | the table's editable columns | re-checks `update` and re-applies `disabledUsing()` |
| `POST /{panel}/actions/reorder` | the table's reorder column | |

The record key must be a scalar. Passing an array through would turn `find()` into a collection lookup and quietly change the meaning of the request, so it is a 422.

### Editable columns

`ToggleColumn`, `TextInputColumn`, `SelectColumn` and `CheckboxColumn` write through `/actions/cell`. The rendered control is never the control:

```php
ToggleColumn::make('is_admin')
    ->disabledUsing(static function (Model $record): bool {
        $actor = auth()->user();

        // The policy lets a user edit their own record, which is right for a
        // display name and catastrophic for a privilege flag.
        return ! ($actor instanceof User && $actor->is_admin)
            || $actor->is($record);
    });
```

```php
public function rules(array $rules): static
public function disabledUsing(Closure $callback): static
public function writeTo(string $attribute): static
public function updateUsing(Closure $callback): static
```

## Table input is a whitelist, not an escape

Every table parameter arrives from the URL and reaches a query builder. The rule is that a name the schema did not declare **does not exist** — not "is escaped", not "is quoted", but is refused or ignored outright.

| Parameter | Narrowed by |
| --- | --- |
| `sort` | the column being `->sortable()`. Declared is not the same as orderable |
| `direction` | anything that is not `desc` falls back to ascending, before reaching the builder |
| `perPage` | must be one of the schema's `perPageOptions()`; anything else falls back to `defaultPerPage()` |
| `page` | a negative or absurd value answers 200 |
| `search` | `%` and `_` are literal characters, not a way to dump the table |
| `filters[…]` | each filter's own `sanitize()`; a `SelectFilter` accepts only a declared option key |
| `group`, `columns[]` | the declared groups and columns |
| a query-builder rule | the constraints and operators that filter offered |

You get all of that by declaring the schema. What you have to decide is what to declare: a column marked `searchable()` is a column somebody can search, and a `QueryBuilderFilter` constraint on a column is a column somebody can probe with ranges.

## Forms write only what they declared

A create or an edit writes the fields the schema declared and nothing else. An extra key in the request body is discarded rather than saved, which is a second lock on top of the model's `$fillable`.

Both locks are worth having. Keep a privilege flag out of `$fillable` even though no form declares it:

```php
/**
 * `is_admin` is deliberately absent. Registration and profile updates both
 * fill from request input, and a privilege flag that is mass-assignable is a
 * privilege anyone can grant themselves by adding a field to a form post.
 *
 * @var list<string>
 */
protected $fillable = ['name', 'email', 'password'];
```

Relation forms follow the same rule, and keep the two halves apart: the related record's own fields under their own names, the pivot's under `pivot.`. Only what `pivotForm()` declared reaches the join row.

## Uploads

The upload endpoint is the place a request could otherwise choose where a file lands. It does not get to.

```text
POST /{panel}/uploads
body:  field, file
query: resource, and one of — page=create | page=edit&record=… | relation&operation=… | action&scope=…
```

The request names a **resource and a field**, never a disk or a directory. Both come from the field's own declaration, looked up in the schema that declared it:

```php
FileUpload::make('image')
    ->image()                       // sets the common image mime types
    ->disk('public')
    ->directory('products')         // normalized: '..' stripped, slashes trimmed
    ->maxSize(2048)                 // kilobytes
    ->acceptedTypes(['image/png', 'image/webp']);
```

Everything declared there is enforced **twice** — once by the upload endpoint against the real file, and again when the form is submitted, because they are two requests and only the second attaches the path to a record. A submitted path outside the declared directory is refused rather than attached.

Which permission an upload needs is the one that would be needed to submit the form the field belongs to, and nothing weaker:

| Context in the query string | Schema built | Ability asked |
| --- | --- | --- |
| `page=create` | the resource's create form | `create` |
| `page=edit` + `record` | the resource's edit form | `update` on that record |
| `relation` + `operation` | the relation form | the relation's own, per operation |
| `action` + `scope` | the action's form | the action's own |

The context is built by the server and travels in the query string; the client adds only the field name and the file. Reading a resource is never enough — an upload writes a file to a disk this application chose, and the ability to look at a list is not the ability to put something on that disk.

## Files that leave the panel

An export is a copy of records somebody was allowed to see. A failure report is a copy of what somebody tried to import. Both are exactly the kind of file worth guessing at, and both are addressed by a name the request supplies.

```php
public static function disk(): string { return 'local'; }        // never 'public'
public static function directory(): string { return 'panel-exports'; }
```

Files are filed under the **owner's key**, and the download endpoint builds that segment from the authenticated user:

```text
{disk}/panel-exports/{user key}/{file}
{disk}/panel-imports/{user key}/{file}
```

```php
// PanelExportController and PanelImportController, both:
abort_if(
    $file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..'),
    404,
);
```

A name, never a path. A traversal has nowhere to go because the caller never supplies a directory, and one user naming another's file finds nothing because the lookup is in their own.

Never move exports to a public disk. A public disk puts a copy of records at a URL anybody can guess, and the download endpoint exists precisely so the question is asked again.

CSV formula escaping is on by default (`Exporter::escapesFormulas()`). A cell beginning `=`, `+`, `-` or `@` is neutralised with a leading apostrophe. The attacker is anyone who can write a text field and the victim is the administrator who opens the export, which is exactly the shape of an admin panel. Turn it off only for a file another *program* parses.

## Search

Global search is opt-in per resource and passes four independent narrowings:

| Layer | Enforced by |
| --- | --- |
| Transport | the panel's middleware stack — the same door every other route uses |
| Resource | `Resource::canViewAny()`, asked before the resource is queried at all |
| Row | `Resource::query()` — tenant scope, per-panel narrowing, the resource's own scope |
| Payload | a hit is a title, a URL and a map of strings — no model, no query, no closure |

So the decision that is yours is which resources to make searchable, and what `globalSearchResultDetails()` puts in the palette. A detail line is shown to anybody who may view the resource at all.

## Outbound requests

A resource with integrations enabled gets a screen where an administrator configures outbound HTTP requests fired on its writes. The server issues those requests, which makes the screen a server-side request forgery surface by construction — the destination is typed into a form rather than written in code.

Two gates, and a URL has to pass both:

```php
// config/panda-panel.php

'integrations' => [
    // Str::is() patterns. Empty, so nothing is reachable until a destination
    // is added here: deny by default, and adding one is a deploy rather than
    // a form submission.
    'allowed_hosts' => [
        // 'api.example.com',
        // '*.partner.io',
    ],

    // Refuses unresolved hosts and any host resolving into private, loopback
    // or link-local ranges — 169.254.169.254 above all, the unauthenticated
    // cloud metadata endpoint that hands out IAM credentials. Checked when an
    // integration is saved and again immediately before each request, because
    // a name approved last week can resolve elsewhere today. IPv4-mapped IPv6
    // literals are normalized back to IPv4 before these checks run.
    'block_private_networks' => true,
],
```

Leave the second on. It is what makes relaxing the first survivable. Deliveries do not follow
redirects, so an allowed host cannot bounce the server into a private address after validation.
Delivery history stores bodies truncated and never stores headers — they hold the API keys these
requests carry, and a log of them would be a credential store nobody meant to create.

## Notifications

Every notification route is scoped to the authenticated user's own notifications by construction: the query starts from `$request->user()`, so there is no id a request could send that would reach somebody else's. The scope *is* the authorization, which is why none of them take a policy.

## Verifying it

```bash
# The middleware is actually on the routes.
php artisan route:list --path=admin

# Nothing in the panel reads records outside Resource::query().
grep -rn "::query()" app/Panels/Admin --include=*.php

# Every privilege flag is out of $fillable.
grep -rn "fillable" app/Models --include=*.php

# The manifest matches the code that is deployed.
php artisan panel:cache
```

A checklist to work through once:

- [ ] `auth()` on every panel that is not deliberately public.
- [ ] `canAccess()` states a rule about the panel; `PanelUser::canAccessPanel()` states one about the account.
- [ ] `strictAuthorization()` on in development, and every ability it named has been written.
- [ ] Every resource's model has a registered policy, including the four soft-delete abilities when `$softDeletes` is true.
- [ ] Every `can*()` override calls `parent::` or routes through `authorize()`.
- [ ] Every `query()` override calls `parent::query()`.
- [ ] Every action that writes has an `authorize()`, and every bulk action an `authorizeEachUsing()`.
- [ ] Every editable column that touches a privilege has a `disabledUsing()`.
- [ ] Every `FileUpload` states its disk, directory, size, and accepted types.
- [ ] Exports are on a private disk.
- [ ] `integrations.allowed_hosts` is empty, or every entry in it is deliberate; `block_private_networks` is true, and unresolved hosts are treated as failures.
- [ ] In a tenant panel: every tenant-owned resource names `$tenantRelationship`, and the ones that do not are written down with a reason.

## The tests

Write the refusals as things that must not happen. Three shapes cover most of it:

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use App\Panels\Admin\Resources\Products\Exports\ProductExporter;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
    $this->product = Product::factory()->create();
});

/*
 * Privilege escalation — past the policy without pressing a button.
 */

it('refuses a member every route to a record they may not touch', function (): void {
    $this->actingAs($this->member);

    $this->get('/admin/products')->assertForbidden();
    $this->get("/admin/products/{$this->product->id}")->assertForbidden();
    $this->put("/admin/products/{$this->product->id}/edit", ['name' => 'x'])->assertForbidden();

    $this->post('/admin/actions/record', [
        'resource' => 'products', 'action' => 'delete', 'record' => $this->product->id,
    ])->assertForbidden();

    $this->post('/admin/actions/cell', [
        'resource' => 'products', 'column' => 'is_published',
        'record' => $this->product->id, 'value' => true,
    ])->assertForbidden();

    expect($this->product->fresh()->is_published)->toBeFalse();
});

it('is a 404, not a 403, in a panel that does not register the resource', function (): void {
    // Nothing there to be refused: the route was never registered.
    $this->actingAs($this->admin)->get('/app/products')->assertNotFound();
});

/*
 * Hostile table input — a name the schema never declared does not exist.
 */

function appliedSortFor(string $query): ?string
{
    return test()->get('/admin/products?'.$query)
        ->viewData('page')['props']['state']['sort'] ?? null;
}

it('treats a SQL fragment in the sort parameter as a name, not as SQL', function (): void {
    $this->actingAs($this->admin);

    foreach (['name; drop table products', 'name) or 1=1--', '1'] as $fragment) {
        expect(appliedSortFor('sort='.urlencode($fragment)))
            ->toBeNull("sort={$fragment} was accepted as a column");
    }

    // And the one that is sortable is honoured, so the above is a whitelist
    // rather than a parameter that never works.
    expect(appliedSortFor('sort=name'))->toBe('name');

    // The table is still there, which a successful injection would not leave.
    expect(Product::query()->count())->toBe(1);
});

/*
 * File access — an export belongs to exactly one user.
 */

it('refuses traversal and another user\'s directory alike', function (): void {
    Storage::fake('local');

    Storage::disk('local')->put('secret.csv', 'ROOT SECRET');
    Storage::disk('local')->put(
        ProductExporter::directory().'/'.$this->member->id.'/theirs.csv',
        'sku,name',
    );

    $this->actingAs($this->admin);

    foreach (['../secret.csv', '..%2Fsecret.csv', '/etc/passwd', 'subdir/secret.csv', '.', '..'] as $attempt) {
        $response = $this->get('/admin/exports/'.$attempt.'?exporter='.urlencode(ProductExporter::class));

        expect($response->getStatusCode())->toBeIn([403, 404]);
    }

    // The name is right; the directory is not this user's.
    $this->get('/admin/exports/theirs.csv?exporter='.urlencode(ProductExporter::class))
        ->assertNotFound();
});
```

The framework states the same properties about itself in ten files under `tests/Feature/Panel/Negative/`:

| File | States that |
| --- | --- |
| `HostileTableInputTest` | a sort, filter, group, search or page parameter the schema never declared is ignored, not escaped |
| `PrivilegeEscalationTest` | no guessed URL, hand-written POST or swapped id gets past a policy |
| `ScopeBypassTest` | a record `Resource::query()` excludes is unreachable through every endpoint that takes a key |
| `SchemaEscapeTest` | a create or edit writes only what the form declared |
| `MalformedInputTest` | input of the wrong shape is answered, never crashed into |
| `FileAndDataAccessTest` | an export, import report or notification belongs to exactly one user |
| `SpreadsheetFormulaTest` | a CSV cell cannot become a formula in the reader's spreadsheet |
| `SchemaMistakeTest` | a schema that cannot mean what it says refuses at build time, naming the wrong name |
| `UnreachableDeclarationTest` | a declaration pointing at something that cannot answer fails with a message naming it |
| `SilentAbsenceTest` | "my resource is missing" has a cause somebody can read |

Three of those guards were verified by deleting them and confirming the suite fails. That is the standard worth holding a security test to: one that passes with the guard removed is decorative.

```bash
php artisan test --compact tests/Feature/Panel/Negative
```

## Gotchas

- **`middleware()` and `authMiddleware()` replace; `auth()` merges.** A panel that calls `middleware([SomeCheck::class])` has just dropped `web`, and with it sessions and CSRF. A panel that calls `authMiddleware([])` has made itself public on purpose.
- **`strictAuthorization()` turns a 403 into a 500.** That is the right trade in development and a judgement call in production.
- **A `can*()` override that calls `Gate::allows()` directly** silently opts that ability out of strict mode.
- **A `query()` override that forgets `parent::query()`** drops the eager loads, the tenant scope, and the per-panel narrowing at once, and the screen still renders.
- **`canAccess()` runs on every request into the panel.** A query in it is a query on every page load — and it is also asked *outside* a request, by the switcher and by `firstAccessibleTo()`, so it must not read `request()->route()`.
- **A hand-registered route is not inside the panel's group.** It carries none of the stack. Name the middleware classes and pass the panel id: `ResolvePanel::class.':'.$panel->getId()`.
- **A central panel on a tenant subdomain scheme needs `domain()`.** Without it, `admin.example.test` is identified as a tenant called `admin`.
- **`visible()` is not authorization.** An action hidden but not authorized is an action a hand-written POST can still run.
- **The `*Any` abilities are separate methods.** A policy with `delete` but no `deleteAny` refuses every bulk delete, and under strict mode throws instead.
- **A cached manifest means discovery does not run.** After adding a resource in production, run `php artisan panel:cache` again — a resource that is not in the manifest has no routes and no policy checks, because it does not exist.

## See also

- [Panel Access Rules](../panels/access.md)
- [Authorization](../concepts/authorization.md)
- [Resource Authorization](../resources/authorization.md), [Action Authorization](../actions/authorization.md)
- [Page Authorization](../pages-navigation/authorization.md), [Widget Authorization](../widgets/authorization.md)
- [Relation Policies](../relations/policies.md)
- [Tenancy Security Checklist](../tenancy/security-checklist.md)
- [Search Security](../search/security.md)
- [Authentication Security](../authentication/security.md), [Two-Factor](../authentication/two-factor.md)
- [File Uploads](../forms/file-uploads.md)
- [Negative Security Tests](../testing/negative-security-tests.md), [Testing Authorization](../testing/authorization.md)
- [Debugging a 403](../troubleshooting/authorization-403.md)
- [Production Checklist](../deployment/production-checklist.md)
- [Tenant Panel](tenant-panel.md), [Import and Export](import-export.md)
- [Reporting a Vulnerability](../contributing/security.md)
