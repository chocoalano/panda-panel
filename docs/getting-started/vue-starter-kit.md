# Laravel Vue starter kit setup

The panel's published components import nineteen modules they do not ship, and a Laravel Vue
starter kit application already has every one of them. That is the whole of the "starter kit
assumption": install into one and the frontend works; install into anything else and those
nineteen files are the work. This page is the shortest path from a new starter kit application to
a working panel, and the list of edits that path involves.

## From nothing to a panel

```bash
laravel new acme            # choose the Vue starter kit when prompted
cd acme
php artisan migrate

composer require chocoalano/panel
php artisan panel:install

npm install
npm run build
php artisan serve
```

`panel:install` should finish with `Done. Nothing is left to do by hand.` Sign in, and you land in
the panel at `/admin` rather than on the starter kit's placeholder dashboard.

## What the starter kit provides

The panel does not read or edit any of these. It imports them, and that is the whole relationship.

| | What the panel uses it for |
| --- | --- |
| `resources/views/app.blade.php` | The Inertia root view. Without it, every panel URL is a 500. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Inertia's middleware. The panel adds its own props separately, in `SharePanelData`. |
| `resources/js/app.ts` | The Inertia entry. The panel needs it to *not* overwrite the layout each page declares. |
| `vite.config.ts` | The build. Also where the Wayfinder plugin runs. |
| `resources/css/app.css` | The Tailwind 4 theme the panel's utilities resolve against. |
| `@/components/*`, `@/composables/useTwoFactorAuth`, `@/types`, `@/types/ui` | Account UI and shared types the panel's settings pages import. |
| `@/routes/*`, `@/actions/*` | Wayfinder output, generated from your route table. |
| `App\Providers\FortifyServiceProvider` | The application's own auth screens, which stay where they are. |

The full specifier list, and what happens when one is missing, is in
[Frontend requirements](frontend-requirements.md).

## Generate the Wayfinder modules

Nine of the nineteen are generated from your own routes and controllers — six under `@/routes`
and three under `@/actions`:

```bash
php artisan wayfinder:generate
```

The starter kit's Vite config runs the plugin during a build, so `npm run build` regenerates them
too. `panel:install` names these separately from the components, because "all of `@/routes/*` and
`@/actions/*` are missing" means Wayfinder has not run, while "a handful of components are
missing" means this is not a starter kit application.

## The one edit that is easy to get wrong

Every panel page declares its own layout:

```ts
defineOptions({ layout: PanelLayout });
```

If your `resources/js/app.ts` assigns a layout unconditionally in the Inertia resolver, it
overwrites that choice after the page has already made it:

```ts
createInertiaApp({
    resolve: (name) => {
        const page = resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue'));

        page.default.layout = AppLayout;      // wrong: every panel screen renders in your shell
        page.default.layout ??= AppLayout;    // right: a page that named its own layout keeps it

        return page;
    },
});
```

The wrong version produces HTTP 200, your sidebar, no panel navigation, and nothing in the log.
`panel:install` reads the file and reports the offending line by number — it is checked rather
than documented precisely because nothing else would ever tell you.

## Optional: emit a panel's own Vite entrypoints

Only needed if a panel declares assets with `Panel::assets()`. The root view spreads them:

```blade
@vite([
    'resources/css/app.css',
    'resources/js/app.ts',
    "resources/js/pages/{$page['component']}.vue",
    ...(panel()?->getAssets() ?? []),
])
```

`panel()` returns the panel for the current request or `null` outside one, so the spread is empty
on every starter kit page and on a panel that declared nothing.

## What the panel takes over from the starter kit

Two addresses change behaviour. Both keep their routes, their names and their page components.

### `/dashboard`

A signed-in user who lands there is redirected to the first panel they can enter. It is a `web`
middleware — `PandaPanel\Http\Middleware\RedirectPanelHome` — rather than a competing route,
because `/dashboard` belongs to your route file and a package racing for the same URI would be
relying on registration order to win.

```php
// config/panda-panel.php
'home_redirect' => [
    'enabled' => true,
    'paths' => ['dashboard'],
],
```

Turn `enabled` off to keep your screen. The paths are `Request::is()` patterns, so `'reports/*'`
hands over a whole section; a path a panel is itself mounted on is ignored, which is what stops a
panel at `/dashboard` redirecting to itself forever. A guest, a non-GET request and a request that
wants JSON are all left alone.

