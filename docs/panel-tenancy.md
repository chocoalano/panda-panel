# Multi-tenancy with the panel framework

How to put this panel framework on top of
[`stancl/tenancy`](https://tenancyforlaravel.com) with a database per tenant,
identified by subdomain.

**This is a guide, not a description of installed code.** Nothing here is
wired up yet. `stancl/tenancy` is not a dependency of this application, and
there are no `tenants` or `domains` tables. Everything below has been checked
against what the framework actually exposes — the extension points named are
real — but the steps are yours to run, because they add a dependency and
change your database schema.

The compatibility question is settled: `stancl/tenancy` v3.10.1 resolves
cleanly against this application's Laravel 13 and PHP 8.4 (`composer require
stancl/tenancy --dry-run` installs four packages and reports no conflicts).

---

## 1. What you are choosing between

Three decisions shape everything that follows. Make them before you install
anything.

### One database or many

`stancl/tenancy` supports both. **Database per tenant** is what this guide
assumes, because it is what you asked for and because it removes a whole class
of bug: there is no `tenant_id` to forget on a query, since the connection
itself is the boundary.

What it costs: migrations run per tenant, a tenant is created asynchronously
(a database has to be made), and anything central — users, plans, the tenant
list — lives in a separate connection you have to be deliberate about.

The single-database alternative scopes every query by `tenant_id`. Cheaper to
run, and every unscoped query is a data leak waiting to be found. If you take
it, the panel-side work is the same; only §4 changes.

### How a tenant is identified

Subdomain (`acme.example.test`) is what this guide assumes. `stancl/tenancy`
also identifies by path (`/acme/...`) or by full domain.

Subdomain has a consequence worth knowing before you commit: **cookies and
sessions are per-host by default**. A user signed in on `acme.example.test`
is not signed in on `beta.example.test` unless you set
`SESSION_DOMAIN=.example.test`. That is either what you want (a hard boundary)
or a support ticket every day, and it is much easier to decide now than to
change later.

### Whether a user belongs to one tenant or many

With a database per tenant, users usually live **inside** the tenant database,
and "one user, many tenants" means separate rows that happen to share an email
address. If you need one account across tenants, the user table has to be
central and the tenant databases hold everything else — a real design
decision, not a setting.

This guide assumes users live in the tenant database, which is the simpler and
more common arrangement for database-per-tenant.

---

## 2. Install and configure

```bash
composer require stancl/tenancy
php artisan tenancy:install
php artisan migrate
```

`tenancy:install` publishes `config/tenancy.php`, a `TenancyServiceProvider`,
the `tenants`/`domains` migrations, and a `database/migrations/tenant`
directory. Register the provider in `bootstrap/providers.php`.

Then, in `config/tenancy.php`:

```php
'tenant_model' => App\Models\Tenant::class,

'central_domains' => [
    'example.test',        // where tenants are created and billed
    'admin.example.test',  // and where your admin panel lives
],

'bootstrappers' => [
    Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
    Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
],
```

The **cache bootstrapper is not optional for this framework.** The panel
stores per-user state in the cache — the emailed-code second factor
(`PandaPanel\Auth\EmailCodeChallenge`) keys on the user id, and user 1 exists in
every tenant database. Without cache separation, one tenant's code would
satisfy another tenant's challenge. The same reasoning covers table state,
widget filters, and anything else keyed by an id that is only unique within a
tenant.

Your tenant model:

```php
namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

final class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * Columns that are real columns rather than JSON in `data`.
     *
     * The panel reads `name` for the switcher and `logo` for the brand, so
     * both are worth promoting out of the JSON blob — a switcher that has to
     * decode JSON to draw a label is a switcher that cannot be sorted by it.
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'logo', 'plan', 'trial_ends_at'];
    }
}
```

Add those columns in a migration on the **central** connection.

---

## 3. Routing: which panels are central, which are tenant

This is the part that matters most, and it is the part a naive install gets
wrong.

`PanelRouteRegistrar` registers every panel's routes at boot with the panel's
own middleware stack. `stancl/tenancy` needs its identification middleware to
run **before** anything that touches the database — including this framework's
`ResolvePanel`, because a panel's `canAccess` predicate reads the user, and
which user depends on which database.

The panel API already has the two hooks you need:

```php
// A tenant panel: identified by subdomain, on the tenant's database.
Panel::make('app')
    ->path('app')
    ->middleware([
        'web',
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
    ])
    ->auth();

