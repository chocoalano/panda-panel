# Upgrade guide

Every breaking change, what it breaks, and the smallest edit that fixes it.
Nothing here is a "consider" — if a section applies to your project, doing
nothing leaves something broken.

Version ranges for PHP, Laravel, Inertia, Vue and Tailwind are in
[compatibility.md](compatibility.md).

---

## Unreleased

Seven changes need an edit. Two of them are silent — the code keeps running and
does the wrong thing — and they are first.

### 1. Uploads are authorized by the form they belong to (silent)

**What changed.** The upload endpoint used to accept
`canCreate() || canViewAny()`. Reading a resource is now never enough:

| Context in the URL | Ability asked |
| --- | --- |
| `page=create` | `create` |
| `page=edit` + `record` | `update`, on that record |
| `relation` + `operation` | the relation manager's own, per operation |
| `action` + `scope` | the action's own `isAuthorizedFor()` |

**What breaks.** Anyone who could previously upload with only `viewAny` now
gets a 403. If that was load-bearing — a read-only role that attaches files —
the fix is to give that role `create` on the model, which is what it was
actually doing.

**Also:** `page` is now an allowlist. It used to mean "edit, or else create",
so an unrecognised value silently became the create form. A request with a
`page` that is not `create` or `edit` is now a 422.

**Also:** the resource is read from the **query string only**, never from the
request body. A form whose values happened to include a `resource` key could
previously point the upload elsewhere.

**If you published the frontend before this change**, re-publish
`resources/js/panel/forms/uploadEndpoint.ts` — the old copy sends `resource`
in the body, where the server no longer looks:

```bash
php artisan vendor:publish --tag=panda-panel-assets --force
```

Check for local edits first. If you have any, the change is small enough to
apply by hand: delete the block that copies `resource` out of the URL into the
`FormData`.

### 2. `down()` no longer drops a `notifications` table it did not create (silent)

**What changed.** `up()` has always skipped the table when the application
already had one. `down()` did not: it ran `dropIfExists()`, so rolling this
package back deleted an application's notifications.

It now drops the table only when it can establish that this package made it —
no other ran migration claims the name, and the columns are exactly the ones
`up()` creates. When the answer is not a clear yes, the table is left standing.

**What breaks.** Nothing, unless you were relying on the rollback to clean up.
An empty `notifications` table may now survive a `migrate:rollback`; drop it by
hand if you want it gone.

### 3. `PanelPlugin::publishes()` is on the contract

**What changed.** `publishes()` moved from the `Plugin` base class onto the
`PanelPlugin` interface, so `panel:publish` can ask any plugin what it
publishes rather than only the ones that happened to inherit.

**What breaks.** A plugin that implements `PanelPlugin` **directly** — which is
what a plugin shipped as its own package should do — no longer satisfies the
interface. Add:

```php
/**
 * @return array<string, string>
 */
public function publishes(): array
{
    return [];
}
```

A plugin that extends `PandaPanel\Plugins\Plugin` needs no change; the base
class still supplies it.

### 4. The guest redirect is registered for you

**What changed.** Sending a guest who opens a panel URL to *that panel's* login
used to be a manual step in `bootstrap/app.php`. The service provider now does
it, using the same `afterResolving(Kernel::class)` trick that made the manual
step necessary in the first place.

