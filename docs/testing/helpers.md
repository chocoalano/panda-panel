# Testing Helpers

Twelve free functions and the four classes behind them, shipped with the package and autoloaded, so a test needs no import and no base class. Reach for them when a question is about the panel rather than about HTTP: which rows a table would show, whether a field is required, whether an action is reachable, whether a user was notified. Every one goes through the real `TableSchema`, `TableQuery`, `FormSchema`, `InfolistSchema` and `Action` — they are a nicer way to **ask**, never a second implementation of the answer.

## A minimal working example

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada Lovelace']);

    $this->actingAs($this->admin);
});

it('shows the resource, its form and its actions to an administrator', function (): void {
    panelTable(UserResource::class)->assertCanSeeRecord($this->admin)->assertCount(1);
    panelForm(UserResource::class)->assertFieldIsRequired('name');
    panelRecordActions(UserResource::class)->assertExists('edit');
});
```

## Why they are functions

A trait would have to be used by every test class that wants one of them, and would read differently inside a Pest closure than inside a class-based test. Free functions read the same in both, and a test that wants one does not have to opt into all of them.

Each is guarded:

```php
if (! function_exists('panelTable')) {
    /**
     * @param  class-string<PanelResource>  $resource
     */
    function panelTable(string $resource): TestsTables
    {
        return TestsTables::for($resource);
    }
}
```

So an application that already has a `panelTable()` of its own keeps it. That is an escape hatch rather than a suggestion: a helper with your project's name and different behaviour confuses everyone who has read these docs.

## Every function

| Function | Signature | Returns |
| --- | --- | --- |
| `panelTable` | `panelTable(string $resource): TestsTables` | a table query builder and assertion object |
| `panelForm` | `panelForm(string $resource, string $page = 'create'): TestsSchemas` | the form for that page |
| `panelInfolistLabels` | `panelInfolistLabels(string $resource, Model $record): array` | `list<string>` — every entry label, however deeply nested |
| `panelRecordActions` | `panelRecordActions(string $resource): TestsActions` | the row-action scope |
| `panelTableActions` | `panelTableActions(string $resource): TestsActions` | the header, toolbar and empty-state scope |
| `panelBulkActions` | `panelBulkActions(string $resource): TestsActions` | the selection scope |
| `panelInfolistActions` | `panelInfolistActions(string $resource): TestsActions` | the record-page scope |
| `fakePanelNotifications` | `fakePanelNotifications(): void` | — starts recording broadcasts |
| `assertPanelNotificationSentTo` | `assertPanelNotificationSentTo(Authenticatable $user, ?string $title = null): void` | — |
| `assertNoPanelNotifications` | `assertNoPanelNotifications(): void` | — |
| `assertPanelNotificationStoredFor` | `assertPanelNotificationStoredFor(Authenticatable $user, ?string $title = null): void` | — |
| `assertNoPanelNotificationsStoredFor` | `assertNoPanelNotificationsStoredFor(Authenticatable $user): void` | — |

`$resource` is always a `class-string<PandaPanel\Resources\Resource>`.

### `panelTable()`

```php
use PandaPanel\Tables\Filters\TernaryFilter;

panelTable(UserResource::class)
    ->filter(['verified' => TernaryFilter::FALSE])
    ->search('Grace')
    ->sort('name', 'asc')
    ->page(1)
    ->assertCanSeeRecord($grace)
    ->assertCanNotSeeRecord($ada)
    ->assertCount(1);
```

State is set the way a URL sets it, so anything the helper can do a request can do — and anything it cannot do, a user cannot either. Full reference: [Testing tables](tables.md).

### `panelForm()`

```php
panelForm(UserResource::class)              // the create form
panelForm(UserResource::class, 'edit')      // the edit form
panelForm(UserResource::class, 'view')      // the view page's read-only schema

panelForm(UserResource::class)
    ->assertHasField('email')
    ->assertFieldIsRequired('name')
    ->assertDehydratesTo(['name' => 'Ada', 'unknown' => 'x'], ['name' => 'Ada']);
```

The page name is what `hiddenOn()` and `visibleOn()` are compared against; `create`, `edit` and `view` are the three the framework's own pages use. Full reference: [Testing forms](forms.md).

### `panelInfolistLabels()`

```php
$labels = panelInfolistLabels(UserResource::class, $this->admin);

expect($labels)->toContain('Two-factor')
    ->and($labels)->toContain('Account summary');
```

It serializes the infolist for that record and walks the tree, descending into `schema` and `tabs`, collecting the `label` of every component whose `component` key is `entry`. A layout is not an entry and does not appear; nor do the children of a repeatable, which belong to an item rather than to the record.

### The four action scopes

```php
panelRecordActions(UserResource::class)     // one row
panelTableActions(UserResource::class)      // header, toolbar, empty state
panelBulkActions(UserResource::class)       // a selection
panelInfolistActions(UserResource::class)   // a record page
```

They are not interchangeable: an action declared as a row action does not exist as a bulk action, however the request spells it. Full reference: [Testing actions](actions.md).

### The notification assertions

```php
fakePanelNotifications();

Notification::make('saved')->title('Saved.')->send($this->admin);