// A central panel: no tenancy middleware, and pinned to a central host.
Panel::make('admin')
    ->path('admin')
    ->domain('admin.example.test')
    ->auth();
```

`->middleware()` replaces the base stack, which is what puts the tenancy
middleware ahead of `ResolvePanel` — the registrar appends `ResolvePanel`
last, always. `->domain()` is what keeps the central panel off tenant
subdomains; without it, `admin.` would be identified as a tenant called
`admin`.

Check the order with `php artisan route:list --path=app` once. The tenancy
middleware must appear before `PandaPanel\Http\Middleware\ResolvePanel`.

### Routes registered at boot, tenants resolved per request

`PanelRouteRegistrar::registerAll()` runs in `PanelServiceProvider::boot()`.
That is fine — routes are static, and identification happens per request — but
it means **a panel cannot be registered per tenant.** Every tenant gets the
same panels. If tenants need different resources, express it with
`Resource::canViewAny()` and `Panel::canAccess()`, not by registering
different panels.

---

## 4. Scoping resources

With a database per tenant, **there is nothing to do.** `Resource::query()`
runs on whatever connection is current, and the current connection is the
tenant's. That is the whole benefit of this arrangement: no `tenant_id`, so
none to forget.

Two things still need saying.

**`Resource::query()` remains the single scope.** Everything in this framework
goes through it — the list, the record lookup, actions, global search, exports.
If you need a further narrowing inside a tenant, put it there and nowhere else.

**Central resources need a connection.** A panel on the central domain
managing tenants reads a model on the central connection:

```php
final class Tenant extends BaseTenant
{
    protected $connection = 'central';  // or whatever config/tenancy.php names
}
```

Without it, opening the tenant list from inside a tenant context lists that
tenant's own database — which will not have a `tenants` table, and the error
will not say why.

### If you chose a single database instead

Then scoping *is* your job, and `Resource::query()` is the one place to do it:

```php
public static function query(): Builder
{
    return parent::query()->where('tenant_id', tenant('id'));
}
```

And automatic ownership on create — the framework calls
`FormSchema::dehydrate()` and then writes, so a global `creating` observer or
`BelongsToTenant` from `stancl/tenancy` is the right place, not a form field.
A tenant id that can be submitted is a tenant id that can be changed.

---

## 5. The tenant switcher, menu, and profile

The framework already has the pieces; they need pointing at tenants rather
than panels.

**The switcher.** `PanelSwitcher.vue` renders `page.props.panels` — a list of
`{id, name, path, icon, url, current}` built in
`HandleInertiaRequests::switchablePanels()`. A tenant switcher is the same
shape with the same component: share a `tenants` prop built from the user's
own tenants, each `url` being that tenant's domain. Filter it the way panels
are filtered — by what the user may actually enter — so the switcher never
offers a destination that answers 403.

**The menu.** `Panel::userMenuItems()` takes links the server produced. Tenant
profile, billing, and "leave this tenant" belong there:

```php
->userMenuItems([
    ['label' => 'Team settings', 'url' => '/app/tenant', 'icon' => 'settings'],
    ['label' => 'Billing', 'url' => '/app/billing', 'icon' => 'receipt'],
])
```

**Tenant profile and registration** are ordinary panel pages —
`php artisan make:panel-page TenantProfile --panel=App`. Registration is a page
on the *central* panel, because a tenant that does not exist yet has no
subdomain to be created from.

**Tenant search** is `Panel::globalSearch()` over a central `Tenant` resource,
on the central panel. Searching tenants from inside a tenant is a question
about the central database, and answering it from a tenant context means
crossing the boundary the whole design exists to keep.

**Avatar, name, and default tenant.** `name` and `logo` are the custom columns
from §2. A default tenant is a column on the central user record — or, if
users live in tenant databases, a central `tenant_user` pivot with a
`is_default` flag. There is no framework hook for this: it decides where a
bare login lands, which is routing, not panel configuration.

---

## 6. Billing and subscription requirements

The framework's answer is `Panel::canAccess()` and the `PanelUser` contract,
and the difference between them matters here:

```php
// A rule about the panel — this one needs a live subscription.
Panel::make('app')->canAccess(
    static fn (?Authenticatable $user): bool => tenant()?->subscribed() === true,
);
```

That gives a 403, which is the wrong answer for an expired trial: the user
should be sent to billing, not told no. For that, use a middleware in the
panel's stack — the same shape as `RequireTwoFactor`, which holds every page
until a condition is met and redirects to the one page where it can be
resolved:

```php
->middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsSubscribed::class,   // redirects to billing, not 403
])
```

Model it on `PandaPanel\Http\Middleware\RequireTwoFactor`: exempt the page you
redirect to, or you build a loop.

---

## 7. Queued work

Exports, imports, and any action with `->databaseTransaction()` may run in a
queue. `QueueTenancyBootstrapper` re-enters the tenant when the job runs — but
only for jobs dispatched *inside* a tenant context.

The framework's own jobs (`RunPanelExport`, `RunPanelImport`) carry a panel id
and re-resolve the panel in `handle()`. They do **not** carry a tenant id,
because they were written before tenancy. With the queue bootstrapper
installed this still works, because the bootstrapper restores the tenant
around the job. Without it, a queued export would run against the central
database and quietly produce the wrong file.

If you write your own jobs, dispatch them from inside the tenant context and
let the bootstrapper do the rest.

---

## 8. Migrations and seeding

```bash
php artisan tenants:migrate
php artisan tenants:seed
```

Your existing migrations need splitting. Anything a tenant owns — `users`,
`notifications`, your domain tables — moves to `database/migrations/tenant`.
Anything central — `tenants`, `domains`, plans — stays in
`database/migrations`.

Two of this framework's own migrations are tenant migrations:

- `create_notifications_table` — a notification belongs to a user, and users
  are per tenant.
- `add_email_two_factor_to_users_table` — a column on `users`.

The `jobs` and `cache` tables are a judgement call. Central is simpler and
means one worker; per-tenant means a tenant cannot fill another's queue.

---

## 9. Testing

The panel test suite builds panels inline and registers routes at runtime
(see `tests/Fixtures/Panel/*Panel.php`). That pattern works unchanged for
tenant panels — but a test that enters a tenant needs the tenant's database to
exist, which makes it slow.

Prefer the split this suite already uses: test the *panel* behaviour against a
fixture panel with no tenancy middleware, and test *tenancy* behaviour —
identification, connection switching, cache separation — in its own file with
a real tenant. Very few tests need both.

---

## 10. The order to do this in

1. Decide §1 — all three questions, written down.
2. Install and configure (§2). Get `tenants:migrate` working before touching
   any panel.
3. Set `SESSION_DOMAIN` and confirm signing in on a subdomain works, with no
   panels involved.
4. Add the tenancy middleware to one panel (§3) and check `route:list`.
5. Move the tenant migrations (§8).
6. Then the panel-side work: switcher, menu, pages (§5), and billing (§6).

Steps 3 and 4 are where a mistake is cheap and invisible later. A panel that
resolves before the tenant does will read the central database and look like
it works, right up until two tenants have a row with the same id.
