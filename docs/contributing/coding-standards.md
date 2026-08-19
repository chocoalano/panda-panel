# Coding Standards

What Pint and PHPStan enforce mechanically, and the conventions they cannot check but review will ask about. Reach for this page before opening a pull request, and when a tool has rejected something you believe is correct — two of the entries below are cases where the tool is right and the obvious fix is wrong.

## A minimal working example

```bash
composer format          # vendor/bin/pint — fix
composer format-check    # vendor/bin/pint --test — report only, what CI runs
composer analyse         # vendor/bin/phpstan analyse --memory-limit=1G

npm run format           # prettier --write
npm run lint:fix         # eslint --fix
npm run typecheck        # vue-tsc --noEmit
```

Every file in the repository passes all six. A pull request that does not is not ready, and `composer ci` plus `npm run ci` is how you find out in two commands.

## Style: Pint

`pint.json`, in full:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": {
            "sort_algorithm": "alpha"
        },
        "no_unused_imports": true
    },
    "exclude": [
        "integration",
        "vendor"
    ]
}
```

The Laravel preset plus three rules:

| Rule | Effect |
| --- | --- |
| `declare_strict_types` | Every PHP file opens with `declare(strict_types=1);`. A rule rather than a convention, so there is no file in the repository without it. |
| `ordered_imports` with `alpha` | `use` statements sorted alphabetically. A deterministic order means an import diff is about the import. |
| `no_unused_imports` | An import nothing references is removed. |

So the head of every source file looks like this:

```php
<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\SortDirection;
```

`exclude` names `integration`, a directory that no longer exists — it held a copy of the application the framework was extracted from and was removed. The entry is harmless and is left because removing it changes nothing.

`.editorconfig` covers what Pint does not — the frontend, the config files, and the docs:

```ini
[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.{yml,yaml,json,neon}]
indent_size = 2

[*.md]
trim_trailing_whitespace = false
```

Markdown keeps trailing whitespace because two trailing spaces are a line break in Markdown, and stripping them silently reflows a paragraph.

## Static analysis: PHPStan

`phpstan.neon`, in full:

```yaml
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 4
    paths:
        - src
        - database
    tmpDir: build/phpstan
```

| Key | Value | Why |
| --- | --- | --- |
| `includes` | `larastan/extension.neon` | Larastan teaches PHPStan about Eloquent, the container, facades and helpers. |
| `level` | `4` | Level 5 adds the "view-string" check, which cannot resolve namespaced package views (`panda-panel::*`) because the service provider never boots during analysis. |
| `paths` | `src`, `database` | `tests/` and `examples/` are not analysed. |
| `tmpDir` | `build/phpstan` | Under `build/`, which is gitignored, so a checkout needs no directory git cannot track. |

The level comment in the file states the condition for raising it: once nothing references a namespaced package view. It is a stated reason rather than a preference, which is the standard for changing it.

CI runs the analyser **twice**, at both ends of the supported range — Laravel 12 on PHP 8.2 and Laravel 13 on PHP 8.4 — because the two ends disagree about what is real. `toPasswordRulesString()` exists on Laravel 13 and not on 12: analysing only the ceiling misses the call that breaks the floor, and analysing only the floor reports a guard the ceiling does not need. A version guard therefore has to be written so both runs are clean.

Because `tests/` is outside `paths`, a trait used only by test fixtures is reported as unused from `src/`. Put shared behaviour where source code uses it, or on the base class, rather than adding an ignore.

## Two traps the tools set

### `class-string<Resource>` becomes `class-string<resource>`

Pint's `phpdoc_types` fixer normalises scalar type names in docblocks, and `resource` is one of PHP's own types. A docblock naming this framework's `Resource` class is rewritten to lowercase and then means the PHP resource type:

```php
// Wrong: Pint rewrites this to class-string<resource> on the next run.
use PandaPanel\Resources\Resource;

/** @param class-string<Resource> $resource */
```

Import the base class under an alias. Pint leaves a name it does not recognise alone:

```php
use PandaPanel\Resources\Resource as PanelResource;

