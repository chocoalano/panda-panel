# InfolistSchema Basics

`PandaPanel\Infolists\InfolistSchema` is a record's read-only presentation: what the view page shows, how it is grouped, and which operations are offered beside it. You reach for it when a resource's view page should show more — or less — than the form happens to declare.

It is deliberately separate from `FormSchema` rather than a mode of it. A form validates, dehydrates, and hides fields per page; an infolist does none of that. Sharing one class would mean every entry carrying rules it can never use, and a view page quietly depending on what the edit form declares. The password on the user view page is *absent* rather than filtered — an infolist that never reads it cannot leak it.

## A minimal infolist

A resource declares one by overriding `infolist()`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Enums\BadgeColor;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return $schema->columns(2)->schema([
            Section::make('Account')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                BadgeEntry::make('status')->colors(['active' => BadgeColor::Success]),
                BooleanEntry::make('email_verified_at')->labels('Verified', 'Unverified'),
                DateTimeEntry::make('created_at')->since(),
            ]),
        ]);
    }

    // table(), form() and pages() omitted
}
```

Nothing else is needed. `/admin/users/{record}` now renders the infolist instead of the form-derived fallback.

## Opting in

`Resource::infolist()` returns the schema unchanged by default:

```php
public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return $schema;
}
```

An untouched schema is empty, and `ViewRecord` falls back to deriving entries from `Resource::form()` — one label-and-value row per form field, with `PasswordInput` fields skipped. So adding an infolist is an improvement a resource opts into rather than a migration it is forced through, and the two paths never both render:

| `Resource::infolist()` | What the view page renders |
| --- | --- |
| Left as the default (empty) | `entries` — labels and stringified values derived from the form |
| Returns a non-empty schema | `infolist` — the full component tree |

`isEmpty()` is what decides:

```php
use PandaPanel\Infolists\InfolistSchema;

InfolistSchema::make()->isEmpty();                                // true
UserResource::infolist(InfolistSchema::make())->isEmpty();        // false
```

Nothing generates an infolist for you. `php artisan make:panel-resource` scaffolds a table, a form, and pages; the infolist is written by hand when the view page needs one.

## `InfolistSchema`, method by method

The class is `final`. Every method returns `$this` unless the signature says otherwise.

| Method | Signature | What it does |
| --- | --- | --- |
| `make()` | `static make(): self` | A new, empty schema. One column, no components, no actions |
| `schema()` | `schema(array $components): self` | Replaces the top-level components. Re-indexed with `array_values()` |
| `columns()` | `columns(int $columns): self` | Divides the root grid. Clamped to 1–4 by `ColumnCount::clamp()` |
| `actions()` | `actions(array $actions): self` | Operations for the record as a whole, rendered above the infolist |
| `allActions()` | `allActions(): array<string, Action>` | Every action the schema declares, wherever it sits — the endpoint's whitelist |
| `getAction()` | `getAction(string $name): ?Action` | One action by name, or null |
| `getComponents()` | `getComponents(): list<InfolistComponent>` | The top-level components, for a caller merging two schemas |
| `entries()` | `entries(): list<Entry>` | Every entry, flattened out of the layouts |
| `isEmpty()` | `isEmpty(): bool` | Whether `schema()` was ever given anything |
| `toArray()` | `toArray(Model $record): array` | `['columns' => int, 'schema' => list, 'actions' => list]` — what crosses the wire |

Used outside a resource, the whole cycle is four lines:

```php
use App\Models\User;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;

$schema = InfolistSchema::make()
    ->columns(2)
    ->schema([TextEntry::make('name'), TextEntry::make('email')]);

$definition = $schema->toArray(User::query()->firstOrFail());
// ['columns' => 2, 'schema' => [['component' => 'entry', 'name' => 'name', ...], ...], 'actions' => []]
```

### `columns()`

The root grid. Entries declared outside any layout are laid into it; layouts always take the whole row.

```php
$schema->columns(3);      // three columns
$schema->columns(9);      // clamped to 4
```

Four is the maximum because `resources/js/panel/lib/grid.ts` has literal Tailwind classes for one through four. An interpolated `grid-cols-${n}` is invisible to the Tailwind compiler and would not exist in the bundle at all, so a schema asking for six would silently render as one. Clamping means what the renderer draws is always what the schema said.

The ladder widens with the viewport: always one column on a phone, never more than two at `md`, and three or four only at `lg`. A span wider than the container is clamped at each breakpoint rather than overflowing it.

### `schema()`

Takes anything extending `PandaPanel\Infolists\Components\InfolistComponent` — an entry or a layout, in any mix:

```php
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Layouts\Section;

$schema->schema([
    TextEntry::make('reference'),                                   // loose entry
    Section::make('Customer')->schema([TextEntry::make('email')]),  // layout
]);
```

Loose entries are collected into one card by the renderer rather than each getting a box of its own, so a flat infolist reads as one panel. Layouts follow below it, in declaration order.

### `entries()`

Every entry in the tree, however deeply nested, in declaration order:

```php
$schema = InfolistSchema::make()->schema([
    TextEntry::make('name'),
    Section::make('Nested')->schema([
        Section::make('Deeper')->schema([TextEntry::make('email')]),
    ]),
]);

array_map(static fn ($entry): string => $entry->getName(), $schema->entries());
// ['name', 'email']
```

A `RepeatableEntry` counts as one entry rather than as its children: they belong to an item, not to the record. See [Repeatable entries](repeatable-entries.md).

### `actions()` and `allActions()`

`actions()` declares operations for the record as a whole. `allActions()` collects those *plus* every section header action, every entry action, and every action registered on another action's dialog — keyed by name:

```php
use PandaPanel\Actions\Action;

