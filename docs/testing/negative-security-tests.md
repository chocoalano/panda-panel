# Negative Security Tests

Ten files under `tests/Feature/Panel/Negative/`, stating as things that **must not happen** the properties the rest of the suite states positively. A panel is a large public surface — every table parameter reaches a query builder, every action endpoint takes a record key from a payload, every export writes a file addressed by name — and the guarantees that surface rests on are only guarantees if something fails when they break. Reach for this page when adding a resource, an endpoint, or a file download of your own.

## A minimal working example

The shape most of these take: a hostile parameter, and an assertion that the result set is the one an honest request would have received.

```php
<?php

declare(strict_types=1);

use App\Models\User;

/**
 * @return list<string>
 */
function rowNamesFor(string $query): array
{
    $page = test()->get('/admin/users?'.$query)->viewData('page');

    return collect($page['props']['rows'] ?? [])
        ->pluck('cells.name.value')
        ->filter()
        ->values()
        ->all();
}

it('ignores a filter name the schema never declared', function (): void {
    User::factory()->count(3)->create();

    $this->actingAs(User::factory()->create(['is_admin' => true]));

    expect(rowNamesFor('filters[password]=secret'))->toHaveCount(4);
    expect(User::query()->count())->toBe(4);
});
```

Two assertions, and both matter: the rows are unchanged, and the table is still there. A successful injection would fail the second.

## The ten files

| File | States that |
| --- | --- |
| `HostileTableInputTest` | a sort, filter, group, search or page parameter the schema never declared is ignored, not escaped |
| `PrivilegeEscalationTest` | no guessed URL, hand-written POST or swapped id gets past a policy |
| `ScopeBypassTest` | a record `Resource::query()` excludes is unreachable through every endpoint that takes a key |
| `SchemaEscapeTest` | a create or edit writes only what the form declared |
| `MalformedInputTest` | input of the wrong shape is answered, never crashed into |
| `FileAndDataAccessTest` | an export, import report or notification belongs to exactly one user |
| `SpreadsheetFormulaTest` | a CSV cell cannot become a formula in the reader's spreadsheet |
| `SchemaMistakeTest` | a schema that cannot mean what it says refuses at build time, and says which name is wrong |
| `UnreachableDeclarationTest` | a declaration pointing at something that cannot answer fails with a message naming it |
| `SilentAbsenceTest` | "my resource is missing" has a cause somebody can read |

Three of these guards were verified by deleting them and confirming the suite fails. That is the standard worth holding a security test to: one that passes with the guard removed is decorative.

## Hostile table input

Every table parameter arrives from the URL and reaches a query builder. The rule is that a name the schema did not declare **does not exist** — not "is escaped", not "is quoted", but is refused or ignored outright.

The row count cannot see a sort — three rows are three rows in any order — so the assertion reads the applied state instead, which the table reports back:

```php
function appliedSortFor(string $query): ?string
{
    $page = test()->get('/admin/users?'.$query)->viewData('page');

    return $page['props']['state']['sort'] ?? null;
}

it('treats a SQL fragment in the sort parameter as a name, not as SQL', function (): void {
    $fragments = [
        'name; drop table users',
        'name) or 1=1--',
        '(select count(*) from users)',
        'name`,`email',
        'users.name',
        '1',
    ];

    foreach ($fragments as $fragment) {
        expect(appliedSortFor('sort='.urlencode($fragment)))
            ->toBeNull("sort={$fragment} was accepted as a column");

        expect(rowNamesFor('sort='.urlencode($fragment)))
            ->toHaveCount(3, "sort={$fragment} changed the result set");
    }

    expect(User::query()->count())->toBe(3);
});
```

The whitelist has to be shown to be a whitelist rather than a parameter that never works:

```php
// `avatar` and `accountAge` are real columns and neither is ->sortable().
expect(appliedSortFor('sort=avatar'))->toBeNull();
expect(appliedSortFor('sort=accountAge'))->toBeNull();

// And the one that is sortable is honoured.
expect(appliedSortFor('sort=name'))->toBe('name');
```

