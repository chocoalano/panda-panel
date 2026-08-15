# Infolist Layouts

A layout groups entries without changing what they read. `Section`, `Grid`, `Tabs` and `Tab` all extend `PandaPanel\Infolists\Components\InfolistComponent`, exactly as entries do, so a schema is one tree of components in any arrangement. You reach for one when a view page has more to say than a flat list reads well as.

Layout only, exactly as in a form: moving an entry between sections cannot change what is shown.

## A minimal layout

```php
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;

return $schema->columns(2)->schema([
    Section::make('Account')
        ->description('Who they are.')
        ->columns(2)
        ->schema([
            TextEntry::make('name'),
            TextEntry::make('email'),
            DateTimeEntry::make('created_at')->label('Joined')->columnSpanFull(),
        ]),
]);
```

## The four layouts

| Class | `component` | Draws |
| --- | --- | --- |
| `PandaPanel\Infolists\Layouts\Section` | `section` | A card with a heading, an optional description, and header actions |
| `PandaPanel\Infolists\Layouts\Grid` | `grid` | Columns, no heading, no box |
| `PandaPanel\Infolists\Layouts\Tabs` | `tabs` | A tab bar and its panels |
| `PandaPanel\Infolists\Layouts\Tab` | `tab` | One panel of a tab set |

There is no `Callout`, `EmptyState`, `Wizard` or `Relationship` here. Those are form layouts and they exist for input, steps, or writes — none of which an infolist has. There is no `CustomComponent` either; a custom *entry* is the extension point. See [Custom entries](custom-entries.md).

## `Section`

A titled group of entries, rendered as a card.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $heading): self` | — |
| `schema()` | `schema(array $components): self` | `[]` |
| `description()` | `description(string $description): self` | `null` |
| `columns()` | `columns(int $columns): self` | `1`, clamped to 1–4 |
| `headerActions()` | `headerActions(array $actions): self` | `[]` |
| `getHeaderActions()` | `getHeaderActions(): list<Action>` | — |
| `entries()` | `entries(): list<Entry>` | Every entry inside, however deeply nested |
| `toArray()` | `toArray(Model $record): ?array` | Null when every child was hidden |

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Layouts\Section;

Section::make('Invitation')
    ->description('Sent when the account was created.')
    ->columns(2)
    ->headerActions([
        Action::make('resend')
            ->label('Resend')
            ->icon('mail')
            ->variant(ActionVariant::Ghost)
            ->action(static fn (Model $record) => $record->sendEmailVerificationNotification()),
    ])
    ->schema([
        TextEntry::make('email'),
        TextEntry::make('invited_by.name')->label('Invited by'),
    ]);
```

Header actions belong to the group of entries rather than to one of them — "resend invitation" beside the invitation details. They are declared here and reachable through the same endpoint as every other infolist action; see [Actions in infolists](actions.md).

The heading is a constructor argument, so `new Section('Account')` is equivalent to `Section::make('Account')`.

## `Grid`

Columns without a heading. A section says "these belong together and here is what they are"; a grid says only "these sit side by side", which is what you want inside a section that already has a title.

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(int $columns = 2): self` | `2`, clamped to 1–4 |
| `schema()` | `schema(array $components): self` | `[]` |
| `entries()` | `entries(): list<Entry>` | Every entry inside |
| `toArray()` | `toArray(Model $record): ?array` | Null when every child was hidden |

```php
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Layouts\Grid;
use PandaPanel\Infolists\Layouts\Section;

Section::make('Passkey')->schema([
    Grid::make(3)->schema([
        TextEntry::make('name'),
        DateTimeEntry::make('created_at')->label('Added'),
        DateTimeEntry::make('last_used_at')->label('Last used')->since(),
    ]),
]);
```

A grid is also the usual body of a repeatable item, where a heading per item would be noise. See [Repeatable entries](repeatable-entries.md).

## `Tabs` and `Tab`

A record shown a few panels at a time, for one with more to say than fits on a screen.

### `Tabs`

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(array $tabs = []): self` | `[]` |
| `tabs()` | `tabs(array $tabs): self` | Replaces the tabs |
| `persistTab()` | `persistTab(bool $persist = true): self` | `false` |
| `entries()` | `entries(): list<Entry>` | Every entry in every tab |
| `toArray()` | `toArray(Model $record): ?array` | Null when every tab was empty |

### `Tab`

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $label): self` | — |
| `schema()` | `schema(array $components): self` | `[]` |
| `icon()` | `icon(string $icon): self` | `null` |
| `badge()` | `badge(string $badge): self` | `null` |
| `columns()` | `columns(int $columns): self` | `1`, clamped to 1–4 |
| `entries()` | `entries(): list<Entry>` | Every entry inside |
| `toArray()` | `toArray(Model $record): ?array` | Null when every child was hidden |

```php
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Infolists\Layouts\Tab;
use PandaPanel\Infolists\Layouts\Tabs;

