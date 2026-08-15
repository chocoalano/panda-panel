# Testing a panel

The helpers ship with the package and are autoloaded, so a test needs no
import and no base class. They are the same ones this framework's own
thousand-test suite uses.

Every one goes through the real `TableSchema`, `TableQuery`, `FormSchema` and
`Action`. That is the rule they are written to and the reason to trust them: a
helper that computed its own idea of what a table shows would pass while the
table was broken. They are a nicer way to **ask**, never a second
implementation of the answer.

```php
panelTable(UserResource::class)->assertCanSeeRecord($user)->assertCount(2);
panelForm(UserResource::class)->assertFieldIsRequired('name');
panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
```

---

## Tables

`panelTable(Resource::class)` builds the table the list page builds. State is
set the way a URL sets it, so anything the helper can do a request can do —
and anything it cannot do, a user cannot either.

```php
panelTable(UserResource::class)
    ->filter(['verified' => TernaryFilter::FALSE])
    ->search('Grace')
    ->sort('name', 'asc')
    ->page(2)
    ->assertCanSeeRecord($grace)
    ->assertCanNotSeeRecord($ada)
    ->assertCount(1)
    ->assertRecordsInOrder([$ada, $grace])
    ->assertColumnExists('email')
    ->assertCellEquals($ada, 'email', 'ada@example.test');
```

| Method | Answers |
| --- | --- |
| `filter()` `search()` `sort()` `page()` | sets state, chainable, returns a new state |
| `records()` `keys()` `row($record)` | the rows as the frontend receives them |
| `assertCanSeeRecord()` / `assertCanNotSeeRecord()` | is this row reachable through the resource's scope |
| `assertCount()` `assertRecordsInOrder()` | how many, in what order |
| `assertCellEquals()` `assertColumnExists()` | one cell, one column |

`row()` and `assertCellEquals()` return the cell **as the frontend receives
it**, which matters for editable columns: a `TextInputColumn` or `ToggleColumn`
is `['value' => …, 'disabled' => …]`, not a scalar.

### What is worth asserting

Status codes prove very little. `assertOk()` on a list page passes for a page
showing every tenant's records.

- **Scope**, not presence: a record the resource hides must be *unreachable*,
  not merely absent from page one.
- **The serialized table holds no closures and no class names.** It crosses to
  the browser.
- **The list issues no query per row.** `assertDatabaseQueryCount()` around a
  render catches an N+1 that a passing page will not.

## Forms

```php
panelForm(UserResource::class)                 // the create form
panelForm(UserResource::class, 'edit')         // the edit form

panelForm(UserResource::class)
    ->assertHasField('email')
    ->assertDoesNotHaveField('password_confirmation')
    ->assertFieldIsRequired('name')
    ->assertDehydratesTo(
        ['name' => ' Ada ', 'unknown' => 'x'],
        ['name' => 'Ada'],
    );
```

`assertDehydratesTo()` is the one to reach for when a field transforms its
input, and it is also how you prove a schema **discards** what it never
declared — the second half of that example is the assertion that matters.

## Actions

Four scopes, and they are not interchangeable: an action declared as a row
action does not exist as a bulk action, however the request spells it.

```php
panelRecordActions(UserResource::class)     // one row
panelTableActions(UserResource::class)      // the table's header
panelBulkActions(UserResource::class)       // a selection
panelInfolistActions(UserResource::class)   // a record page
```

```php
panelRecordActions(UserResource::class)
    ->assertExists('impersonate')
    ->assertVisible('impersonate', $user)
    ->assertCanNotRun('impersonate', $otherUser);
```

`call()` **authorizes first and fails rather than skipping**. That is
deliberate: a helper that ran an action the user could not have run would be a
test proving the wrong thing.

## Notifications

```php
fakePanelNotifications();

// … do the thing …

assertPanelNotificationSentTo($user, 'Import failed');
assertNoPanelNotifications();

// Database notifications, which outlive the request that sent them.
assertPanelNotificationStoredFor($user, 'Export ready');
assertNoPanelNotificationsStoredFor($otherUser);
```

Sent and stored are separate questions. A notification can broadcast without
persisting, and the notification centre reads only what persisted.

## Tenancy

A tenant-scoped resource asked outside a tenant **raises** — that is the whole
design, not an inconvenience. Tests enter one explicitly:

```php
use PandaPanel\Tenancy\Tenancy;

$acme = Tenancy::for($acmeTeam, fn () => InvoiceResource::query()->pluck('number')->all());
$beta = Tenancy::for($betaTeam, fn () => InvoiceResource::query()->pluck('number')->all());

expect($acme)->not->toEqual($beta);
```

`Tenancy::for()` restores whatever was bound before, in a `finally`, so a
callback that throws does not leave the rest of the test scoped to somebody
else's tenant.

The assertion worth writing is the negative one: prove tenant A **cannot** see
tenant B's rows. A test that only checks A sees A's rows passes against a
completely unscoped query.

## Classes, not just functions

The free functions are wrappers. A test that would rather hold an object can:

```php
use PandaPanel\Testing\TestsTables;

$table = TestsTables::for(UserResource::class)->search('Ada');
```

`PandaPanel\Testing\TestsTables`, `TestsSchemas`, `TestsActions` and
`TestsNotifications` are all public.

## Overriding a helper

Every function is guarded by `function_exists`, so an application that defines
its own `panelTable()` before the package's autoloaded file runs keeps its own.
That is an escape hatch rather than a suggestion — a helper with your project's
name and different behaviour is a helper that confuses everyone who has read
these docs.
