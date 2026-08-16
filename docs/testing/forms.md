# Testing Forms

`panelForm()` asks a resource's form what it declares: which fields exist on a given page, what validates, and — the question most form bugs are hiding behind — what would actually be written. A form and an infolist are recursive structures, a field can be four layouts deep, so the useful questions are ones a test should not have to walk the tree to answer.

## A minimal working example

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('declares the fields the create form needs', function (): void {
    panelForm(UserResource::class)
        ->assertHasField('name')
        ->assertHasField('email')
        ->assertDoesNotHaveField('remember_token')
        ->assertFieldIsRequired('name');
});
```

## How it is wired

`TestsSchemas::schema()` builds the schema exactly as `CreateRecord`, `EditRecord` and `ViewRecord` do:

```php
$this->resource::form(
    FormSchema::make()->model($this->resource::getModel())->forPage($this->page),
);
```

`fields()` and `validationRules()` are the same methods the controller calls, so a field this finds is a field that validates and dehydrates.

## Choosing the page

```php
panelForm(UserResource::class)            // 'create' — the default
panelForm(UserResource::class, 'edit')
panelForm(UserResource::class, 'view')
```

The page name is the string `hiddenOn()` and `visibleOn()` are compared against, and `FormSchema::getPage()` is readable from inside a resource's own `form()` method — the example application uses it to make the password required on create and optional on edit:

```php
$isCreate = $schema->getPage() === 'create';
```

So the two pages are worth asserting separately whenever a schema branches on the page:

```php
it('requires a password when creating and not when editing', function (): void {
    panelForm(UserResource::class)->assertFieldIsRequired('password');

    expect(panelForm(UserResource::class, 'edit')->schema()->validationRules()['password'])
        ->not->toContain('required');
});
```

`create`, `edit` and `view` are the three the framework's own resource pages use. A custom page that sets `protected static string $page = 'wizard'` can be asked for by that name too.

## Every method

| Method | Signature | Returns |
| --- | --- | --- |
| `schema` | `schema(): FormSchema` | the built schema |
| `fieldNames` | `fieldNames(?Model $record = null): array` | `list<string>` — every visible field, flattened |
| `assertHasField` | `assertHasField(string $name, ?Model $record = null): self` | `$this` |
| `assertDoesNotHaveField` | `assertDoesNotHaveField(string $name, ?Model $record = null): self` | `$this` |
| `assertFieldIsRequired` | `assertFieldIsRequired(string $name, ?Model $record = null): self` | `$this` |
| `dehydrate` | `dehydrate(array $validated, ?Model $record = null): array` | the attributes that would be written |
| `assertDehydratesTo` | `assertDehydratesTo(array $validated, array $expected, ?Model $record = null): self` | `$this` |
| `infolistLabels` | `static infolistLabels(string $resource, Model $record): array` | `list<string>` — every infolist entry label |

Every one takes an optional `?Model $record`, because visibility can depend on the record being edited: `hiddenUsing()`, `visibleUsing()` and `rulesUsing()` are all handed it.

### `fieldNames()`

The flat list, in declaration order, with layouts unwrapped:

```php
expect(panelForm(UserResource::class)->fieldNames())
    ->toBe(['name', 'email', 'verified', 'is_admin', 'password']);
```

Two sections and a nested layout produce one list; a field hidden on this page is not in it.

### `assertHasField()` and `assertDoesNotHaveField()`

```php
panelForm(UserResource::class)
    ->assertHasField('email')
    ->assertDoesNotHaveField('two_factor_secret');
```

`assertDoesNotHaveField()` asserts twice: the name is not in `fieldNames()` **and** it is not a key in `validationRules()`. A field that is not there is not merely hidden — it is absent from the rules and from what dehydrates, so a request that sends it cannot make it exist. That is the property worth stating.

Pass a record when absence depends on one:

```php
$locked = User::factory()->create(['is_admin' => true]);

panelForm(UserResource::class, 'edit')->assertHasField('is_admin', $locked);
```

### `assertFieldIsRequired()`

Reads `validationRules()[$name]`, casts each rule to a string, and asserts `required` is among them:

```php
panelForm(UserResource::class)->assertFieldIsRequired('name');
```

Rule objects are stringified, so a `Rule::unique(...)` in the list does not break the comparison. For anything more specific than "required", assert on the rules directly:

```php
$rules = array_map(strval(...), panelForm(UserResource::class)->schema()->validationRules()['name']);