### Guest visits to a panel URL

A guest who opens a panel URL is sent to *that panel's* login when the panel has one, and to
`route('login')` otherwise — which is what Laravel does by default, so this adds a case rather
than replacing one. The service provider registers it:

```php
// config/panda-panel.php
'register_guest_redirect' => true,
```

Set it to `false` if your `bootstrap/app.php` calls `redirectGuestsTo()` itself, and call into the
package's rule from your own so panel logins keep working:

```php
use PandaPanel\Support\PanelLoginRedirect;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(
        fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
    );
})
```

### `/settings/*`

The package does nothing here. The example application redirects its settings addresses into the
panel's own settings pages so bookmarks and Wayfinder links still resolve, while keeping the
write route where it was:

```php
Route::middleware('auth')->group(function (): void {
    Route::get('settings/profile', [SettingsRedirectController::class, 'profile'])->name('profile.edit');

    // The screen moved into the panel; the write did not.
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});
```

Copy that only if you want it. See [`examples/routes/web.php`](../../examples/routes/web.php) and
`examples/app/Http/Controllers/Settings/SettingsRedirectController.php`.

## Fortify, and two sets of auth screens

The starter kit wires Fortify's views to its own Inertia pages. Leave that as it is:

```php
use Laravel\Fortify\Fortify;

Fortify::loginView(static fn () => Inertia::render('auth/Login'));
Fortify::createUsersUsing(CreateNewUser::class);
```

A panel that turns its own front door on renders *its* versions of those screens, at its own path,
carrying its brand:

```php
$panel->auth()->login()->passwordReset()->emailVerification();
```

Both post to the same Fortify endpoints. Duplicating the login POST per panel would mean
duplicating rate limiting, two-factor, passkeys and session handling — four things that must never
disagree between two doors into the same application.

`auth()` is separate from `login()`: `auth(verified: true)` appends the `auth` and `verified`
middleware to the panel's stack, and `login()` is what gives the panel guest routes of its own.

## If you are not on a starter kit

Nothing about the server half cares. What you have to supply is the nineteen modules, and the
package's own repository contains a minimal, correctly-typed stand-in for each under
[`frontend/host/`](../../frontend/host/README.md) — used only for type-checking and building this
package on its own, `export-ignore`d so `composer require` never brings them, and readable as a
specification of what each module must export.

The order that works:

1. `php artisan inertia:middleware`, and a root view at `resources/views/app.blade.php`.
2. A `vite.config.ts` with the Vue and Tailwind 4 plugins.
3. `npm install` the packages `panel:install` lists.
4. Install and run Wayfinder for `@/routes/*` and `@/actions/*`.
5. Write the seven components, the composable and the two type modules, or copy them from a starter
   kit application.
6. `php artisan panel:install` again — it re-checks everything and should now report nothing.

## Notes

- **The starter kit's `/dashboard` page component is never deleted.** Turning `home_redirect` off
  gives the screen back exactly as it was.
- **`panel:install` is safe to re-run.** Publishing skips existing files, registration is a no-op
  for a panel already listed, and the frontend checks only read.
- **A starter kit upgrade can reintroduce the layout assignment.** It is in a file you own, so
  `panel:assets` will not tell you about it — re-run `panel:install` (or the check directly) after
  taking a starter kit update.
- **The panel does not need a starter kit's `AppLayout`.** It ships `PanelLayout` and
  `PanelBlankLayout` and declares them per page. The starter kit shell is only ever used by the
  starter kit's own pages.

## See also

- [Frontend requirements](frontend-requirements.md) — the nineteen modules, in full
- [Installation](installation.md) and [Running panel:install](installer.md)
- [Opening your first panel](first-panel.md)
- [Frontend: host modules](../frontend/host-modules.md), [Wayfinder](../frontend/wayfinder.md)
- [Authentication: Fortify](../authentication/fortify.md), [login](../authentication/login.md)
- [Configuration: home redirect](../configuration/home-redirect.md),
  [guest redirect](../configuration/guest-redirect.md)
- [Troubleshooting: login redirects](../troubleshooting/login-redirects.md),
  [Inertia root view](../troubleshooting/inertia-root-view.md)