/** @param class-string<PanelResource> $resource */
```

A leading-slash FQCN also survives the fixer and is worse: `fully_qualified_strict_types` then re-adds a `use` statement the code never uses, so the file ends up with both an unused import and a name every IDE reports as simplifiable. Disabling `phpdoc_types` would fix it too, at the cost of scalar normalisation everywhere. The alias is the local fix, and it applies to `ResourceConfiguration` in `PandaPanel\Resources` as well.

### `Request::query('a.b')` does not walk a path

The query bag looks the whole string up as one key, so a dotted path reads as a literal key name and answers null. Namespaced table state — a relation table writing to `relations[posts][page]` — is read through `data_get()`:

```php
data_get($request->query(), $path);
```

## Conventions the tools cannot check

### Setters are bare, readers are `get`-prefixed

Recorded as decision D9 in [the ADR](architecture-decisions.md). PHP cannot overload, and a combined setter/getter is the magic this framework avoids:

```php
$panel->id('admin')->path('admin')->auth();

$panel->getId();          // 'admin'
$panel->getPath();        // 'admin'
$panel->getMiddleware();  // list<string>
```

### Nothing but scalars and arrays crosses to Vue

The boundary is a serialization contract, not a style preference. A schema serializes columns, filters, fields, layouts, actions, widgets and navigation as scalars and arrays. Closures are evaluated during serialization and only their result crosses:

```php
use App\Models\Post;
use PandaPanel\Actions\Action;

