# Host Modules

Nineteen `@/…` modules the panel's published components import and the package does not ship. They belong to your application: Wayfinder generates some of them from your own route table, and a Laravel Vue starter kit writes the rest. Reach for this page when `npm run build` fails on a module specifier, or when adding the panel to an application that is not a starter kit.

The package cannot ship them, and shipping them would be wrong even if it could — that is what the rest of this page is about.

## A minimal working example

Ask the installer what is missing:

```bash
php artisan panel:install
```

It reports, by name:

```text
The published components import these modules, which belong to your
application and are not there yet:

    @/routes/two-factor
    @/components/UserMenuContent
    @/composables/useTwoFactorAuth

`@/routes/*` and `@/actions/*` are generated — run `php artisan wayfinder:generate`.
The rest come with a Laravel Vue starter kit.
```

Two commands fix the common cases:

```bash
php artisan wayfinder:generate    # writes @/routes/* and @/actions/*
npm run build
```

## The list

The `HOST_MODULES` list inside `PandaPanel\Support\Installer\FrontendRequirements`, in the order it is declared. The constant is private; `missingHostModules()` is how you read it from outside.

| Module | Kind | Comes from |
| --- | --- | --- |
| `@/routes` | generated | Wayfinder |
| `@/routes/login` | generated | Wayfinder |
| `@/routes/register` | generated | Wayfinder |
| `@/routes/password` | generated | Wayfinder |
| `@/routes/two-factor` | generated | Wayfinder |
| `@/routes/verification` | generated | Wayfinder |
| `@/actions/App/Http/Controllers/Settings/ProfileController` | generated | Wayfinder |
| `@/actions/App/Http/Controllers/Settings/SecurityController` | generated | Wayfinder |
| `@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController` | generated | Wayfinder |
| `@/components/Heading` | component | starter kit |
| `@/components/UserInfo` | component | starter kit |
| `@/components/UserMenuContent` | component | starter kit |
| `@/components/PasskeyItem` | component | starter kit |
| `@/components/PasskeyRegister` | component | starter kit |
| `@/components/TwoFactorRecoveryCodes` | component | starter kit |
| `@/components/TwoFactorSetupModal` | component | starter kit |
| `@/composables/useTwoFactorAuth` | composable | starter kit |
| `@/types` | types | starter kit |
| `@/types/ui` | types | starter kit |

Which ones are missing tells you what to do. All of `@/routes/*` and `@/actions/*` means Wayfinder has not run. A handful of components means this is not a starter kit application, and you will have to write them.

## Why they are not shipped

Two of the kinds are impossible to ship, and the rest would be wrong to.

**`routes/*` and `actions/*` are generated.** Wayfinder writes them from your application's route table and controllers. A copy vendored into this package would be a snapshot of somebody else's routes, wrong the moment you rename one.

**The components are your application's design.** `UserMenuContent.vue` is where a project puts its own account links. Shipping one would mean overwriting a file every starter kit already has and every project has already edited.

That is also why the panel's `userMenuItems()` is serialized rather than rendered: the entries reach `panel.shell.userMenuItems`, and the application's own menu component decides where they go.

## Checking an application

`FrontendRequirements` is the class the installer uses, and it is callable from anywhere:

```php
use PandaPanel\Support\Installer\FrontendRequirements;

FrontendRequirements::missingHostModules();
// ['@/routes/two-factor', '@/components/UserMenuContent']

FrontendRequirements::npmPackages();
// ['@inertiajs/vue3@^3.0.0', '@internationalized/date@^3.12.0', …]

FrontendRequirements::missingNpmPackages();
// the same pairs, filtered to what this application has not declared

FrontendRequirements::hasVite();
// bool

FrontendRequirements::missingInertia();
// ['an Inertia root view at resources/views/app.blade.php']

FrontendRequirements::layoutOverrides();
// [['file' => 'resources/js/app.ts', 'line' => 4, 'code' => 'page.default.layout = AppLayout;']]
```

| Method | Signature | Returns |
| --- | --- | --- |
| `missingHostModules` | `static missingHostModules(): array` | `list<string>` — `@/…` specifiers not found on disk |
| `npmPackages` | `static npmPackages(): array` | `list<string>` — `name@range` pairs from the package's own `dependencies` |
| `missingNpmPackages` | `static missingNpmPackages(): array` | the same, minus what the application declares |
| `hasVite` | `static hasVite(): bool` | whether `vite.config.ts` or `vite.config.js` exists |
| `missingInertia` | `static missingInertia(): array` | `list<string>` — what is missing, in words |
| `layoutOverrides` | `static layoutOverrides(): array` | `list<array{file: string, line: int, code: string}>` |

### How a module is looked for

Extensionless, because a Wayfinder module is `.ts`, a component is `.vue`, and a starter kit may write either as a directory with an index. Each name is tried with every one of:

```text
.ts   .vue   .d.ts   /index.ts   /index.vue   /index.d.ts
```

There is deliberately no bare `''` in that list. `File::exists()` answers true for a directory, so a bare entry made every directory-shaped module vacuous: `@/types` was satisfied by the *folder* this package publishes into, however empty it was of the module actually being imported.

`.d.ts` is in the list because a starter kit writes its shared types as `resources/js/types/index.d.ts`, which is a real answer to `@/types`.

### The npm dependencies

`npmPackages()` reads the package's own `package.json` rather than restating a list — a second copy goes stale the first time a component imports something new. `missingNpmPackages()` compares against the application's `package.json`, looking at both `dependencies` and `devDependencies`, because what matters is whether the project has *declared* the dependency. A transitive copy in `node_modules` today is one somebody else's upgrade removes tomorrow.

The installer prints the result as a runnable command:

```bash
npm install @inertiajs/vue3@^3.0.0 \
  @internationalized/date@^3.12.0 \
  …
npm run build
```

### The layout override check

The one thing about this seam that cannot be fixed from inside the package:

```ts
page.default.layout = AppLayout;   // replaces the panel shell
```

Every panel page declares its own layout with `defineOptions({ layout: PanelLayout })`. An unconditional assignment in `resources/js/app.ts` overwrites that after the page has already asked, and the panel then renders inside your application's shell — your sidebar, not the panel navigation — at HTTP 200 with nothing logged.

`layoutOverrides()` reads `resources/js/app.ts`, `app.js`, `ssr.ts` and `ssr.js`, and reports every line that assigns `.layout` without falling back. These three forms all pass:

```ts
page.default.layout ??= AppLayout;
page.default.layout ||= AppLayout;
page.default.layout = page.default.layout || AppLayout;
```

`panel:install` refuses to finish quietly when it finds an offending line, naming the file and the line number.

## The stand-ins in `frontend/host`

This repository holds a minimal stand-in for each of the nineteen, used **only** when type-checking and building the package on its own. Nothing there is published, exported by Composer, or reachable from an application.

```text
frontend/host/
  routes/index.ts  login.ts  register.ts  password.ts  two-factor.ts
                   verification.ts  shape.ts
  actions/App/Http/Controllers/Settings/ProfileController.ts
          App/Http/Controllers/Settings/SecurityController.ts
          Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController.ts
  components/Heading.vue  UserInfo.vue  UserMenuContent.vue  PasskeyItem.vue
             PasskeyRegister.vue  TwoFactorRecoveryCodes.vue
             TwoFactorSetupModal.vue
  composables/useTwoFactorAuth.ts
  types/index.ts  ui.ts  inertia.d.ts
```

Resolution is two-step and ordered: `@/x` means `resources/js/x` first, falling through to `frontend/host/x` only when the file is genuinely not part of the package.

```json
// tsconfig.json
"paths": {
    "@/*": ["./resources/js/*", "./frontend/host/*"]
}
```

`vite.config.ts` implements the same two-step resolution for the bundler, from the same list, so a build and a type-check never disagree about what `@/x` means. A plain Vite alias cannot express the fall-through — an alias is one mapping, and two aliases would have the second shadowed by the first for every path.

That fall-through is what makes `npm run typecheck` possible at all. Without it, the whole published tree would fail to resolve nineteen imports and the toolchain would report nothing useful about the code it *can* check.

### Keeping the seam honest

A stand-in that drifted from what the starter kit really exports would let a real breakage type-check clean. Two things guard against it:

- Each stub declares the **exact** props, emits and exports the panel's own components use — no `any` escape hatches on the surface that matters — so removing a prop from a stub breaks the build here.
- `panel:install` checks a real application for every one of these paths, so the seam is verified where it is real rather than only where it is simulated.
- `FrontendContractTest` scans every published file for `@/…` imports the package does not satisfy and asserts that each one is in the declared list. A module that reached the imports without reaching the list is one the installer would report as fine and the host's build would then fail on.

## Writing your own

If the application is not a starter kit, the components have to exist. The stubs in `frontend/host/` are the specification of what each must export. Two examples of the surface actually used:

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

## Gotchas

- **A missing host module fails at `npm run build`, in a message about a module specifier.** That is a true error about the wrong thing, which is why the installer checks for them and names each one.
- **Wayfinder output is not committed in most projects.** A fresh clone or a CI job needs `php artisan wayfinder:generate` before `npm run build`, or every `@/routes/*` import fails.
- **A directory is not a module.** `resources/js/types/` existing does not satisfy `@/types`; a `.ts`, `.d.ts` or index file inside it does.
- **`missingNpmPackages()` reads `package.json`, not `node_modules`.** A package installed but not declared still reports as missing, on purpose.
- **Nothing in `frontend/host` ships.** It is not in the publish map, and `@/…` in a published file resolves against the application's own `resources/js`.
- **The account menu entries are yours to render.** `panel.shell.userMenuItems` crosses the wire; `UserMenuContent.vue` is the host's component, so nothing shipped with the package draws them.

## See also

- [Published Asset Structure](assets.md)
- [Wayfinder Routes](wayfinder.md)
- [Inertia Pages](inertia-pages.md)
- [Frontend Requirements](../getting-started/frontend-requirements.md)
- [Laravel Vue Starter Kit Setup](../getting-started/vue-starter-kit.md)
- [Common Install Problems](../getting-started/common-install-problems.md)
- [panel:install](../cli/panel-install.md)
- [Host modules troubleshooting](../troubleshooting/host-modules.md), [Vite troubleshooting](../troubleshooting/vite.md)
- [Frontend Contract Tests](../testing/frontend-contract-tests.md)
