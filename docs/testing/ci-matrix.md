# CI Matrix

`.github/workflows/tests.yml` runs five jobs on every push to `main` and every pull request against it. The matrix exists because this package supports two Laravel majors, three PHP minors and two dependency resolutions, and because the other half of the package is Vue and TypeScript that no PHP job can say anything about. This page is what each job proves and why the combinations are the ones they are — useful when a job fails, and as a template for a package of your own.

## A minimal working example

Everything CI runs, locally, in one command each:

```bash
composer ci    # pint --test, then phpstan, then pest
npm run ci     # prettier --check, then eslint, then vue-tsc, then vite build
```

Those two are the whole of it, minus the matrix. A change that passes both locally fails CI only for a version-specific reason, which is exactly what the matrix is for.

## The jobs

| Job | Runs | Matrix | Blocking |
| --- | --- | --- | --- |
| `test` | `vendor/bin/pest` | PHP × Laravel × stability, minus one exclusion — 10 jobs | yes |
| `static-analysis` | `vendor/bin/phpstan analyse` | two ends of the supported range | yes |
| `code-style` | `vendor/bin/pint --test` | one job, PHP 8.4 | yes |
| `frontend` | `format:check`, `lint`, `typecheck`, `build` | Node 20, 22, 24 | yes |
| `frontend-latest` | `typecheck`, `build` against the top of every range | one job, Node 22 | no — `continue-on-error` |

`fail-fast: false` on every matrix, so one failing combination does not cancel the others. When three of ten jobs fail, knowing which three is most of the diagnosis.

## The test matrix

```yaml
strategy:
  fail-fast: false
  matrix:
    php: ['8.2', '8.3', '8.4']
    laravel: ['12.*', '13.*']
    stability: [prefer-lowest, prefer-stable]
    include:
      - laravel: '12.*'
        testbench: '10.*'
      - laravel: '13.*'
        testbench: '11.*'
    exclude:
      - php: '8.2'
        laravel: '13.*'
```

Three axes and one exclusion:

- **PHP 8.2, 8.3, 8.4** — the floor is `"php": "^8.2"` in `composer.json`.
- **Laravel 12 and 13** — `"laravel/framework": "^12.0|^13.0"`, each pinned to the Testbench major that knows how to boot it: 10 for Laravel 12, 11 for Laravel 13.
- **`prefer-lowest` and `prefer-stable`** — the two ends of every dependency range. `prefer-lowest` is the one that catches a call to a method added in a minor release you did not declare.
- **The exclusion**: Laravel 13 requires PHP 8.3. The combination does not exist, and a job that cannot resolve is noise rather than coverage — PHP 8.2 is supported *through* Laravel 12, which is what the other half of the matrix proves.

Installation is a `require --no-update` for each pin, then one `composer update` at the chosen stability:

```yaml
- name: Install dependencies
  run: |
    composer require "laravel/framework:${{ matrix.laravel }}" --no-interaction --no-update
    composer require "orchestra/testbench:${{ matrix.testbench }}" --dev --no-interaction --no-update
    composer update --${{ matrix.stability }} --prefer-dist --no-interaction --no-progress

- name: Run tests
  run: vendor/bin/pest
```

No `composer install` anywhere: the committed `composer.lock` is a development convenience, and CI resolves the declared ranges fresh on every run instead, because a library's job is to work against the ranges rather than against one resolution of them. The extensions the suite needs are `mbstring`, `pdo_sqlite` and `zip` — sqlite because the harness runs on `:memory:`, zip because the xlsx writer is real.

## Static analysis, twice

```yaml
strategy:
  matrix:
    include:
      - laravel: '12.*'
        testbench: '10.*'
        php: '8.2'
      - laravel: '13.*'
        testbench: '11.*'
        php: '8.4'
```

Twice, because the two ends of the range disagree about what is real. `toPasswordRulesString()` exists on Laravel 13 and not on 12: analysing only the ceiling misses the call that breaks the floor, and analysing only the floor reports a guard the ceiling does not need.

`phpstan.neon`:

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

Level 4 rather than higher for one stated reason: level 5 adds the "view-string" check, which cannot resolve namespaced package views (`panda-panel::*`) because the service provider never boots during analysis. The paths are `src` and `database`; `tests/` and `examples/` are not analysed.

Locally: `composer analyse`, which is `vendor/bin/phpstan analyse --memory-limit=1G`.

## Code style

One job, `vendor/bin/pint --test`. `pint.json`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": { "sort_algorithm": "alpha" },
        "no_unused_imports": true
    },
    "exclude": ["integration", "vendor"]
}
```

`declare_strict_types` is a rule rather than a convention, so every file in the repository carries it. Locally: `composer format` to fix, `composer format-check` to check.

## Frontend

Four checks, in the order that gives the most useful failure first — formatting, then lint, then types, then a real build. A type error is a better message than the bundler's version of the same problem.

```yaml
strategy:
  fail-fast: false
  matrix:
    node: ['20', '22', '24']

