# Common install problems

Symptoms an install produces, what each one actually is, and the smallest fix. Almost every entry
here is a case where the failure is silent or names the wrong thing — a 404 with no error, a build
error about a module specifier, a screen that renders at HTTP 200 inside somebody else's shell.

## Start here

```bash
php artisan panel:install --no-panel --no-user --no-interaction   # re-runs every check, changes nothing
php artisan route:list --name=panel.                             # did the routes register at all
php artisan panel:clear                                          # is a stale manifest hiding something
tail -f storage/logs/laravel.log                                 # the panel logs several of these
```

The first command is the diagnostic: with `--no-panel --no-user` it publishes what is missing, then
re-checks Inertia, Vite, the npm dependencies, the layout assignment and the host modules, and
prints what it found.

| Symptom | Section |
| --- | --- |
| The panel URL 404s | [Nothing answers at the panel's path](#nothing-answers-at-the-panels-path) |
| 403 after signing in | [403 from the panel itself](#403-from-the-panel-itself) |
| The panel renders inside my application's shell | [The layout is overwritten](#the-layout-is-overwritten) |
| `Failed to resolve import` at build time | [The build cannot resolve a module](#the-build-cannot-resolve-a-module) |
| 500 on the first panel URL | [No Inertia root view or middleware](#no-inertia-root-view-or-middleware) |
| Signing in still lands on the starter kit dashboard | [The home redirect](#the-home-redirect-in-both-directions) |
| A guest is sent to the wrong login | [The guest redirect](#the-guest-redirect) |
| A resource is missing from the sidebar | [A resource is not in the navigation](#a-resource-is-not-in-the-navigation) |
| A resource I just added is invisible | [A stale panel manifest](#a-stale-panel-manifest) |
| An icon renders nothing | [An icon draws nothing](#an-icon-draws-nothing) |
| A widget or column draws a blank | [A component is not in the registry](#a-component-is-not-in-the-registry) |
| `vendor:publish` reported nothing | [Publishing did nothing](#publishing-did-nothing) |
| `panel:assets` says CONFLICT | [A conflicted asset](#a-conflicted-asset) |
| `panel:user` fails | [panel:user refuses](#paneluser-refuses) |
| `Echo has not been configured` | [Broadcasting without a broadcaster](#broadcasting-without-a-broadcaster) |
| Composer will not install the package | [Composer refuses](#composer-refuses) |

---

## Nothing answers at the panel's path

**Symptom.** `/admin` is a 404. The provider file exists. `make:panel` reported success.

**Cause.** Panels are listed, not discovered. A provider that is not in `config/panda-panel.php`
is not registered, has no route group, and therefore has no URL. This is the single most common
"it did not work" after an install.

```php
// config/panda-panel.php
'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
],
```

**Confirm it.**

```bash
php artisan route:list --name=panel.
```

An empty result means no panel registered. Three other causes produce the same empty result:

| | Check |
| --- | --- |
| The config was never published, so the installer had nothing to edit | `ls config/panda-panel.php` |
| The `panels` array was restructured, so `PanelRegistrar` left it alone and reported it | Look for a `panels` key built from a variable |
| `register_routes` is `false` | `config('panda-panel.register_routes')` |

A provider class named in config that no longer exists is **skipped rather than fatal** — a
boot-time fatal would take down every route including the one that would have shown you the error.
So a typo in the class name also looks exactly like this. `php artisan panel:cache` prints what it
actually found.

---

## 403 from the panel itself

**Symptom.** Signed in, and every panel URL answers 403.

**Cause.** A panel asks two independent questions and both must agree:

```php
// the panel's own rule, in its provider
->canAccess(static fn (?Authenticatable $user): bool => $user?->is_admin === true)

// a rule about the account, on the user model
public function canAccessPanel(PandaPanel\Core\Panel $panel): bool;
```

A signed-in user who is refused gets a 403, never a redirect: hiding navigation is not an access
control.

**Confirm it.**

```bash
php artisan tinker
```

```php
use PandaPanel\Core\PanelManager;

$user = App\Models\User::query()->where('email', 'ada@example.com')->first();

app(PanelManager::class)->get('admin')->isAccessibleTo($user);   // false
```

`panel:user --panel=admin` reports the same thing at creation time, and names which of the two
rules said no.

A privilege flag is usually not mass-assignable — deliberately, since a fillable `is_admin` is a
privilege anyone can grant themselves through a form post — so granting it is an explicit write:

```php
$user->forceFill(['is_admin' => true])->save();
```

**Not this.** A 403 on *one resource* rather than the whole panel is a policy, not panel access.
See [A resource is not in the navigation](#a-resource-is-not-in-the-navigation).

---

## The layout is overwritten

**Symptom.** Panel pages render at HTTP 200, inside your application's sidebar, with the panel
navigation nowhere. Nothing is logged.

**Cause.** Every panel page declares its own layout with `defineOptions({ layout: PanelLayout })`.
An unconditional assignment in your Inertia resolver replaces it *after* the page asked:

```ts
page.default.layout = AppLayout;      // wrong
page.default.layout ??= AppLayout;    // right
page.default.layout ||= AppLayout;    // also right
```

**Confirm it.**

```php
PandaPanel\Support\Installer\FrontendRequirements::layoutOverrides();
// [['file' => 'resources/js/app.ts', 'line' => 12, 'code' => 'page.default.layout = AppLayout;']]
```

`panel:install` reports the file, the line and the replacement. This is checked rather than
documented because it is the one thing about the seam the package cannot fix from the inside.

---

## The build cannot resolve a module

**Symptom.** `npm run build` fails with `Failed to resolve import "@/routes/login"`, or a similar
message about `@/components/UserMenuContent`, `reka-ui`, `vue-sonner`.

**Cause.** Two different problems with one error message.

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingNpmPackages();   // a dependency your package.json does not declare
FrontendRequirements::missingHostModules();   // a module your application owns and does not have
```

**Fix.**

```bash
npm install @inertiajs/vue3@^3.0.0 reka-ui@^2.0.0    # whatever panel:install listed
php artisan wayfinder:generate                        # for every @/routes/* and @/actions/*
npm run build
```

Which ones are missing tells you which problem it is: all of `@/routes/*` and `@/actions/*` means
Wayfinder has not run; a handful of components means this is not a starter kit application, and
those files have to be written — see [Frontend requirements](frontend-requirements.md).

---

## No Inertia root view or middleware

**Symptom.** The first panel URL is a 500, usually `View [app] not found` or an Inertia error.

**Cause.** Every panel screen is an Inertia response. Two files have to exist:

```php
FrontendRequirements::missingInertia();
// ['an Inertia root view at resources/views/app.blade.php',
//  "Inertia's middleware (php artisan inertia:middleware)"]
```

**Fix.**

```bash
php artisan inertia:middleware
```

and a root view at `resources/views/app.blade.php`. A Blade-only application cannot host a panel;
that is not a configuration you can turn on.

---

## The home redirect, in both directions

**Symptom A.** After signing in you land on the starter kit's empty `/dashboard` rather than in the
panel.

**Cause.** `home_redirect.enabled` is `false`, or the path is not in the list, or the signed-in
user is not accepted by any panel (in which case there is nowhere to send them).

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

**Symptom B.** Your `/dashboard` is a real screen and it is now unreachable while signed in.

**Fix.** Turn it off. Your route, its name and `pages/Dashboard.vue` were never touched — the
request was simply answered earlier by a `web` middleware.

```php
'home_redirect' => ['enabled' => false],
```

The paths are `Request::is()` patterns, so `'reports/*'` hands over a section. A path a panel is
itself mounted on is ignored, which is what stops a panel at `/dashboard` redirecting to itself
forever. A guest, a non-GET request and a request that wants JSON are all left alone.

---

## The guest redirect

**Symptom.** A guest opening a panel URL lands on the application's login rather than the panel's,
or a custom `redirectGuestsTo()` you wrote stopped taking effect.

**Cause.** The service provider registers `PandaPanel\Support\PanelLoginRedirect` unless told not
to. It is a strict superset of Laravel's default — a request that is not a panel request, or a
panel with no login of its own, still gets `route('login')` — so the only thing it overrides is an
application that set its own.

```php
// config/panda-panel.php
'register_guest_redirect' => false,
```

```php
// bootstrap/app.php
use PandaPanel\Support\PanelLoginRedirect;

$middleware->redirectGuestsTo(
    fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
);
```

A panel only has a login of its own if it called `->login()`. Without it, `->auth()` still protects
the panel and guests go to the application's login.

---

## A resource is not in the navigation

**Symptom.** A resource exists, discovery found it, and it is not in the sidebar. Its URL 403s.

**Cause, most often.** The model has no policy. `Gate::allows()` denies when no policy exists —
correct, and indistinguishable from a policy that considered the question and said no. A freshly
generated resource therefore 403s until you write one. That is the intended default: a panel that
showed every record because nobody had written a rule yet would be worse.

**Confirm it.** In development the panel logs it once per model:

```text
[panel] ProductResource is not in the navigation because Product has no policy, so viewAny()
is denied by default. Create one with `php artisan make:policy ProductPolicy --model=Product` …
```

**Fix.**

```bash
php artisan make:policy ProductPolicy --model=Product
```

Or make the whole class of mistake loud instead of silent:

```php
$panel->strictAuthorization();
```

Under that, a model with no policy — or a policy with no method for the ability being asked —
raises `PandaPanel\Exceptions\PanelAuthorizationException` rather than reading as a working deny.

**Other causes with the same symptom:** the resource is discovered by a different panel, its
`canViewAny()` genuinely says no, or the manifest is stale.

---

## A stale panel manifest

**Symptom.** A resource, page or widget you just added is invisible. No route, no navigation entry,
no error.

**Cause.** `panel:cache` wrote a manifest, and with one present discovery does not run at all —
which is the point of it, and the trap.

**Confirm it.** In development the panel logs:

```text
[panel] The cached panel manifest is out of date: the classes under the discovery paths have
changed since `php artisan panel:cache` last ran. …
```

The check compares a fingerprint of the discovery paths (file count and newest mtime), runs only
when a manifest exists, and never in production.

**Fix.**

```bash
php artisan panel:clear      # development
php artisan panel:cache      # deploy time, after the code is in place
```

`panel:cache` and `panel:clear` are registered as `optimize` hooks, so `php artisan optimize` and
`optimize:clear` include them.

---

## An icon draws nothing

**Symptom.** `->icon('shield')` renders empty space.

**Cause.** The icon registry is a build-time allowlist: Lucide ships thousands of icons and only
the ones your panels declare belong in the bundle. A name that is not in
`resources/js/panel/icons/registry.ts` renders nothing.

**Fix.**

```bash
php artisan panel:icons          # rewrite the registry from the icons your panels declare
php artisan panel:icons --check  # fail instead of writing, for CI
npm run build
```

The command scans `app/` for every shape an icon name is declared in, checks each against the
icons Lucide actually ships, and fails by name on one that does not exist. In development the
frontend also warns once per unknown name.

---

## A component is not in the registry

**Symptom.** A custom widget draws the fallback; a custom column cell is blank.

**Cause.** Component names resolve through `import.meta.glob` over `resources/js/pages/Panels/**`
— a build-time allowlist. There are three reasons a name misses, and they are indistinguishable
from the screen: a typo, a file outside the globbed directory, or a build that was not re-run.

**Fix.** Put the component under `resources/js/pages/Panels/{Panel}/…` at exactly the name the PHP
side declares, then rebuild. In development the registries warn once per unknown name, naming the
directory the component has to live in.

---

## Publishing did nothing

**Symptom.** `vendor:publish --tag=panda-panel-assets` reports nothing published, or an updated
component does not appear after `composer update`.

**Cause.** `vendor:publish` skips files that already exist. Its only other setting, `--force`,
overwrites everything including the files you deliberately changed. Neither can tell the
difference, because "differs from the package's copy" is equally true of a stale file and an edited
one.

**Fix.** Use the command that records the third value:

```bash
php artisan panel:assets            # what is behind, what you changed, what conflicts
php artisan panel:assets --update   # writes only files you never had, or never edited
npm run build
```

If `panel:assets` warns that there is no `.panel-assets.json`, run `--update` once to write one,
then commit it.

---

## A conflicted asset

**Symptom.** `panel:assets` reports `CONFLICT` and writes nothing for those files.

**Cause.** The file changed here *and* upstream. Neither copy is safe to throw away, and resolving
that by guessing is how an upgrade eats somebody's work.

**Fix.**

```bash
diff resources/js/panel/tables/DataTable.vue \
     vendor/chocoalano/panel/resources/js/panel/tables/DataTable.vue

# merge by hand, then:
php artisan panel:assets --force
```

`--force` extends to files you edited and to nothing else: a file you deleted on purpose stays
deleted, and one the package no longer ships is not resurrected. A conflict is not an error —
`panel:assets` still exits `0`, because breaking a deploy over a file somebody edited on purpose
would be wrong.

---

## `panel:user` refuses

| Message | Cause | Fix |
| --- | --- | --- |
| `No auth guard named [x].` | `auth.guards.x.provider` is not a string | Check `config/auth.php`, or drop `--guard=` |
| `The [users] user provider names no model this command can create.` | The provider has no `model` key, or names a missing class | Point `--guard=` at a guard backed by an Eloquent provider |
| `The name field is required.` in a script | Non-interactive run with options missing | Pass `--name=`, `--email=` and `--password=`; prompts are skipped when input is not interactive |
| `The user could not be created: …` | The save threw — usually a unique constraint on `email` | The command creates; it does not update |
| No access line printed at all | `--panel=` names an id that does not exist, or no panel is registered | Check ids with `route:list --name=panel.` |

---

## Broadcasting without a broadcaster

**Symptom.** `Echo has not been configured`, thrown from inside `onMounted`, followed by a cascade
of `Slot "default" invoked outside of the render function` warnings — none of which name a
broadcaster.

**Cause.** A panel subscribes to its own notifications by default. In an application with no
broadcast connection, the client called `echo()` on a channel nothing could serve.

**Current behaviour.** The panel withholds the channel unless a connection is genuinely usable:
`broadcasting.default` names a connection, that connection has a driver, and the driver is not
`null` or `log` — both of which are real drivers that no browser can attach to.

**Fix, whichever applies.**

```php
$panel->broadcasting(false);        // this panel does not want live notifications
```

```dotenv
BROADCAST_CONNECTION=reverb
```

```bash
php artisan reverb:start            # development needs the websocket server running
```

Without the server the browser retries in the background and the panel works exactly as before,
minus the live notifications.

---

## Composer refuses

| Message | Cause |
| --- | --- |
| `requires php ^8.2` | PHP below 8.2. |
| `requires ext-zip` | The zip extension is not installed. It is a hard requirement — XLSX files are zip archives. |
| `laravel/framework[v11.x] … found … but it does not match your constraint` | Laravel 11 is not supported and cannot be: every 11.x release is flagged by unpatched advisories and composer will not resolve against it. |
| Cannot resolve PHP 8.2 with Laravel 13 | Laravel 13 requires PHP 8.3. PHP 8.2 users get Laravel 12. |

See [Compatibility](compatibility.md) for the full matrix.

---

## Two other boot-time exceptions

Both are raised deliberately at boot, where they are cheap to find, rather than left to be
discovered as a page that renders the wrong thing.

| Exception message contains | Meaning |
| --- | --- |
| `already registered` | Two panels share an id. Ids come from the provider class name unless `->id()` says otherwise. |
| `already used by the panel [first]` | Two panels share a path on the same domain. Give one a different `path()`, or a `domain()`. |
| a colliding route path | Two resources in one panel claim the same path shape — a `ManageRelatedRecords` page at `projects/{record}/tasks` and a nested resource at `projects/{parentRecord}/tasks` are the same path as far as matching is concerned. Laravel would silently make one of them unreachable. |

## See also

- [Running panel:install](installer.md) — the checks these symptoms come from
- [Frontend requirements](frontend-requirements.md) — npm packages, host modules, the layout rule
- [Opening your first panel](first-panel.md), [Creating the first user](first-user.md)
- [Troubleshooting: panel routes 404](../troubleshooting/panel-routes-404.md),
  [403](../troubleshooting/authorization-403.md),
  [host modules](../troubleshooting/host-modules.md),
  [Vite](../troubleshooting/vite.md), [Tailwind](../troubleshooting/tailwind.md),
  [icons](../troubleshooting/icons.md),
  [asset conflicts](../troubleshooting/asset-conflicts.md),
  [broadcasting](../troubleshooting/broadcasting.md),
  [login redirects](../troubleshooting/login-redirects.md),
  [Inertia root view](../troubleshooting/inertia-root-view.md)
- [Concepts: authorization](../concepts/authorization.md), [caching](../concepts/caching.md)
