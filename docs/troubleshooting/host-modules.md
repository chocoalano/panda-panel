# Missing host modules

`npm run build` fails with `Failed to resolve import "@/routes/login"`, or a similar message naming
`@/components/UserMenuContent` or `@/types/ui`. Those modules belong to your application: Wayfinder
generates some of them from your own route table, and a Laravel Vue starter kit writes the rest.
Reach for this page when the build cannot resolve a specifier, or when adding the panel to an
application that is not a starter kit.

## Start here

The installer names every missing one, and changes nothing when run this way:

```bash
php artisan panel:install --no-panel --no-user --no-interaction
```

```text
  WARN  1 thing(s) this package cannot do for your application:

  1. The published components import these modules, which belong to your application and are
     not there yet:

       @/routes/two-factor
       @/components/UserMenuContent
       @/composables/useTwoFactorAuth

     `@/routes/*` and `@/actions/*` are generated — run `php artisan wayfinder:generate`.
     The rest come with a Laravel Vue starter kit.
```

Two commands fix the common case:

```bash
php artisan wayfinder:generate
npm run build
```

## Reading the answer in PHP

`PandaPanel\Support\Installer\FrontendRequirements` is what the installer asks, and it is callable
from anywhere — tinker, a test, a deployment check.

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingHostModules();
// ['@/routes/two-factor', '@/components/UserMenuContent']
```

| Method | Signature | Returns |
| --- | --- | --- |
| `missingHostModules` | `static missingHostModules(): array` | `list<string>` — `@/…` specifiers not found on disk |
| `npmPackages` | `static npmPackages(): array` | `list<string>` — `name@range` pairs from the package's own `dependencies` |
| `missingNpmPackages` | `static missingNpmPackages(): array` | the same pairs, minus what this application declares |
| `hasVite` | `static hasVite(): bool` | whether `vite.config.ts` or `vite.config.js` exists |
| `missingInertia` | `static missingInertia(): array` | `list<string>` — what is missing, in words |
| `layoutOverrides` | `static layoutOverrides(): array` | `list<array{file: string, line: int, code: string}>` |

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::hasVite();          // false → the components are Vue and need a build
FrontendRequirements::missingInertia();   // ['an Inertia root view at resources/views/app.blade.php']
FrontendRequirements::missingNpmPackages();
// ['reka-ui@^2.0.0', 'vue-sonner@^2.0.0']
FrontendRequirements::layoutOverrides();
// [['file' => 'resources/js/app.ts', 'line' => 12, 'code' => 'page.default.layout = AppLayout;']]
```

## The nineteen modules

The private `HOST_MODULES` list inside `FrontendRequirements`, in declaration order.
`missingHostModules()` is how you read it from outside.

| Module | Comes from |
| --- | --- |
| `@/routes` | Wayfinder |
| `@/routes/login` | Wayfinder |
| `@/routes/register` | Wayfinder |
| `@/routes/password` | Wayfinder |
| `@/routes/two-factor` | Wayfinder |
| `@/routes/verification` | Wayfinder |
| `@/actions/App/Http/Controllers/Settings/ProfileController` | Wayfinder |
| `@/actions/App/Http/Controllers/Settings/SecurityController` | Wayfinder |
| `@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController` | Wayfinder |
| `@/components/Heading` | starter kit |
| `@/components/UserInfo` | starter kit |
| `@/components/UserMenuContent` | starter kit |
| `@/components/PasskeyItem` | starter kit |
| `@/components/PasskeyRegister` | starter kit |
| `@/components/TwoFactorRecoveryCodes` | starter kit |
| `@/components/TwoFactorSetupModal` | starter kit |
| `@/composables/useTwoFactorAuth` | starter kit |
| `@/types` | starter kit |
| `@/types/ui` | starter kit — imported by the panel's broadcasting and flash bridge |

**Which ones are missing tells you what to do.** All of `@/routes/*` and `@/actions/*` means
Wayfinder has not run. A handful of components means this is not a starter kit application, and
those files have to be written.

## How a module is looked for

`@/x` means `resources/js/x`. Each name is tried with every one of these suffixes, in order:

```text
.ts   .vue   .d.ts   /index.ts   /index.vue   /index.d.ts
```

Extensionless, because a Wayfinder module is `.ts`, a component is `.vue`, and a starter kit may
write either as a directory with an index.

There is deliberately **no bare match** in that list. `File::exists()` answers true for a directory,
so a bare entry made every directory-shaped module vacuous: `@/types` was satisfied by the *folder*
this package publishes into, however empty it was of the module actually being imported. `.d.ts` is
there because a starter kit writes its shared types as `resources/js/types/index.d.ts`, which is a
real answer to `@/types`.

That is why `ls resources/js/types` showing a directory is not evidence the module exists:

```bash
ls resources/js/types/index.d.ts resources/js/types/ui.ts 2>/dev/null
```

## Fixing the generated half

```bash
php artisan wayfinder:generate
```

Wayfinder writes `resources/js/routes/**` and `resources/js/actions/**` from the application's own
route table and controllers. Most projects do not commit that output, so a fresh clone and every CI
job needs the command before `npm run build`.

The three `@/actions/*` modules name controllers the starter kit ships:
`Settings\ProfileController`, `Settings\SecurityController` and, from `laravel/passkeys`,
`PasskeyRegistrationController`. An application without those controllers will not have those
modules generated, and the panel's profile, security and passkey screens are what import them.

## Writing the rest by hand

If the application is not a starter kit, the components have to exist. This repository holds a
minimal stand-in for each under `frontend/host/`, used only when type-checking and building the
package on its own — nothing there is published or reachable from an application, but each declares
the exact props, emits and exports the panel's own components use, which makes it the specification.

```ts
// resources/js/types/ui.ts — the flash toast the panel's middleware writes
export interface FlashToast {
    type: 'success' | 'error' | 'warning' | 'info';
    message: string;
    url?: string | null;
    urlLabel?: string | null;
}
```

```ts
// resources/js/composables/useTwoFactorAuth.ts
import type { Ref } from 'vue';

export function useTwoFactorAuth(): {
    hasSetupData: Ref<boolean>;
    clearTwoFactorAuthData: () => void;
};
```

```vue
<!-- resources/js/components/Heading.vue -->
<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}>();
</script>
```

Match the surface, not the styling — the styling is yours.

## The other half of the same build error

The same `Failed to resolve import` message covers a missing npm dependency, and the two are told
apart by what the specifier looks like: `@/…` is a host module, anything else is a package.

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingNpmPackages();
```

```bash
npm install @inertiajs/vue3@^3.0.0 reka-ui@^2.0.0 vue-sonner@^2.0.0
npm run build
```

`npmPackages()` reads the `dependencies` block of *this package's* `package.json` rather than
restating a list — a second copy goes stale the first time a component imports something new.
`missingNpmPackages()` compares against the application's `package.json`, reading both
`dependencies` and `devDependencies`, because what matters is whether the project has *declared* the
dependency: a transitive copy in `node_modules` today is one somebody else's upgrade removes
tomorrow.

An application with no `package.json` at all gets the whole list back.

## Notes

- **A missing host module fails at `npm run build`, in a message about a module specifier.** That is
  a true error about the wrong thing, which is why the installer checks for them and names each one.
- **`package.json` is `export-ignore`d in `.gitattributes`.** A composer install from a dist archive
  therefore has no copy of it inside `vendor/chocoalano/panel`, and `npmPackages()` — which reads
  exactly that file — answers an empty list. The npm half of the installer's report is only complete
  when the package is installed from source; the full list is in
  [Frontend requirements](../getting-started/frontend-requirements.md).
- **A directory is not a module.** `resources/js/types/` existing does not satisfy `@/types`; a
  `.ts`, `.d.ts` or index file inside it does.
- **`missingNpmPackages()` reads `package.json`, not `node_modules`**, on purpose.
- **Nothing in `frontend/host` ships.** It is `export-ignore`d, and `@/…` inside a published file
  resolves against the application's own `resources/js`.
- **The account menu entries are yours to render.** `panel.shell.userMenuItems` crosses the wire;
  `UserMenuContent.vue` is the host's component, so nothing shipped with the package draws them.
- **A test derives the list from the imports in the published tree** and compares it against
  `FrontendRequirements`, so a module that reached the imports without reaching the list fails in
  this repository rather than in somebody's build.

## See also

- [Host modules reference](../frontend/host-modules.md)
- [Frontend requirements](../getting-started/frontend-requirements.md), [Vue starter kit setup](../getting-started/vue-starter-kit.md)
- [Wayfinder routes](../frontend/wayfinder.md), [Inertia pages](../frontend/inertia-pages.md)
- [`panel:install`](../cli/panel-install.md)
- [Vite build errors](vite.md), [Inertia root view](inertia-root-view.md), [Tailwind](tailwind.md)
- [Common install problems](../getting-started/common-install-problems.md)