return $schema->schema([
    Tabs::make([
        Tab::make('Account')
            ->icon('user')
            ->columns(2)
            ->schema([
                Section::make('Identity')->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                ]),
            ]),

        Tab::make('Security')
            ->icon('shield')
            ->badge('2')
            ->schema([TextEntry::make('two_factor_confirmed_at')]),
    ])->persistTab(),
]);
```

The icon is a registry key resolved through `resources/js/panel/icons/registry.ts`, never markup. A name that is not in the registry renders no icon rather than something arbitrary; run `php artisan panel:icons` after declaring a new one. See [Icons](../frontend/icons.md).

The badge is a plain string — a count, a status word. It is not computed; format it yourself before passing it in.

### Every tab is serialized

All of them, always. They are the same record read different ways, so fetching a tab when it opens would be a request to show data the page already had. Switching tabs costs nothing and makes no request.

### `persistTab()`

Remembers the open tab in the URL's `tab` parameter, so a reload — or a link somebody was sent — opens where it was left:

```php
Tabs::make([...])->persistTab();      // /admin/users/1?tab=security
```

The key is `Str::slug()` of the label, generated on the server so an error message and a URL can name the same thing:

```php
Tab::make('Account Details');     // key: 'account-details'
```

A URL naming a tab that does not exist opens the first one rather than nothing at all.

## Columns and spans

Every container carries its own column count, and an entry's span counts *that* container's columns:

```php
$schema->columns(2)->schema([          // the root grid: 2
    Section::make('Wide')->columns(3)->schema([
        TextEntry::make('a'),                       // 1 of 3
        TextEntry::make('b')->columnSpan(2),        // 2 of 3
        TextEntry::make('c')->columnSpanFull(),     // the whole row
    ]),
]);
```

Layouts themselves always take the whole row of their parent — `InfolistNode.vue` passes `'full'` for any node that is not an entry — so nesting two sections side by side is not something the renderer offers. Put a `Grid` inside a section instead.

Counts are clamped to 1–4 by `PandaPanel\Support\ColumnCount::clamp()`, because `resources/js/panel/lib/grid.ts` has literal Tailwind classes for one through four and an interpolated `grid-cols-${n}` would not exist in the bundle. The ladder is one column on a phone, at most two at `md`, and three or four at `lg`.

## An empty layout renders nothing

Each layout's `toArray()` returns null when every child returned null:

```php
use PandaPanel\Infolists\Layouts\Section;

$section = Section::make('Nothing')->schema([
    TextEntry::make('email')->visible(static fn (): bool => false),
]);

$section->toArray($record);      // null
```

The same holds for `Grid`, for `Tab`, and — when every tab is empty — for `Tabs`. An empty heading reads as a rendering fault, so the heading goes with the entries. It cascades: a section holding only empty grids is itself empty.

## What crosses the wire

```php
// Section
['component' => 'section', 'heading' => 'Account', 'description' => null, 'columns' => 2, 'schema' => [...], 'headerActions' => []]

// Grid
['component' => 'grid', 'columns' => 3, 'schema' => [...]]

// Tabs
['component' => 'tabs', 'persistTab' => true, 'tabs' => [...]]

// Tab
['component' => 'tab', 'label' => 'Account', 'key' => 'account', 'icon' => 'user', 'badge' => null, 'columns' => 2, 'schema' => [...]]
```

The TypeScript mirrors are `InfolistSectionDefinition`, `InfolistGridDefinition`, `InfolistTabsDefinition` and `InfolistTabDefinition` in `resources/js/panel/types/infolist.ts`. `InfolistNode.vue` recurses on `component`, so nesting depth is a data concern rather than a component concern.

## Notes

- **Loose entries are grouped.** Entries declared at the top level of the schema, outside any layout, are collected into one card by `InfolistRenderer.vue` rather than each getting a box of its own. Mixing loose entries and sections is fine: the card comes first, the layouts follow in declaration order.
- **`Grid::make()` clamps; the constructor does not.** `Grid::make(9)` is four columns. `new Grid(9)` keeps nine in the payload, and the renderer falls back to one column because there is no literal class for it. Use `make()`.
- **A tab set with one tab still draws a tab bar.** If that is not what you want, it is a section.
- **Header actions only exist on `Section`.** `Grid`, `Tab` and `Tabs` carry none, and `InfolistSchema::allActions()` only walks top-level sections for them — a section nested inside a tab declares actions the endpoint will not find. Put those on the schema with `actions()` instead, or on the entry.
- **`Tab::badge()` takes a string.** Cast a count yourself: `->badge((string) $record->passkeys->count())` inside a closure-built schema, or format it before the schema is built.

## See also

- [InfolistSchema basics](overview.md)
- [Entries](entries.md)
- [Entry reference](entry-reference.md)
- [Repeatable entries](repeatable-entries.md)
- [Actions in infolists](actions.md)
- [Form layouts](../forms/layouts.md)
- [Icons](../frontend/icons.md)