The rest of the surface, each with its own test: an unrecognised `direction` falls back to ascending rather than reaching the builder; `perPage` clamps for `999999`, `-1`, `0`, `all` and `10; drop table users`; a negative or absurd `page` answers 200; a ternary value outside the three it accepts leaves the query alone; a query-builder rule naming a constraint or an operator that was never offered is refused; `%` and `_` in a search term are literal characters rather than a way to dump the table; a `group` or a `columns[]` naming something that does not exist is ignored.

The last test throws everything at once and listens to the database:

```php
use Illuminate\Support\Facades\DB;

it('issues no query that the database refuses', function (): void {
    $failures = [];

    DB::listen(function ($query) use (&$failures): void {
        if (str_contains(strtolower($query->sql), 'drop table')) {
            $failures[] = $query->sql;
        }
    });

    test()->get('/admin/users?'.http_build_query([
        'sort' => 'name; drop table users',
        'direction' => 'desc; drop table users',
        'search' => "'; drop table users--",
        'group' => 'name; drop table users',
        'perPage' => '10; drop table users',
        'filters' => ['is_admin' => '1; drop table users'],
    ]))->assertOk();

    expect($failures)->toBeEmpty();
});
```

A parameter that reached the builder as SQL shows up here as a fragment inside the statement rather than as a binding.

## Privilege escalation

Every one of these is a way somebody might get past the policy without ever pressing a button the interface offered.

```php
// Reading somebody else's record.
$this->actingAs($member)->get("/admin/users/{$other->id}")->assertForbidden();
$this->actingAs($member)->get("/admin/users/{$other->id}/edit")->assertForbidden();
$this->actingAs($member)->get('/admin/users')->assertForbidden();

// Writing it, three ways.
$this->actingAs($member)->put("/admin/users/{$other->id}/edit", [...])->assertForbidden();
$this->actingAs($member)->post('/admin/actions/record', [
    'resource' => 'users', 'action' => 'delete', 'record' => $other->id,
])->assertForbidden();
$this->actingAs($member)->post('/admin/actions/cell', [
    'resource' => 'users', 'column' => 'name', 'record' => $other->id, 'value' => 'x',
])->assertForbidden();
```

The interesting half is the privilege flag. The example policy permits a member to edit their own record, which is correct for a display name and catastrophic for `is_admin` — so there is a test for each of the three routes to that flag, and one of them reaches a guard nothing else does:

```php
it('does not let an administrator toggle the flag on their own account', function (): void {
    // An administrator is admitted to the panel and the policy lets them edit
    // themselves, so the column's `disabledUsing()` is the only thing left.
    $this->actingAs($this->admin)->post('/admin/actions/cell', [
        'resource' => 'users',
        'column' => 'is_admin',
        'record' => $this->admin->id,
        'value' => false,
    ]);

    expect($this->admin->fresh()->is_admin)->toBeTrue();
});
```

Then the cross-panel cases, which are 404 rather than 403 — the resource is not registered in that panel, so there is nothing there to be refused:

```php
$this->actingAs($this->admin)->get('/app/users')->assertNotFound();

$this->actingAs($this->admin)->post('/app/actions/record', [
    'resource' => 'users', 'action' => 'delete', 'record' => $other->id,
])->assertNotFound();
```

And guests, at every endpoint: a redirect from the HTML routes, 401 from the JSON ones.

## Scope bypass

A resource that narrows `query()` — to a tenant, a team, a "not archived" — relies on every route to a record going through the same narrowing. One that did not would undo the whole point of the scope: the list shows what you own, and a guessed id shows what you do not.

Every endpoint that takes a record key must answer **404**, because outside the scope the record does not exist as far as this resource is concerned:

```php
$this->get("/scope-host/scoped-users/{$outside->id}")->assertNotFound();
$this->get("/scope-host/scoped-users/{$outside->id}/edit")->assertNotFound();
$this->put("/scope-host/scoped-users/{$outside->id}/edit", [...])->assertNotFound();

$this->post('/scope-host/actions/record', [
    'resource' => 'scoped-users', 'action' => 'delete', 'record' => $outside->id,
])->assertNotFound();

$this->post('/scope-host/actions/cell', [
    'resource' => 'scoped-users', 'column' => 'name', 'record' => $outside->id, 'value' => 'x',
])->assertNotFound();
```

Global search is a second way to reach a record by name, so it obeys the same narrowing:

```php
$results = json_encode($this->getJson('/scope-host/search?q=Scope')->json());

expect($results)->toContain('In Scope')->and($results)->not->toContain('Out Of Scope');
```

Close the file with the control, or a broken route would pass every assertion above:

```php
it('reaches a record inside the scope, so the refusals above are the scope and not a broken route', function (): void {
    $this->get("/scope-host/scoped-users/{$inside->id}")->assertOk();
});
```

## Schema escape

A panel form is a whitelist: the schema names the fields, and what arrives under any other name is not a field. That property is the only thing between a create form and every column on the model.

```php
it('persists no attribute the create form never declared', function (): void {
    $this->post('/admin/users/create', [
        'name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',

        'remember_token' => 'stolen-token',
        'two_factor_secret' => 'stolen-secret',
        'two_factor_confirmed_at' => now()->toDateTimeString(),
        'id' => 9999,
    ]);

    $user = User::query()->where('email', 'mallory@example.test')->firstOrFail();

    expect($user->remember_token)->not->toBe('stolen-token')
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->id)->not->toBe(9999);
});
```

Pick the columns that matter — a password hash, a remember token, a two-factor secret, a verification timestamp. Each is on the table and none is in the form.

The editable-cell endpoint is a form of its own and gets the same treatment: a write to a non-editable column is **400**, and a value failing the column's own rules is **422**.

```php
// `email` is a TextColumn, not a TextInputColumn.
$this->post('/admin/actions/cell', [
    'resource' => 'users', 'column' => 'email', 'record' => $target->id,
    'value' => 'hijacked@example.test',
])->assertStatus(400);

// `name` declares required and maxLength(255).
$this->postJson('/admin/actions/cell', [
    'resource' => 'users', 'column' => 'name', 'record' => $target->id, 'value' => '',
])->assertStatus(422);
```

## Malformed input

Not an attack so much as the ordinary state of a public endpoint. What matters is the **class** of answer: a 4xx is the endpoint saying no; a 500 leaks a stack trace, fills a log, and is something an attacker can cause on demand.

```php
/**
 * Every status that means "the endpoint decided", as opposed to "the endpoint
 * broke". A redirect counts.
 *
 * @return list<int>
 */
function answeredStatuses(): array
{
    return [200, 201, 204, 302, 400, 401, 403, 404, 405, 409, 422, 429];
}

it('answers rather than breaks on a malformed record action payload', function (): void {
    $payloads = [
        [],
        ['resource' => 'users'],
        ['resource' => ['users'], 'action' => 'delete', 'record' => 1],
        ['resource' => 'users', 'action' => 'delete', 'record' => null],
        ['resource' => 'users', 'action' => 'delete', 'record' => ['a' => 'b']],
        ['resource' => 'users', 'action' => 'delete', 'record' => str_repeat('9', 500)],
        ['resource' => str_repeat('a', 5000), 'action' => 'delete', 'record' => 1],
        ['resource' => "users\0", 'action' => 'delete', 'record' => 1],
    ];

    foreach ($payloads as $index => $payload) {
        $status = $this->post('/admin/actions/record', $payload)->getStatusCode();

        expect($status)->toBeIn(answeredStatuses(), "record payload #{$index} produced {$status}");
    }
});
```