steps:
  - uses: actions/setup-node@v4
    with:
      node-version: ${{ matrix.node }}
      cache: npm

  - run: npm ci
  - run: npm run format:check
  - run: npm run lint
  - run: npm run typecheck
  - run: npm run build
```

| Script | Command |
| --- | --- |
| `format:check` | `prettier --check resources/js frontend resources/css` |
| `lint` | `eslint resources/js frontend --max-warnings=0` |
| `typecheck` | `vue-tsc --noEmit -p tsconfig.json` |
| `build` | `vite build` |
| `ci` | all four, in that order |

`npm ci` rather than `npm install`, against a committed lockfile. That is the opposite of the PHP side, and deliberately: the dependency **ranges** in `package.json` are what an application installs, while this repository's own toolchain has to be reproducible independently of what those ranges resolved to this morning. `engines` declares `node >= 20.19`, which is the floor the matrix starts at.

None of the frontend toolchain ships. `package.json`, the Vite config, the tsconfig and the lint configs are all `export-ignore`d, so `composer require` pulls none of them and an application never sees that lockfile.

## Frontend, latest dependencies

```yaml
frontend-latest:
  name: frontend (latest dependencies)
  continue-on-error: true

  steps:
    - run: npm install --no-package-lock
    - run: npm run typecheck
    - run: npm run build
```

A second opinion on the ranges, and the only job that can catch the failure applications actually hit: `package.json` says `^4.1.0` and the lockfile says 4.3.3, so `npm ci` never resolves the top of a range. This does.

It is allowed to fail. An upstream minor breaking us is news, not a reason to block a pull request that had nothing to do with it — and a red job that blocks nothing is still a red job somebody looks at.

## Reading a failure

| Symptom | Where to look |
| --- | --- |
| one `prefer-lowest` job red, `prefer-stable` green | a call to something added in a minor version the constraint does not require |
| every Laravel 13 job red | an API that changed between majors; check the `static-analysis` job for the other end |
| `static-analysis` red on one Laravel only | a version guard is missing, or is guarding the wrong way round |
| `frontend` red on Node 24 only | a dependency using something removed in that Node; the build step usually names it |
| `frontend-latest` red, `frontend` green | an upstream release; reproduce with `npm install --no-package-lock` |
| `code-style` red | run `composer format` and commit |

## Running the matrix locally

The `test` job is reproducible with two commands. Do it on a branch, and restore afterwards — `composer require` rewrites `composer.json`:

```bash
composer require "laravel/framework:12.*" --no-interaction --no-update
composer require "orchestra/testbench:10.*" --dev --no-interaction --no-update
composer update --prefer-lowest --prefer-dist --no-interaction

vendor/bin/pest

git checkout composer.json && composer update
```

For the frontend:

```bash
npm ci && npm run ci                       # what the blocking job runs
npm install --no-package-lock && npm run build   # what the non-blocking one runs
```

## For an application's own CI

An application testing a panel needs far less than this, because it pins one Laravel version in a lockfile. The useful parts to copy:

- **`fail-fast: false`** on any matrix at all.
- **A frontend job.** The panel's Vue components live in your `resources/js` after publishing, and `npm run build` is the only thing that checks they still compile against your starter kit. This is the seam that breaks on upgrade — see [Frontend contract tests](frontend-contract-tests.md).
- **`php artisan panel:assets`** in the pipeline, which reports which published files are behind and which the application has edited.
- **The sqlite and zip extensions**, if your tests exercise exports.

## Gotchas

- **`composer require` in CI edits `composer.json`.** It is `--no-update`, so nothing resolves until the single `composer update` — but a local reproduction leaves the file changed. Check it out afterwards.
- **`prefer-lowest` is the one that fails first on a new dependency.** A range added without a floor that actually works resolves to something years old.
- **The PHP jobs are not cached.** Resolving the ranges fresh is the point; a cache keyed on `composer.lock` would defeat it.
- **`npm ci` fails, rather than resolving, when the lockfile disagrees with `package.json`.** That is the intended behaviour: a change to a range without regenerating the lockfile is a change nobody has run.
- **`continue-on-error` still shows red.** `frontend-latest` failing does not block a merge and does still want reading.
- **Coverage is not in the workflow.** `composer test-coverage` exists locally; the setup-php step sets `coverage: none` in every job.

## See also

- [Test setup](setup.md) — the harness the `test` job runs
- [Frontend contract tests](frontend-contract-tests.md)
- [Compatibility matrix](../getting-started/compatibility.md) and [requirements](../getting-started/requirements.md)
- [Negative security tests](negative-security-tests.md)
- [`panel:assets`](../cli/panel-assets.md)