expect($rules)->toBe(['required', 'string', 'max:255']);
```

### `dehydrate()` and `assertDehydratesTo()`

Where most form bugs live: a field that validates and then does not persist, or one that persists under a different name.

```php
$written = panelForm(UserResource::class)->dehydrate([
    'name' => 'Ada',
    'email' => 'ada@example.test',
    'unknown' => 'discarded',
]);

expect($written)->toHaveKey('name')
    ->and($written)->not->toHaveKey('unknown');
```

`assertDehydratesTo()` asserts the whole payload with `assertSame`, which makes it the way to prove a schema **discards** what it never declared:

```php
panelForm(UserResource::class)->assertDehydratesTo(
    ['name' => 'Ada', 'unknown' => 'discarded'],
    ['name' => 'Ada'],
);
```

It is also how a rename is pinned down. The example application's `verified` toggle writes to `email_verified_at`:

```php
$written = panelForm(UserResource::class)->dehydrate([
    'name' => 'Ada',
    'verified' => true,
]);

expect($written)->toHaveKey('email_verified_at')
    ->and($written)->not->toHaveKey('verified');
```

Pass the record for the edit case, where a field can decline to write:

```php
$user = User::factory()->create();

$written = panelForm(UserResource::class, 'edit')
    ->dehydrate(['name' => 'Ada', 'password' => ''], $user);

// `optionalWhenFilled()` drops it rather than blanking the hash.
expect($written)->not->toHaveKey('password');
```

### `infolistLabels()`

A static method on the same class, exposed as `panelInfolistLabels()`:

```php
use PandaPanel\Testing\TestsSchemas;

$labels = TestsSchemas::infolistLabels(UserResource::class, $user);
// or: panelInfolistLabels(UserResource::class, $user);

expect($labels)->toContain('Two-factor');
```

It serializes the infolist for that record and walks `schema` and `tabs` recursively, collecting the label of every component whose `component` key is `entry`. Layout components are not entries and do not appear.

## Testing the endpoints

The helper covers the schema. The controller half is ordinary HTTP, and both halves are needed: a schema that declares the right fields does not prove the request that arrives is filtered by it.

```php
it('persists no attribute the create form never declared', function (): void {
    $this->post('/admin/users/create', [
        'name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',

        // None of these is a field on the form.
        'remember_token' => 'stolen-token',
        'two_factor_secret' => 'stolen-secret',
        'id' => 9999,
    ]);

    $user = User::query()->where('email', 'mallory@example.test')->firstOrFail();

    expect($user->remember_token)->not->toBe('stolen-token')
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->id)->not->toBe(9999);
});
```

Validation failures come back as session errors, because the panel's form pages are Inertia pages rather than JSON endpoints:

```php
$this->post('/admin/users/create', ['name' => 'Nameless'])
    ->assertSessionHasErrors();

$this->put("/admin/users/{$target->id}/edit", [
    'name' => $target->name,
    'email' => $other->email,
])->assertSessionHasErrors('email');
```

The routes, for reference: `POST /{panel}/{resource}/create` and `PUT /{panel}/{resource}/{record}/edit`.

## Gotchas

- **`password_confirmation` is a rule without a field.** `FormSchema::validationRules()` adds `['nullable', 'string']` under `{field}_confirmation` for a `PasswordInput::make(…)->confirmed()`. It is therefore in the rules and not in `fieldNames()`, which makes `assertDoesNotHaveField('password_confirmation')` **fail** — its second assertion is about the rules. Assert `expect(panelForm(...)->fieldNames())->not->toContain('password_confirmation')` instead.
- **A relation group namespaces its children.** `Relationship::make('profile')->schema([TextInput::make('name')])` beside a top-level `name` produces rule keys `name` and `profile.name`, not a duplicate-name error.
- **Duplicate field names throw.** `validationRules()` calls `assertUniqueFieldNames()`, so two fields with one name is a `PanelSchemaException` naming the field — raised the moment a test asks for the rules, however deeply nested the second declaration was.
- **`dehydrate()` does not validate.** It takes *already validated* input. Passing something the rules would have rejected tells you what the schema would write, not whether it would have got that far.
- **Element and nested rules have their own keys.** A list field validates its elements under `field.*`, and a repeater's children under `items.*.title`. Look for those keys rather than expecting everything under the field's own name.

## See also

- [Testing helpers](helpers.md) and [test setup](setup.md)
- [Forms overview](../forms/overview.md), [validation](../forms/validation.md), [hydration](../forms/hydration.md)
- [Visibility](../forms/visibility.md) — what `hiddenOn()` and `visibleOn()` are compared against
- [Infolists overview](../infolists/overview.md)
- [Negative security tests](negative-security-tests.md)