The same table for the bulk, cell, options, search, notification and upload endpoints; for record keys that are not record keys (`abc`, `0`, `-1`, `1e400`, `../../etc/passwd`, `%00`, four hundred nines); and for verbs the routes do not offer.

Uploads get two tests that are about content rather than shape, and one of them cannot be written with `UploadedFile::fake()`:

```php
it('refuses a PHP script wearing a png extension', function (): void {
    Storage::fake('public');

    // A *real* uploaded file: the fake reports its type from the file name,
    // so it would answer image/png for anything called .png and this test
    // would pass without testing anything.
    $path = tempnam(sys_get_temp_dir(), 'panda').'.png';
    file_put_contents($path, "<?php echo 'hi'; ?>\n");

    $file = new UploadedFile($path, 'payload.png', null, null, test: true);

    expect($file->getMimeType())->not->toBe('image/png');

    $this->postJson(uploadUrl(), [
        'resource' => 'form-fixtures',
        'field' => 'attachment',
        'file' => $file,
    ])->assertStatus(422);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();

    @unlink($path);
});
```

## File and cross-user data access

An export is a copy of every record the exporter could see, written to disk and left there. Both halves of the design need a test: a path cannot be smuggled through the file name, and a name cannot reach another user's directory.

```php
it('refuses every shape of traversal in an export file name', function (): void {
    // Both rungs a traversal would climb to. Without these the directories do
    // not exist, every attempt misses for the wrong reason, and the test
    // passes with the guard deleted.
    Storage::disk('local')->put('secret.csv', 'ROOT SECRET');
    Storage::disk('local')->put(UserExporter::directory().'/secret.csv', 'PARENT SECRET');

    $attempts = [
        '../secret.csv', '../../secret.csv', '..%2Fsecret.csv', '%2e%2e%2fsecret.csv',
        '....//secret.csv', '..%5Csecret.csv', '/etc/passwd', 'subdir/secret.csv',
        '.', '..',
    ];

    foreach ($attempts as $attempt) {
        $response = $this->get('/admin/exports/'.$attempt.'?exporter='.urlencode(UserExporter::class));

        expect($response->getStatusCode())->toBeIn([403, 404]);
    }
});
```

Planting the decoy files is the part that is easy to skip and the part that makes the test real. `.` and `..` are in the list because they reach the controller as a name rather than being normalised away by the router — without the guard they are a 500.

Then the cross-user cases, and a control that proves the endpoint works at all:

```php
Storage::disk('local')->put(UserExporter::directory().'/'.$other->id.'/report.csv', 'name,email');

// The name is right; the directory it lives in is not this user's.
$this->actingAs($this->admin)
    ->get('/admin/exports/report.csv?exporter='.urlencode(UserExporter::class))
    ->assertNotFound();
```

The `exporter` parameter names a class, so it gets its own test: `User::class`, a facade, a class that does not exist and an empty string all answer 404.

Notifications are scoped by query rather than by policy, which makes them worth the same treatment — one user cannot list, read or clear another's:

```php
$this->actingAs($this->admin)
    ->postJson('/admin/notifications/read', ['id' => $notification->getKey()])
    ->assertOk();

// Matched nothing rather than 403'd — the same outcome, one fewer leak.
expect($other->unreadNotifications()->count())->toBe(1);
```

## Spreadsheet formulas

A CSV cell beginning with `=`, `+`, `-` or `@` is a formula as far as Excel, LibreOffice and Sheets are concerned, and they evaluate it when the file is opened. The attacker is anyone who can write a text field; the victim is the administrator who opens the export. CWE-1236.

```php
use PandaPanel\Support\Spreadsheet\Csv;

it('neutralizes every character a spreadsheet reads as a formula', function (): void {
    foreach (['=', '+', '-', '@', "\t", "\r"] as $prefix) {
        expect(Csv::neutralize($prefix.'SUM(A1)'))->toStartWith("'");
    }
});

it('leaves ordinary text exactly as it was', function (): void {
    foreach (['Apollo', '2026-08-15', 'a=b', '', '0'] as $value) {
        expect(Csv::neutralize($value))->toBe($value);
    }
});
```

