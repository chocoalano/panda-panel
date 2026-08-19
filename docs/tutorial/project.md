# 2 · Create the project

**Goal:** a Laravel application that runs, with a frontend the panel can build against.

The panel's published components import nineteen modules they do not ship, and a Laravel Vue
starter kit application already has every one of them. That is the whole of the "starter kit
assumption" — install into one and the frontend works; install into anything else and those
nineteen files are the work.

## Do this

```bash
laravel new acme
```

Choose the **Vue** starter kit when the installer asks. Then:

```bash
cd acme
php artisan migrate
npm install
npm run dev
```

Open `http://localhost:8000` in one terminal and leave `npm run dev` running in another. You should
see the starter kit's welcome page, and be able to register an account.

::: details No `laravel` command?
```bash
composer global require laravel/installer
```
Then make sure `~/.composer/vendor/bin` (or `~/.config/composer/vendor/bin`) is on your `PATH`.
:::

## What the starter kit gives you

The panel does not read or edit any of these. It imports them, and that is the whole relationship.

| | What the panel uses it for |
| --- | --- |
| `resources/views/app.blade.php` | The Inertia root view. Without it, every panel URL is a 500 |
| `app/Http/Middleware/HandleInertiaRequests.php` | Inertia's middleware. The panel adds its own props separately |
| `resources/js/app.ts` | The Inertia entry. The panel needs it to *not* overwrite the layout each page declares |
| `vite.config.ts` | The build, and where the Wayfinder plugin runs |
| `resources/css/app.css` | The Tailwind 4 theme the panel's utilities resolve against |
| `@/components/*`, `@/composables/useTwoFactorAuth`, `@/types` | Account UI and shared types the panel's settings pages import |
| `@/routes/*`, `@/actions/*` | Wayfinder output, generated from your own route table |
| `App\Providers\FortifyServiceProvider` | Your application's auth screens, which stay where they are |

## Generate the Wayfinder modules

Nine of the nineteen are generated from your own routes and controllers:

```bash
php artisan wayfinder:generate
```

The starter kit's Vite config runs the plugin during a build, so `npm run build` regenerates them
too. Running it once now means step 3's installer has nothing to report about them.

## The one edit that is easy to get wrong

Every panel page declares its own layout:

```ts
defineOptions({ layout: PanelLayout });
```

If `resources/js/app.ts` assigns a layout unconditionally in the Inertia resolver, it overwrites
that choice *after* the page already made it. Open the file and look at the resolver:

```ts
createInertiaApp({
  resolve: (name) => {
    const page = resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue'));

    page.default.layout = AppLayout;      // [!code --] every panel screen renders in your shell
    page.default.layout ??= AppLayout;    // [!code ++] a page that named its own layout keeps it

    return page;
  },
});
```

`??=` and `||=` are both correct; a bare `=` is not.

::: warning This failure is silent
The wrong version produces HTTP 200, your sidebar, no panel navigation, and nothing in the log.
`panel:install` reads the file and reports the offending line by number — it is checked precisely
because nothing else would ever tell you.
:::

## If you are not using a starter kit

Nothing about the server half cares. What you have to supply is the nineteen frontend modules, in
this order:

1. `php artisan inertia:middleware`, and a root view at `resources/views/app.blade.php`.
2. A `vite.config.ts` with the Vue and Tailwind 4 plugins.
3. `npm install` the packages `panel:install` lists in step 3.
4. Install and run Wayfinder for `@/routes/*` and `@/actions/*`.
5. Write the seven components, the composable and the two type modules — or copy them from a
   starter kit application.

The package repository carries a minimal, correctly-typed stand-in for each under `frontend/host/`,
readable as a specification of what every module must export. Details in
[Host modules](/frontend/host-modules).

## Check it worked

```bash
php artisan route:list --name=dashboard
ls resources/js/routes resources/js/actions
```

The route should exist, and both directories should be populated. Registering an account through
the starter kit's own screens and landing on `/dashboard` is the strongest check available at this
point — it proves Inertia, Vite and the auth stack all work before the panel is anywhere near
them.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| `npm run dev` fails on Tailwind | Node below 20.19 | Upgrade Node, delete `node_modules`, `npm install` again |
| `resources/js/routes` is empty | Wayfinder has not run | `php artisan wayfinder:generate` |
| A blank page and a console error about `app.blade.php` | No Inertia root view | `php artisan inertia:middleware`, and create the view |
| `/dashboard` 500s | Migrations not run | `php artisan migrate` |

## Next

The application runs. Now give it a panel.

**→ [3 · Install Panda Panel](install)**

## See also

- [Laravel Vue starter kit setup](/getting-started/vue-starter-kit) — the same ground, in full
- [Frontend requirements](/getting-started/frontend-requirements) — the nineteen modules, listed
- [Wayfinder](/frontend/wayfinder)