**What breaks.** Nothing, if your `bootstrap/app.php` has the line the old
README told you to add — it is now redundant but harmless, and you can delete
it:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(PanelLoginRedirect::for(...));   // ← delete
})
```

**If you set your own custom guest redirect**, the package will now overwrite
it. Turn the automatic registration off and call into `PanelLoginRedirect`
from your own rule:

```php
// config/panda-panel.php
'register_guest_redirect' => false,
```

```php
// bootstrap/app.php
$middleware->redirectGuestsTo(
    fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
);
```

`PanelLoginRedirect::for()` already falls back to `route('login')` for a
request that is not a panel request, so leaving it on is correct for almost
every application.

### 5. The testing helpers moved into the package

**What changed.** `panelTable()`, `panelForm()`, `panelRecordActions()` and the
rest used to live in this repository's own `tests/` and were not shipped. They
are now `PandaPanel\Testing\*`, autoloaded through composer, and available in
your application's suite.

**What breaks.** Nothing in an application — they were not reachable before.
If you had copied them into your own `tests/Support/`, delete your copy: the
shipped functions are guarded by `function_exists`, so a leftover copy wins
silently and will drift.

```php
panelTable(UserResource::class)->assertCanSeeRecord($user)->assertCount(2);
panelForm(UserResource::class)->assertFieldIsRequired('name');
panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
```

### 6. `Password::toPasswordRulesString()` is no longer called directly

**What changed.** Three places built the browser `passwordrules` attribute with
`Password::defaults()->toPasswordRulesString()`. That method is Laravel 13
only, so on Laravel 12 the login, register, reset-password and security
settings pages were a 500 — under a constraint that claimed to support both.

They now go through `PandaPanel\Support\PasswordRules::attribute()`, which
uses the framework's method where it exists and reproduces its exact output
from `appliedRules()` where it does not.

**What breaks.** Nothing. If you called `toPasswordRulesString()` in your own
panel pages and you support Laravel 12, use `PasswordRules::attribute()`
instead — the output is identical.

### 7. `panel:install` does more, and asks

**What changed.** It now registers the scaffolded panel in
`config/panda-panel.php` itself, checks the frontend, and offers to create a
user. Two of those change what a scripted install does:

- **Provider registration** edits the published config file. If your config has
  been restructured — a `panels` key built from a variable, for instance — it is
  left alone and reported, and you add the line yourself.
- **The user prompt** only appears in an interactive terminal. Pass
  `--no-user` to be explicit in a script, and `panel:user --name= --email=
  --password=` to create one non-interactively.

---

## Upgrading the published frontend

The one thing publishing the frontend has always cost: a package update cannot
improve a file the application owns. There is now a way through it.

```bash
composer update chocoalano/panel
php artisan panel:assets            # report
php artisan panel:assets --update   # write the safe ones
npm run build
```

`--update` writes exactly two categories — files you never had, and files you
have never edited. Anything you changed is left alone. Anything changed on
*both* sides is reported by path and never written, because resolving that by
guessing is how an upgrade eats somebody's work; diff those against
`vendor/chocoalano/panel`, merge by hand, then `--force`.

**Coming from a version before this existed**, there is no
`.panel-assets.json` yet, so the first run has no record to compare against.
It handles that: a file already identical to the package's reads as current,
and only genuinely different ones are reported. Run `--update` once to write
the manifest, then **commit it** — it is the record of what this application
published, in the same way `composer.lock` is a record of what it installed.

## Widened: PHP 8.2

The floor was `^8.3` and nothing required it — no typed class constants, no
`#[\Override]`, no 8.3 standard library. It is now `^8.2`, satisfied through
Laravel 12, which is the newest Laravel that runs on PHP 8.2. CI runs that
combination on every push.

Laravel 11 is still not supported, and cannot be: every 11.x release is
flagged by unpatched security advisories and composer refuses to resolve
against it. See [compatibility.md](compatibility.md#why-not-laravel-11).

## New, and safe to ignore

Nothing below breaks an existing project. They are here because the upgrade is
where you will look for them.

### Tenancy

A panel can now declare a tenant model and a resolver, and the framework will
identify, authorize, bind, and scope:

```php
$panel->tenant(Team::class, fn (Request $request) => Team::query()
    ->where('slug', $request->route('team'))
    ->first());
```

```php
final class InvoiceResource extends Resource
{
    protected static ?string $tenantRelationship = 'team';
}
```

Your user model implements `HasPanelTenants`. A resource that names no
relationship is not scoped, which is the right answer for a global table and
for a database-per-tenant arrangement where the connection is already the
boundary.

This is the framework's half only — it does not create databases, switch
connections, or read subdomains. [panel-tenancy.md](panel-tenancy.md) is the
guide for putting it together with `stancl/tenancy`.

**If you already hand-rolled tenancy** by overriding `Resource::query()`, it
keeps working exactly as it did. `applyTenantScope()` is a no-op for a panel
that never called `tenant()`.

### A frontend toolchain

The package now has a `package.json`, a Vite config, a tsconfig, ESLint and
Prettier, and CI runs all four against Node 20, 22 and 24. None of it ships:
every file is `export-ignore`d, so `composer require` pulls none of it.

What it changes for you: the npm dependency list an application needs is now
read from that `package.json` rather than restated in the README, so
`panel:install` and the docs cannot disagree about it.

### Compatibility matrix

[compatibility.md](compatibility.md) — what is tested, what is tolerated, and
what will not work.