$schema = InfolistSchema::make()
    ->actions([Action::make('approve')])
    ->schema([
        Section::make('Details')
            ->headerActions([Action::make('resend')])
            ->schema([TextEntry::make('name')->action(Action::make('rename'))]),
    ]);

array_keys($schema->allActions());   // ['approve', 'resend', 'rename']
```

That map is the whitelist `PandaPanel\Http\Controllers\PanelActionController::infolist()` resolves against — a *different* whitelist from the table's, so an action shown on a view page cannot be run from a list that never offered it. See [Actions in infolists](actions.md).

## What the view page does with it

`PandaPanel\Resources\Pages\ViewRecord::render()` builds the schema fresh per request, serializes it against the resolved record, and hands Inertia both halves:

```php
$infolist = static::$resource::infolist(InfolistSchema::make());

return Inertia::render('panel/resources/View', [
    'infolist' => $infolist->isEmpty() ? null : $infolist->toArray($model),
    'entries' => $infolist->isEmpty() ? $this->entries($model) : [],
    'recordKey' => $model->getKey(),
    'actionEndpoints' => $this->actionEndpoints(),
    // page, resource, relations and widgets omitted
]);
```

`resources/js/pages/panel/resources/View.vue` renders `InfolistRenderer` when `infolist` is non-null and the fallback list otherwise. The record is resolved through `Resource::query()` like every other lookup, so a record outside the scope is a 404 rather than something the view page can reach around.

## What crosses the wire

```php
[
    'columns' => 2,
    'schema' => [
        ['component' => 'entry', 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Grace Hopper', ...],
        ['component' => 'section', 'heading' => 'Account', 'columns' => 2, 'schema' => [...], 'headerActions' => []],
    ],
    'actions' => [
        ['name' => 'approve', 'label' => 'Approve', 'type' => 'callback', ...],
    ],
]
```

Four `component` discriminants exist — `entry`, `section`, `grid`, `tabs` — and `type` discriminates the eleven entry types below `entry`. The TypeScript mirror is `resources/js/panel/types/infolist.ts`; the renderer's switch is exhaustive over that union, so a PHP entry type with no branch is a compile error.

Nothing executable ever crosses. `formatUsing()`, `visible()`, `state()` and every action handler are evaluated on the server and only their outcome is serialized:

```php
$encoded = json_encode($schema->toArray($record));

// Never contains 'Closure'.
```

## Organizing a large infolist

An infolist that fills a screen does not belong inline in the resource. The convention the examples follow is a class per schema, beside the form and the table:

```text
app/Panels/Admin/Resources/Users/
├── UserResource.php
├── Forms/UserForm.php
├── Infolists/UserInfolist.php
└── Tables/UsersTable.php
```

```php
public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return UserInfolist::configure($schema);
}
```

`configure()` is a plain static method taking and returning the schema. Nothing in the framework requires the split — `infolist()` is the only contract — but it keeps the resource readable and lets a test build the schema without a page.

## Styling

The rendered infolist carries the `panel-infolist` class, and a panel may add its own through the `infolist` CSS hook:

```php
$panel->cssHooks(['infolist' => 'my-infolist']);
```

The hook names are an allowlist; `infolist` is one of them. See [CSS hooks](../frontend/css-hooks.md).

## Testing

Two helpers ask the schema what it declares, through the same lookups the page and the endpoint use:

```php
use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;

it('shows what the view page should show', function (): void {
    $record = User::factory()->create();

    expect(panelInfolistLabels(UserResource::class, $record))
        ->toContain('Email')
        ->not->toContain('Password');

    panelInfolistActions(UserResource::class)->assertExists('note');
});
```

`panelInfolistLabels()` walks the serialized tree and returns every entry label, however deeply nested. `panelInfolistActions()` is `PandaPanel\Testing\TestsActions::infolist()`, scoped to the infolist whitelist — an action it cannot find is one the endpoint could not run either. See [Testing actions](../testing/actions.md).

## Gotchas

- **An empty schema is not an empty page.** A resource that declares no infolist still renders a view page, from the form. If you want the view page to show *nothing*, it needs an infolist that declares something else.
- **The fallback stringifies with `displayValue()`.** Booleans become `Yes`/`No`, scalars are cast, and anything else becomes null. It is deliberately plain; the infolist is where formatting belongs.
- **`toArray()` requires a record.** Unlike `FormSchema::toArray()`, the model is not optional — an entry with no record has no value to resolve.
- **A layout emptied by hidden entries disappears entirely.** A section, grid, or tab whose children all returned null renders nothing rather than a bare heading. See [Layouts](layouts.md).
- **`columns()` is clamped, not validated.** `columns(6)` is silently four. That is closer to what was meant than the one column an unclamped six would have produced.
- **The schema is rebuilt per request.** `infolist()` is a static method taking a fresh `InfolistSchema`; nothing is cached between requests, so a closure reading `auth()->user()` sees the current one.

## See also

- [Entries](entries.md)
- [Entry reference](entry-reference.md)
- [Layouts](layouts.md)
- [Repeatable entries](repeatable-entries.md)
- [Custom entries](custom-entries.md)
- [Actions in infolists](actions.md)
- [Resource pages](../resources/resource-pages.md)
- [CRUD pages](../resources/crud-pages.md)
- [FormSchema basics](../forms/overview.md)
- [Actions overview](../actions/overview.md)