assertPanelNotificationSentTo($this->admin, 'Saved.');
assertNoPanelNotificationsStoredFor($this->admin);
```

Sent and stored are separate questions. Full reference: [Testing notifications](notifications.md).

## The classes

The free functions are thin wrappers. A test that would rather hold an object can:

```php
use PandaPanel\Testing\TestsActions;
use PandaPanel\Testing\TestsNotifications;
use PandaPanel\Testing\TestsSchemas;
use PandaPanel\Testing\TestsTables;

$table = TestsTables::for(UserResource::class)->search('Ada');

$form = TestsSchemas::form(UserResource::class, 'edit');
$labels = TestsSchemas::infolistLabels(UserResource::class, $record);

$rowActions = TestsActions::record(UserResource::class);
$tableActions = TestsActions::table(UserResource::class);
$bulkActions = TestsActions::bulk(UserResource::class);
$infolistActions = TestsActions::infolist(UserResource::class);

TestsNotifications::fake();
TestsNotifications::assertSentTo($user, 'Export ready');
TestsNotifications::assertNothingSent();
TestsNotifications::assertStoredFor($user, 'Export ready');
TestsNotifications::assertNothingStoredFor($user);
```

| Free function | Class method |
| --- | --- |
| `panelTable($r)` | `TestsTables::for($r)` |
| `panelForm($r, $page)` | `TestsSchemas::form($r, $page)` |
| `panelInfolistLabels($r, $record)` | `TestsSchemas::infolistLabels($r, $record)` |
| `panelRecordActions($r)` | `TestsActions::record($r)` |
| `panelTableActions($r)` | `TestsActions::table($r)` |
| `panelBulkActions($r)` | `TestsActions::bulk($r)` |
| `panelInfolistActions($r)` | `TestsActions::infolist($r)` |
| `fakePanelNotifications()` | `TestsNotifications::fake()` |
| `assertPanelNotificationSentTo()` | `TestsNotifications::assertSentTo()` |
| `assertNoPanelNotifications()` | `TestsNotifications::assertNothingSent()` |
| `assertPanelNotificationStoredFor()` | `TestsNotifications::assertStoredFor()` |
| `assertNoPanelNotificationsStoredFor()` | `TestsNotifications::assertNothingStoredFor()` |

`TestsTables`, `TestsSchemas` and `TestsActions` are `final` with private constructors: they are built through the named constructors above and nothing else. `TestsNotifications` is entirely static.

## Reaching the schema underneath

Both schema-backed helpers expose the object they built, for a question no assertion covers:

```php
use PandaPanel\Tables\TableSchema;
use PandaPanel\Forms\FormSchema;

$table = panelTable(UserResource::class)->schema();      // TableSchema
$form = panelForm(UserResource::class)->schema();        // FormSchema

expect($table->getColumn('email'))->not->toBeNull()
    ->and(array_keys($form->validationRules()))->toContain('email');
```

`TestsTables::schema()` builds a fresh `TableSchema` on every call, because a schema holds resolved state and reusing one across assertions would make each depend on the last. `TestsSchemas::schema()` does the same, and constructs it exactly as the resource pages do:

```php
$this->resource::form(
    FormSchema::make()->model($this->resource::getModel())->forPage($this->page),
);
```

## What the helpers do not do

Say plainly what is absent rather than reaching for it:

- **No `perPage()`.** `TestsTables` sets `filters`, `search`, `sort`, `direction` and `page`. Page size, column visibility, grouping and column-level searches are set by a request; make one.
- **No panel argument.** Every helper reads the *current* panel through `panel()`. A resource registered in two panels with different per-panel configuration is asked about whichever panel is current — see [Test setup](setup.md#panel-context-outside-a-request).
- **No relation-manager helper.** A relation manager is tested through its endpoints, or by calling its static methods directly.
- **No widget helper.** `PandaPanel\Pages\WidgetCollection::for([...])->definitions()` is the equivalent, and it is public.
- **No page helper.** `Page::canAccess()` and a `get()` on the page's route cover it. See [Testing authorization](authorization.md).

## Gotchas

- **They ask, they do not simulate.** `panelTable()->records()` runs `Resource::query()` through `TableQuery`. If the resource's scope is broken, the helper is broken in exactly the same way — which is the point.
- **`row()` serializes the model you hand it.** A column backed by an aggregate (`->counts('passkeys')`) reads `null` for a bare `User::factory()->create()` and its real value for a record taken from `records()`, because only the second was loaded through the table's query.
- **`call()` authorizes first.** It fails the test rather than skipping when the current user may not run the action.
- **`assertDoesNotHaveField()` also checks the rules.** A confirmed password field adds a `_confirmation` key to `validationRules()` without adding a field, so `assertDoesNotHaveField('password_confirmation')` fails on a resource with `PasswordInput::make('password')->confirmed()`.
- **Notifications need the fake first.** `fakePanelNotifications()` installs `Event::fake([PanelNotificationSent::class])`, which only records what is dispatched afterwards.

## See also

- [Test setup](setup.md)
- [Testing tables](tables.md), [forms](forms.md), [actions](actions.md), [notifications](notifications.md)
- [Testing tenancy](tenancy.md) and [authorization](authorization.md)
- [Infolists overview](../infolists/overview.md)
- [Tables API](../tables/api.md), [Resources API](../resources/api.md)
