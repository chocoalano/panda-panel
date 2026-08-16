# Pull Requests

What a change is expected to carry before it is opened, and what review will ask about. There is no pull request template in this repository and no bot that comments; the checks are the five CI jobs and a person reading the diff. This page is what both of them look for.

## A minimal working example

```bash
git switch -c fix/table-sort-whitelist

# ... make the change, add the test ...

composer ci      # pint --test, phpstan, pest
npm run ci       # prettier --check, eslint, vue-tsc, vite build

git add -A
git commit -m "Ignore a sort column the schema never declared sortable"
git push -u origin fix/table-sort-whitelist
```

Then open the pull request against `main`. `main` is the default branch and the only one CI runs on — `on: push: branches: [main]` and `on: pull_request: branches: [main]`.

If both commands pass locally, the only way CI fails is for a version-specific reason: a different PHP minor, a different Laravel major, `--prefer-lowest`, or a different Node. That is what the matrix is for, and [CI matrix](../testing/ci-matrix.md) is how to read the failure.

## The checklist

Ten questions, in the order they usually go wrong.

1. **Does a test fail without the change?** A fix with no test is a fix that comes back. See [Running the tests](testing.md) for where it goes.
2. **Does the negative suite need a counterpart?** A change to authorization, to a query parameter, to a file download, or to what a schema accepts belongs in `tests/Feature/Panel/Negative/` as well as in its own file. The standard is that deleting the guard makes the test fail.
3. **Does `composer ci` pass?** Style, static analysis, tests.
4. **Does `npm run ci` pass?** Formatting, lint, types, build. Skipping it because the change "is only PHP" is how the published components stop compiling.
5. **Is there a matching change on the other side of the boundary?** A new column, field, entry or widget type is a PHP class, an enum case, and a branch in the Vue renderer. The union is exhaustive, so a missing branch is a compile error rather than an empty cell — but only if you ran the type-check.
6. **Does anything in the fixed set need to move with it?** The table in [Coding standards](coding-standards.md#changes-that-are-never-one-edit) lists them: `NavigationItem` fields, `Page::metadata()` keys, lifecycle hooks, icon names, asset entrypoints, CSS hook names.
7. **Is there a `CHANGELOG.md` entry?** Under `## [Unreleased]`, in the right section. See [Releases](releases.md#the-changelog).
8. **Do the docs still say the truth?** `docs/` is written page by page and cross-linked; a renamed method or a changed default has a page naming it. Grep before assuming it does not.
9. **Does it break an application?** Then it also belongs in [`docs/upgrading/breaking-changes.md`](../upgrading/breaking-changes.md), with the smallest fix stated. A silent break — code that keeps running and does the wrong thing — goes at the top of that list.
10. **Does it need an ADR?** [Architecture decisions](architecture-decisions.md#when-a-change-needs-a-new-adr) has the table.

## What CI runs

Five jobs, from `.github/workflows/tests.yml`:

| Job | Runs | Matrix | Blocking |
| --- | --- | --- | --- |
| `test` | `vendor/bin/pest` | PHP 8.2–8.4 × Laravel 12–13 × `prefer-lowest`/`prefer-stable`, minus PHP 8.2 with Laravel 13 | yes |
| `static-analysis` | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` | Laravel 12 on PHP 8.2, Laravel 13 on PHP 8.4 | yes |
| `code-style` | `vendor/bin/pint --test` | one job, PHP 8.4 | yes |
| `frontend` | `format:check`, `lint`, `typecheck`, `build` | Node 20, 22, 24 | yes |
| `frontend-latest` | `typecheck`, `build` against the top of every range | one job, Node 22 | no — `continue-on-error` |

`fail-fast: false` on every matrix, so one failing combination does not cancel the others. When three of ten jobs fail, knowing which three is most of the diagnosis.

`frontend-latest` is allowed to fail. An upstream minor breaking the build is news, not a reason to block a pull request that had nothing to do with it — and a red job that blocks nothing is still a red job somebody looks at.

## Reproducing a CI failure

The `test` job is two commands. Do it on a branch, and restore afterwards, because `composer require` rewrites `composer.json`:

```bash
composer require "laravel/framework:12.*" --no-interaction --no-update
composer require "orchestra/testbench:10.*" --dev --no-interaction --no-update
composer update --prefer-lowest --prefer-dist --no-interaction

vendor/bin/pest

git checkout composer.json && composer update
```

Laravel 13 pairs with Testbench 11. `--prefer-lowest` is the resolution that fails first when a new dependency range has a floor nobody has run.

For the frontend:

```bash
npm ci && npm run ci                              # what the blocking job runs
npm install --no-package-lock && npm run build    # what the non-blocking one runs
```

## Commits and branches

There is no enforced commit convention. Write a subject line that says what changed in the imperative, and use the body for the reason when the reason is not obvious from the diff — the same standard the comments in this codebase are held to, and the reason `CHANGELOG.md` entries read as prose rather than as a list of nouns.

Branch off `main` and open the request against `main`. There is no develop branch and no release branch; a release is a tag on `main`.

## Scope

One change per pull request, where "one change" means one reason to be reverted. A formatting sweep bundled with a fix makes the fix unreviewable, and `composer format` over an unrelated file is a formatting sweep.

Two exceptions, both because the pieces cannot land separately:

- **A change that spans the boundary.** A new column type is one change even though it touches PHP, an enum, TypeScript and a test.
- **A fix and its regenerated artefact.** A new icon name and the `panel:icons` output are one change; committing the name without the registry ships a button with no icon.

## Files you do not edit by hand

| File | Regenerate with |
| --- | --- |
| `resources/js/panel/icons/registry.ts` | `php artisan panel:icons` (`--check` fails when it is out of date) |
| `package-lock.json` | `npm install` after changing a range — never hand-edited |
| `build/**` | It is scratch output; `rm -rf build` |

`registry.ts` says so in its own header. A hand-edited registry is one that disagrees with the command the next person runs.

## Notes

- **`.github/` is in `.gitignore`.** The workflow file exists in a working tree and is not tracked, so changing CI needs `git add -f .github/workflows/tests.yml` to reach a commit.
- **`composer.lock` is not committed.** Do not add it. A library is tested against its ranges, and CI resolves fresh on every run.
- **`package-lock.json` is committed.** Changing a range in `package.json` without regenerating it makes `npm ci` fail rather than resolve, which is the intended behaviour.
- **`composer require` in CI edits `composer.json`.** It is `--no-update`, so nothing resolves until the single `composer update` — but a local reproduction leaves the file changed. Check it out afterwards.
- **`code-style` red is one command.** Run `composer format` and commit.
- **The PHP jobs are not cached.** Resolving the ranges fresh is the point; a cache keyed on `composer.lock` would defeat it.
- **Adding a config file at the repository root needs an `export-ignore` line** in `.gitattributes`, or it ships to every application.
- **Coverage is not in the workflow.** `composer test-coverage` exists locally; every PHP job sets `coverage: none` on `setup-php`.

## See also

- [Local development](local-development.md) — the commands this page assumes
- [Running the tests](testing.md) — what a change is expected to add
- [Coding standards](coding-standards.md) — including the changes that are never one edit
- [Architecture decisions](architecture-decisions.md) — when a change needs a record
- [Security](security.md) — do not open a pull request for a vulnerability
- [Releases](releases.md) — what happens to a merged change
- [CI matrix](../testing/ci-matrix.md) — every job, and how to read a failure
- [Breaking changes](../upgrading/breaking-changes.md) — the page a breaking change is added to