Action::make('publish')->visible(static fn (?Post $record): bool => $record?->draft === true);
```

`Action::visible(Closure $callback): static` holds the closure on the server. What Vue receives is the action's array with the button present or absent — never the closure, never SQL, never a policy internal, never a class name.

There are tests asserting that class names never reach page metadata or shared props. A new key on a serialized array is a new key on the TypeScript interface in the same change — the Vue side discriminates on `type` with an exhaustive `never` check, so a PHP type without a renderer is a compile error rather than an empty cell.

### `discover*()` and `navigationGroups()` accumulate

They append rather than overwrite, because a single-path implementation would be a dead end for a module system that contributes to a panel it did not define. A test registers two discovery paths on one panel and asserts both survive.

### No reflection or filesystem scanning in a request path

Discovery runs during boot, or once in `panel:cache`. A `Finder` in a controller is a `Finder` on every page load.

### Never build a Tailwind class by interpolation

`md:col-span-${n}` will not exist in the bundle, because the class was never in a file Tailwind scanned. Column spans, badge colours, grid columns and content widths all map through literal records:

```ts
const SPANS: Record<number, string> = {
    1: 'md:col-span-1',
    2: 'md:col-span-2',
    3: 'md:col-span-3',
    4: 'md:col-span-4',
};
```

### Validate values crossing from PHP, do not assert them

A guard function narrows an incoming payload so a shape mismatch degrades to an empty cell rather than throwing inside a table. `types/cellGuards.ts`, `types/widgetGuards.ts` and `composables/usePanelPage.ts` are the pattern.

## Frontend style

Prettier owns formatting. `.prettierrc.json`:

```json
{
    "semi": true,
    "singleQuote": true,
    "trailingComma": "all",
    "tabWidth": 4,
    "useTabs": false,
    "printWidth": 80,
    "vueIndentScriptAndStyle": false,
    "endOfLine": "lf",
    "overrides": [
        { "files": ["*.json", "*.yml", "*.yaml"], "options": { "tabWidth": 2 } }
    ]
}
```

`.prettierignore` excludes `build`, `node_modules`, `vendor`, `bootstrap`, `examples`, `resources/views` and `resources/js/components/ui`. The last one is vendored from shadcn-vue and left in that project's formatting, because these are the files an application is most likely to re-pull upstream and reformatting them would make every update a whitespace diff.

ESLint owns the class of mistake that type-checks perfectly and is still wrong. `eslint-config-prettier` comes last and turns off every stylistic rule, so no rule is one both tools have an opinion about. The six overrides in `eslint.config.js`, each with a stated reason:

| Rule | Setting | Reason |
| --- | --- | --- |
| `vue/multi-word-component-names` | `off` | `PanelSidebar`, `DataTable`, `ActionModal` — single-word names are the framework's to use, and the rule cannot tell the difference. |
| `vue/no-mutating-props` | `error` | A prop the server sent is data. Copying every one into local state to satisfy a rule is how a form ends up with two ideas of what its value is. |
| `@typescript-eslint/no-unused-vars` | `error`, ignoring `^_` | An unused argument prefixed `_` is a signature being honoured. |
| `@typescript-eslint/no-explicit-any` | `error` | Payloads arrive as untyped JSON and are narrowed by hand. `unknown` is the input to that; `any` would be skipping it. |
| `vue/no-v-html` | `off` | One use, in the Markdown preview, safe by construction: `renderMarkdown()` escapes every character before adding a single tag. Off globally rather than at the site because the rule reports on the attribute and a disable comment cannot sit on the line before it in a multi-line tag. |
| `vue/require-default-prop` | `off` | Written for the options API. With `defineProps<Props>()`, `class?: string` with no default is the correct way to say "no class unless given one"; a default of `''` would put an empty attribute on every element. |

Two directories relax further: `frontend/host/**` allows empty component blocks, because the stand-ins exist to be minimal; and `resources/js/components/ui/**` turns off four rules for the same reason Prettier skips it.

TypeScript is strict, from `tsconfig.json`:

```json
"strict": true,
"noImplicitOverride": true,
"noUnusedLocals": true,
"noUnusedParameters": true,
"forceConsistentCasingInFileNames": true,
"isolatedModules": true,
"verbatimModuleSyntax": true,
"noEmit": true
```

`verbatimModuleSyntax` means a type-only import must say so:

```ts
import type { Plugin } from 'vite';
import { defineConfig } from 'vite';
```

## Changes that are never one edit

Some changes have a fixed set of places that must move together. Making half of one is the most common way a pull request comes back:

| Change | Also requires |
| --- | --- |
| A new column, field, entry or widget type | The PHP class, the enum case, and a branch in the Vue renderer — the union is exhaustive and a missing branch is a compile error. |
| A field on `NavigationItem` | The constructor, every `with*()` copy, `toArray()`, its docblock array shape, the TypeScript interface, and the key list in `NavigationTest`. |
| A key in `Page::metadata()` | The strict `->has('page', ...)` assertion in `PanelShellTest`. |
| A new lifecycle hook | The order documented in `HasLifecycleHooks`, the `HookedCreateUser` / `HookedEditUser` fixtures that record every call, and `ResourceLifecycleHookTest`. |
| A new icon name in PHP | `php artisan panel:icons`, which rewrites `resources/js/panel/icons/registry.ts`. Never edit that file by hand. |
| A new panel asset entrypoint | `vite.config.ts`'s `input`, or the page dies with a manifest error. |
| A new config file at the repository root | An `export-ignore` line in `.gitattributes`. |
| A new CSS hook name | `hook('name')` in the component that draws it — `StylingTest` asserts every allowlisted name is emitted somewhere. |

## Notes

- **`declare(strict_types=1)` is not optional.** Pint adds it, and a file without it fails `--test` in CI rather than being quietly fixed.
- **Do not add a PHPStan baseline.** There is none, and the analyser reports no errors today. A baseline is a list of things nobody will fix.
- **Do not call `Gate::allows()` directly.** Authorization goes through `PandaPanel\Support\PolicyGate::allows()`, which is where strict-authorization behaviour lives. Two copies of that rule would be two places to keep true.
- **Do not call `DB::transaction()` directly in a page or action.** `PandaPanel\Support\DatabaseTransaction::run(?bool, Closure)` resolves action → page → panel → on, and `null` means "did not decide" rather than "off".
- **`import.meta.glob` never takes the `@` alias.** Vite's dev server resolves an aliased pattern to nothing while the production build resolves it normally, so a registry built that way works only after a build. Use a relative pattern.
- **The generated code has to pass too.** `GeneratorTest` runs Pint over what `make:panel-resource` writes, so a stub that drifts from these standards fails the suite.

## See also

- [Local development](local-development.md) — running these tools, with every flag
- [Running the tests](testing.md) — the third of the three checks
- [Frontend toolchain](frontend-toolchain.md) — the configs behind the frontend half
- [Architecture decisions](architecture-decisions.md) — the conventions that came from a decision
- [Pull requests](pull-requests.md) — the checklist
- [CI matrix](../testing/ci-matrix.md) — where each of these runs, and on what
- [Component registries](../concepts/component-registries.md) — why a name resolves at build time
- [Server metadata to Vue](../concepts/metadata-to-vue.md) — the serialization boundary in detail
