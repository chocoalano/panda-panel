# Wayfinder routes

Nine of the modules the published panel components import are not shipped by this package and
never will be: [Wayfinder](https://github.com/laravel/wayfinder) generates them from *your*
route table. They are the ones the panel's auth and settings pages use to post to Fortify and to
your own settings controllers. Reach for this page when `npm run build` fails on `@/routes/...` or
`@/actions/...`, or when you need to know exactly which routes and controllers your application
has to have.

## The one command

```bash
php artisan wayfinder:generate --with-form
npm run build
```

`--with-form` matters: the panel binds `.form()` onto Inertia's `<Form>` on ten of its call
sites, and a Wayfinder run without form helpers produces modules that do not export it.

Ask the package what is still missing rather than guessing:

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingHostModules();
// ['@/routes/login', '@/routes/password', …]
```

## The nine modules

Every one of these is a *generated* module. Vendoring a copy inside the package would be shipping
a snapshot of somebody else's routes.

| Specifier | Exports the panel uses | Generated from | Method and URI |
| --- | --- | --- | --- |
| `@/routes` | `logout` | route `logout` | `POST /logout` |
| `@/routes/login` | `store` | route `login.store` | `POST /login` |
| `@/routes/register` | `store` | route `register.store` | `POST /register` |
| `@/routes/password` | `email`, `update` | routes `password.email`, `password.update` | `POST /forgot-password`, `POST /reset-password` |
| `@/routes/two-factor` | `enable`, `disable` | routes `two-factor.enable`, `two-factor.disable` | `POST` and `DELETE /user/two-factor-authentication` |
| `@/routes/verification` | `send` | route `verification.send` | `POST /email/verification-notification` |
| `@/actions/App/Http/Controllers/Settings/ProfileController` | `update`, `destroy` | `App\Http\Controllers\Settings\ProfileController` | `PATCH` and `DELETE /settings/profile` |
| `@/actions/App/Http/Controllers/Settings/SecurityController` | `update` | `App\Http\Controllers\Settings\SecurityController` | `PUT /settings/security` |
| `@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController` | `destroy` | `Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController` | `DELETE /passkeys` |

A route module is named after the route name's leading segment and exports the rest, so
`password.email` becomes `email` in `@/routes/password`. A controller module mirrors the
controller's fully-qualified class name under `@/actions/`. The methods and URIs in the last
column are what the package's own stand-ins record — see
[`frontend/host/`](../../frontend/host/README.md) — which is the specification of what each module
has to resolve to for the panel to behave.

Fortify registers the six route-based ones. The two `App\Http\Controllers\Settings` controllers
and the passkey controller come from a Laravel Vue starter kit.

## Which panel file imports what

Ten published files import a generated module. All of them are compiled by your build whether or
not the panel ever renders them, because the Inertia resolver globs `resources/js/pages/**`.

| File | Imports |
| --- | --- |
| `resources/js/pages/panel/auth/Login.vue` | `store` from `@/routes/login` |
| `resources/js/pages/panel/auth/Register.vue` | `store` from `@/routes/register` |
| `resources/js/pages/panel/auth/ForgotPassword.vue` | `email` from `@/routes/password` |
| `resources/js/pages/panel/auth/ResetPassword.vue` | `update` from `@/routes/password` |
| `resources/js/pages/panel/auth/VerifyEmail.vue` | `logout` from `@/routes`, `send` from `@/routes/verification` |
| `resources/js/pages/panel/settings/Profile.vue` | `ProfileController`, `send` from `@/routes/verification` |
| `resources/js/pages/panel/settings/Security.vue` | `SecurityController` |
| `resources/js/components/DeleteUser.vue` | `ProfileController` |
| `resources/js/components/ManageTwoFactor.vue` | `enable`, `disable` from `@/routes/two-factor` |
| `resources/js/components/ManagePasskeys.vue` | `destroy` from the passkey controller |

Nothing under `resources/js/panel/**` imports a route module at all. That is the layout, table,
form, widget and action machinery — the part that has to work for a panel whose id and path the
build cannot know.

## The three calls the panel makes

Wayfinder's exports carry more than this. These are the only three shapes the panel's own
components use, which is also all that
[`frontend/host/routes/shape.ts`](../../frontend/host/routes/shape.ts) models.

### `.form()` — bind onto Inertia's `<Form>`

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { store } from '@/routes/login';
</script>

<template>
    <Form v-slot="{ errors, processing }" v-bind="store.form()" :reset-on-success="['password']">
        <input name="email" type="email" required />
        <button type="submit" :disabled="processing">Log in</button>
    </Form>
</template>
```

`v-bind` spreads the action and method onto the form, so the component never spells a URL. This is
the shape on every panel auth page, on both settings pages, and on the two-factor and delete-account
components.

A controller module is an object of definitions, so the same call reads through a property:

```vue
<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
</script>

<template>
    <Form v-bind="ProfileController.update.form()">…</Form>
    <Form v-bind="ProfileController.destroy.form()" reset-on-success>…</Form>
</template>
```

### `.url(...)` — a string for the router

```ts
import { router } from '@inertiajs/vue3';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';

const handleDelete = (id: number, onError: () => void) => {
    router.delete(destroy.url(id), {
        preserveScroll: true,
        onError,
    });
};
```

This is the only place the panel passes a route parameter. `ManagePasskeys.vue` needs a URL rather
than form attributes because the delete is issued imperatively, from a confirmation handler.

### Bare invocation — an href

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { logout } from '@/routes';
</script>

<template>
    <Link :href="logout()" as="button">Log out</Link>
</template>
```

`VerifyEmail.vue` uses this. A guest waiting on a verification link needs a way out, and the exit
is the application's logout, not a panel route.

## What the modules must export

The stand-ins in `frontend/host/` are the package's own type-checking seam, not something an
application installs — they are `export-ignore`d, so `composer require` never brings them. They
are worth reading as a contract, because each declares the exact surface the panel's components
touch:

```ts
export interface RouteInvocation {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
}

export interface RouteDefinition {
    (...args: Array<string | number | undefined>): RouteInvocation;
    url(...args: Array<string | number | undefined>): string;
    form(...args: Array<string | number | undefined>): RouteInvocation;
}
```

A real Wayfinder definition carries more than this — query builders, per-method variants,
route-model-binding parameter objects. The stand-in models only what is used, because a fuller one
would be inventing a contract rather than recording one.

## Where the panel does not use Wayfinder

Every URL inside a panel comes from the server. The framework cannot generate them at build time,
because a panel's route names are `panel.{id}.*` and the id is a runtime fact — an application can
register three panels, or rename one, without the frontend being rebuilt.

```php
// PandaPanel\Core\Panel
$panel->getRouteNamePrefix();   // 'panel.admin.'
$panel->routeName('dashboard'); // 'panel.admin.dashboard'
```

So the server serializes finished URLs into the props:

```php
'url' => route($panel->routeName('search'), absolute: false),
```

and the components read them:

| Where the URL comes from | Example |
| --- | --- |
| Shared props | `panels[].url`, `search.url`, `tenancy.available[].url` |
| Navigation metadata | every sidebar item's `url`, every user-menu item's `url` |
| Page props | a page's own action endpoints, `emailCodeUrls.enable` |
| `panel.path` | `` :action="`/${panel.path}/two-factor/verify`" `` in `EmailCode.vue` |
| Resource helpers, server-side | `UserResource::url('edit', $record)` |

The rule the frontend is held to is "no hardcoded panel URLs" — every href comes from the server
or from Wayfinder, and inside the panel it is always the server.

Wayfinder still generates modules for the panel's own named routes when you run it; they land in
`resources/js/routes/panel/…`. Nothing in the package imports them, and they are not in the panel's
publish map, so [`panel:assets`](updating-assets.md) never reports or overwrites generated output.

## Checking, and what a failure looks like

```php
use PandaPanel\Support\Installer\FrontendRequirements;

/** @return list<string> the missing modules, as `@/…` specifiers */
FrontendRequirements::missingHostModules();
```

A specifier is satisfied by any of these spellings under `resources/js`, tried in order:

```text
.ts   .vue   .d.ts   /index.ts   /index.vue   /index.d.ts
```

A bare match is deliberately absent from that list. `File::exists()` answers true for a directory,
so a bare match would let `@/routes` be satisfied by an empty folder.

`php artisan panel:install` runs the same check and names the modules:

```text
  The published components import these modules, which belong to your application and are not
  there yet:

    @/routes
    @/routes/login
    @/routes/password
    …

  `@/routes/*` and `@/actions/*` are generated — run `php artisan wayfinder:generate`. The rest
  come with a Laravel Vue starter kit.
```

It is a list rather than a count on purpose. *All* of `@/routes/*` and `@/actions/*` missing means
Wayfinder has never run. A handful of components missing means this is not a starter kit
application and those files have to be written.

Skipping the check gets you the same problem as a build error about a module specifier — a true
error about the wrong thing:

```text
Failed to resolve import "@/routes/login" from "resources/js/pages/panel/auth/Login.vue".
```

## Keeping it generated

Wayfinder is the application's tool, run against the application's routes, so the package never
runs it for you.

- **With the Vite plugin** — the arrangement a Laravel Vue starter kit ships — `npm run dev` and
  `npm run build` regenerate the modules, and route changes are picked up on the next build.
- **Without it**, run `php artisan wayfinder:generate --with-form` after any change to a route
  name or a settings controller signature.

Route *names* are what Wayfinder generates from, so they are the part to keep stable; a URI is
safe to move. A renamed `password.update` is a module export that disappears, and the panel's
reset-password page stops building.

## Notes

- **The modules are needed at build time, not at runtime.** A panel with no `login()` never
  renders `Login.vue`, but the Inertia resolver's glob still compiles it, so the import still has
  to resolve.
- **Your `ProfileController` needs `destroy`.** `DeleteUser.vue` binds
  `ProfileController.destroy.form()`, and it is imported unconditionally by the panel's profile
  page. The example application in `examples/` ships only `update`, because it is an example of the
  server half — the delete route is the starter kit's.
- **Your `SecurityController` needs `update`.** The panel owns the security *screen*; the write
  stays in your controller, so one place changes a password whichever form posted to it.
- **The panel posts to Fortify's endpoints, not to copies of them.** Duplicating the login POST
  per panel would mean duplicating rate limiting, two-factor, passkeys and session handling — four
  things that must never disagree between two doors into the same application.
- **`@/actions/Laravel/Passkeys/...` only exists if passkeys are installed.** `ManagePasskeys.vue`
  renders nothing when `canManagePasskeys` is false, but the import is static and still has to
  resolve.
- **Generated modules are not published assets.** They are not in `PublishedAssets::map()`, so
  they never appear in a `panel:assets` report, and re-running Wayfinder is the only thing that
  updates them.
- **A test derives the list from the imports themselves.** `FrontendContractTest` scans every
  published file for `@/…` specifiers the package does not ship and asserts each is declared in
  `FrontendRequirements`, so a module that reached one and not the other fails here rather than in
  somebody's build.

## See also

- [Host modules](host-modules.md), [Published asset structure](assets.md), [Updating published assets](updating-assets.md)
- [Inertia pages](inertia-pages.md), [Vue component tree](component-tree.md)
- [Frontend requirements](../getting-started/frontend-requirements.md), [Laravel Vue starter kit setup](../getting-started/vue-starter-kit.md), [Common install problems](../getting-started/common-install-problems.md)
- [Routing](../concepts/routing.md), [Frontend assets](../concepts/frontend-assets.md)
- [URLs and routes](../resources/urls-routes.md), [Panel URLs](../pages-navigation/urls.md)
- [Fortify](../authentication/fortify.md), [Login](../authentication/login.md), [Profile](../authentication/profile.md), [Security](../authentication/security.md), [Passkeys](../authentication/passkeys.md)
- [`panel:install`](../cli/panel-install.md)
- [Troubleshooting: missing host modules](../troubleshooting/host-modules.md), [Vite](../troubleshooting/vite.md)