Quoting is about parsing the file and does nothing about what the cell means once parsed, which is the whole of the problem — so the test reads the cell back through `fgetcsv()` and asserts the apostrophe survived. `xlsx` needs none of this and has a test saying why: every cell is written as `t="inlineStr"` and the writer never emits an `<f>` element.

## Schema mistakes and silent absences

The last three files are about failures that used to be invisible rather than about attacks. They are in this directory because the shape is the same — something that must not happen, stated as a test.

`SchemaMistakeTest` asserts on the **message** as much as on the throw, because a refusal that does not say which name is wrong leaves somebody reading a resource with forty columns:

```php
expect(fn () => TableSchema::make()->columns([
    TextColumn::make('name'),
    TextColumn::make('email'),
    TextColumn::make('name'),
]))->toThrow(PanelSchemaException::class, 'more than one column named [name]');

expect(fn () => Action::make('send invoice'))
    ->toThrow(PanelSchemaException::class, 'try [send-invoice]');

expect(fn () => TableSchema::make()
    ->columns([TextColumn::make('name'), TextColumn::make('created_at')])
    ->defaultSort('createdAt')
    ->toArray())->toThrow(PanelSchemaException::class, 'It has: name, created_at');
```

Beside each refusal is a test for the thing that must **not** be refused — a relation group repeating a name the owner also uses, the same action name in two different sets, a filter on a column the table does not display, a column span merely out of range. Without those, a stricter check would be an improvement in one direction only.

`SilentAbsenceTest` covers the two causes of "my resource is missing" that are mistakes rather than decisions: no policy at all, and a cached manifest that predates the resource.

## Writing them for your own resource

The checklist a new resource is worth, in the order the failures actually happen:

- Unauthorized access returns 403 on **every** route, including the write verbs and the action endpoint.
- Search matches only whitelisted columns.
- An unknown or non-sortable sort column is ignored, and the applied state says so.
- `perPage` clamps; an invalid filter value is rejected.
- Records resolve through `Resource::query()`, so an out-of-scope key 404s — on the view page, the edit page, the edit submission, the record action, the bulk action, the cell endpoint and global search.
- Create validates and persists only declared fields.
- Update leaves an untouched password alone.
- Delete authorizes per record; a bulk selection containing one forbidden record changes nothing.
- The serialized table and form contain no closures and no class names.
- The list route issues no query per row.

Two rules while writing them. **Assert behaviour, not status codes** — `assertOk()` alone proves very little, and a 200 on a list page is exactly what a leaking scope looks like. And **prove the guard is load-bearing**: delete it, run the test, confirm it fails. A negative test that passes with the guard removed is a test of something else.

## Gotchas

- **Plant the decoys.** A traversal test against directories that do not exist passes for the wrong reason.
- **A control test belongs in every file.** "…so the refusals above are the scope and not a broken route."
- **Percent-encode what the HTTP layer would reject.** A raw backslash is refused before the application sees it, which is a different guard than the one under test.
- **`UploadedFile::fake()` lies about mime types.** It reports from the file name. Any test about file *content* needs a real `UploadedFile`.
- **Loop with a message.** `expect($status)->toBeIn(answeredStatuses(), "payload #{$index} produced {$status}")` — a loop that fails without saying which iteration is a debugging session.
- **404 versus 403 is a decision.** Out of scope is 404 because the record does not exist for that resource; refused by policy is 403. Swapping them either leaks existence or hides a bug.

## See also

- [Testing authorization](authorization.md) and [tenancy](tenancy.md)
- [Testing tables](tables.md) — the positive half of the same surface
- [Search security](../search/security.md)
- [Tenancy security checklist](../tenancy/security-checklist.md)
- [File uploads](../forms/file-uploads.md)
- [Channel authorization](../notifications/channel-authorization.md)
