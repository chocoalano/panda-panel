# Infolists Reference

`PandaPanel\Infolists\InfolistSchema` and its entries: a record's read-only presentation, declared the way a form is but with none of a form's machinery. Deliberately not a mode of `FormSchema` — a form validates, dehydrates, and hides fields per page, and an infolist does none of that.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Infolists\InfolistSchema` | The schema |
| `PandaPanel\Infolists\Components\Entry` | The base of every entry |
| `PandaPanel\Infolists\Components\*Entry` | The eleven entry types |
| `PandaPanel\Infolists\Layouts\{Section,Grid,Tabs,Tab}` | Containers |
| `PandaPanel\Infolists\Support\InfolistRow` | The wrapper a repeatable entry's rows get |
| `PandaPanel\Infolists\Enums\EntryType` | The closed set that crosses into Vue |

## An infolist that runs

```php
<?php

namespace App\Panels\Admin\Resources\Users\Infolists;

use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;

final class UserInfolist
{
    public static function configure(InfolistSchema $schema): InfolistSchema
    {
        return $schema
            ->columns(2)
            ->schema([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        BooleanEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->labels('Verified', 'Unverified'),
                    ]),

                Section::make('Activity')->schema([
                    DateTimeEntry::make('created_at')->label('Joined'),
                    DateTimeEntry::make('updated_at')->label('Last updated')->since(),
                ]),
            ]);
    }
}
```

```php
public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return UserInfolist::configure($schema);
}
```

`ViewRecord` renders it. A resource that declares none gets read-only rows derived from its form instead — so adding an infolist is an improvement you opt into rather than a migration you are forced through.

## `InfolistSchema`

```php
public static function make(): self;
public function schema(array $components): self;   // array<array-key, InfolistComponent>
public function columns(int $columns): self;       // 1, clamped to 1..4
public function actions(array $actions): self;     // array<array-key, Action>

public function allActions(): array;               // array<string, Action>
public function getAction(string $name): ?Action;
public function getComponents(): array;            // list<InfolistComponent>
public function entries(): array;                  // list<Entry>
public function isEmpty(): bool;
public function toArray(Model $record): array;     // {columns, schema, actions}
```

`columns()` clamps through `PandaPanel\Support\ColumnCount::clamp()`, whose maximum is `4` — the renderer has literal Tailwind classes for one to four.

`isEmpty()` is what `ViewRecord` asks to decide between the infolist and the form-derived fallback.

### `allActions()`

The whitelist the infolist action endpoint resolves against. It collects, in order:

1. the schema's own `actions()`;
2. every `Section::headerActions()`;
3. every `Entry::action()`;
4. every action registered inside one of those with `registerModalActions()`.

An action that is not in there does not exist, however a request spells it. Header actions, section actions, and entry actions are all reachable because all three were declared by this schema — and nothing else is.

## Actions on an infolist

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

$schema
    ->actions([
        Action::make('note')
            ->label('Add a note')
            ->icon('pencil')
            ->variant(ActionVariant::Outline)
            ->modalHeading('Note about this account')
            ->modalSubmitLabel('Save note')
            ->modalWidth(ModalWidth::Large)
            ->slideOver()
            ->successMessage('Note saved.')
            ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
                Textarea::make('note')->rows(6)->required()->maxLength(1000),
            ]))
            ->authorize(static fn (?Model $record): bool => $record !== null)
            ->action(static function (Model $record, array $data): void {
                $record->notes()->create(['body' => $data['note']]);
            }),
    ]);
```

Schema-level actions sit above the infolist, which is where "approve this order" belongs — it is about the record, not about one of its columns.

A section may carry its own:

```php
use PandaPanel\Infolists\Layouts\Section;

Section::make('Identity')
    ->headerActions([
        Action::make('resendVerification')
            ->label('Resend verification')
            ->icon('mail')
            ->visible(fn (?Model $record): bool => $record?->email_verified_at === null)
            ->action(fn (Model $record) => $record->sendEmailVerificationNotification()),
    ])
    ->schema([...]);
```

And an entry may carry one beside its value with `Entry::action()`.

They post to `panel.{id}.actions.infolist`, a different endpoint from the table's, so an action shown on a view page cannot be run from a list.

## `Entry`

`abstract class Entry extends InfolistComponent`.

```php
public static function make(string $name): static;
public function getName(): string;
public function label(string $label): static;             // headline of the name, dots become spaces
public function getLabel(): string;
public function placeholder(string $placeholder): static; // shown when the value is empty
public function helperText(string $helperText): static;
public function columnSpan(int $columnSpan): static;      // 1, min 1
public function columnSpanFull(): static;
public function formatUsing(Closure $callback): static;   // Closure(mixed, Model): mixed
public function visible(Closure $callback): static;       // Closure(Model): bool
public function isVisible(Model $record): bool;
public function action(Action $action): static;
public function getAction(): ?Action;
public function entries(): array;                         // [$this]
public function toArray(Model $record): ?array;
abstract public function type(): EntryType;
abstract public function toValue(Model $record): mixed;
```

Dot notation reads through relations, so `author.name` is an entry rather than a reason to write a formatter — and its label comes out as `Author Name`.

`toArray()` returns `null` for an entry `visible()` refused, so it is absent from the payload rather than rendered empty. Its keys: `component: 'entry'`, `name`, `label`, `type`, `value`, `placeholder`, `helperText`, `columnSpan`, `action`, plus whatever the concrete entry adds.

`formatUsing()` runs on the server and its result is what the entry type then renders:

```php
BadgeEntry::make('role')
    ->formatUsing(fn (mixed $value, Model $record): string => $record->is_admin ? 'Administrator' : 'Member')
    ->colors(['Administrator' => BadgeColor::Info]);
```

An empty value returns `null` from `toValue()` so the placeholder shows, rather than a blank space that reads as a rendering bug.

## Entry types

| Class | `EntryType` | Own methods | Defaults |
| --- | --- | --- | --- |
| `TextEntry` | `Text` | `limit(int)`, `prose(bool = true)` | — |
| `BadgeEntry` | `Badge` | `colors(array, BadgeColor $default = BadgeColor::Neutral)` | `Neutral` |
| `BooleanEntry` | `Boolean` | `labels(string $true, string $false)` | `Yes` / `No` |
| `DateTimeEntry` | `DateTime` | `format(string)`, `since(bool = true)` | `'M j, Y g:ia'`, `since` false |
| `KeyValueEntry` | `KeyValue` | — | — |
| `IconEntry` | `Icon` | `icons(array)`, `colors(array, BadgeColor $default = BadgeColor::Neutral)`, `iconUsing(Closure)` | `Neutral` |
| `ImageEntry` | `Image` | `disk(string)`, `size(int)`, `circular(bool = true)` | 96 px, not circular |
| `ColorEntry` | `Color` | `copyable(bool = true)` | false |
| `CodeEntry` | `Code` | `language(CodeLanguage)`, `copyable(bool = true)` | `Plain`, false |
| `RepeatableEntry` | `Repeatable` | `schema(array)`, `columns(int)`, `itemLabel(string)` | 1 column |
| `CustomEntry` | `Custom` | `component(string)`, `config(array)`, `state(Closure)` | — |

```php
use PandaPanel\Infolists\Components\CodeEntry;
use PandaPanel\Infolists\Components\IconEntry;
use PandaPanel\Infolists\Components\ImageEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Tables\Enums\BadgeColor;

TextEntry::make('bio')->limit(200)->prose()->columnSpanFull();

ImageEntry::make('avatar_path')->disk('public')->size(64)->circular();

IconEntry::make('two_factor_confirmed_at')
    ->label('Two-factor')
    ->formatUsing(fn (mixed $value): string => $value === null ? 'off' : 'on')
    ->icons(['on' => 'shield', 'off' => 'x'])
    ->colors(['on' => BadgeColor::Success, 'off' => BadgeColor::Neutral]);

CodeEntry::make('summary')
    ->copyable()
    ->formatUsing(fn (mixed $value, Model $record): array => [
        'id' => $record->getKey(),
        'admin' => (bool) $record->is_admin,
    ]);
```

`IconEntry::iconUsing()` is the escape hatch when the icon cannot be a lookup:

```php
IconEntry::make('score')->iconUsing(fn (mixed $value): string => $value > 80 ? 'trending-up' : 'trending-down');
```

`CustomEntry::component()` takes a build-time registry key — the component's path below `resources/js/pages/` without the extension, and the glob only sees `Panels/**/Entries/*.vue`. `state()` supplies whatever that component needs:

```php
use PandaPanel\Infolists\Components\CustomEntry;

CustomEntry::make('sparkline')
    ->component('Panels/Admin/Entries/Sparkline')
    ->config(['height' => 40])
    ->state(fn (Model $record): array => $record->dailyTotals());
```

`BadgeColor` is `Neutral`, `Success`, `Warning`, `Danger`, `Info` — a closed set, because each case maps to a literal Tailwind class the compiler has to have seen.

`CodeLanguage` is `Plain`, `Json`, `Html`, `Css`, `JavaScript`, `Php`, `Sql`, `Yaml`, `Markdown`.

## `RepeatableEntry`

One entry per related row.

```php
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\RepeatableEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Layouts\Grid;

RepeatableEntry::make('passkeys')
    ->label('Registered')
    ->itemLabel('Passkey')
    ->placeholder('No passkeys registered.')
    ->schema([
        Grid::make(3)->schema([
            TextEntry::make('name'),
            DateTimeEntry::make('created_at')->label('Added'),
            DateTimeEntry::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
        ]),
    ]);
```

Each row is wrapped in `PandaPanel\Infolists\Support\InfolistRow` — an unguarded, timestamp-free `Model` — so the children read it exactly as they read a real record.

```php
InfolistRow::wrap(array $row): self;
```

Because a wrapped row has no key, an `Entry::action()` inside a repeatable is not serialized: an action pointing at it would name a record the endpoint could never find.

## Layouts

| Class | Constructor | Own methods |
| --- | --- | --- |
| `Section` | `make(string $heading)` | `schema`, `description`, `columns`, `headerActions(array)`, `getHeaderActions()` |
| `Grid` | `make(int $columns = 2)` | `schema` |
| `Tabs` | `make(array $tabs = [])` | `tabs`, `persistTab(bool = true)` |
| `Tab` | `make(string $label)` | `schema`, `icon`, `badge`, `columns` |

```php
use PandaPanel\Infolists\Layouts\Tab;
use PandaPanel\Infolists\Layouts\Tabs;

Tabs::make([
    Tab::make('Account')->icon('user')->columns(2)->schema([...]),
    Tab::make('Security')->icon('shield')->badge('2')->schema([...]),
])->persistTab();
```

Every layout implements `entries()`, which walks to the leaves, and `toArray(Model $record)`, which may return `null` — a section whose every entry is hidden renders nothing.

`Tabs` does not persist the selected tab by default; `persistTab()` remembers it.

## `EntryType`

`Text`, `Badge`, `Boolean`, `DateTime`, `KeyValue`, `Icon`, `Image`, `Color`, `Code`, `Repeatable`, `Custom`.

## Testing

```php
panelInfolistLabels(UserResource::class, $user);
// ['Name', 'Email', 'Role', 'Email verified', ...]
```

`PandaPanel\Testing\TestsSchemas::infolistLabels()` builds the real schema through `Resource::infolist()`, so a label it reports is a label the view page renders.

## Notes

- **An infolist is not a form.** There are no rules, no dehydration, no per-page visibility, and no `disabled`. An entry that should not be shown to this user uses `visible()`, which is evaluated on the server and removes it from the payload.
- **`toValue()` returns scalars, arrays, and nulls only.** No closures, no models, no queries — the same boundary a table column keeps.
- **A password has no entry, and that is the point.** A form-derived view could only promise to hide it by filtering; an infolist that never reads it cannot leak it.
- **Truncation happens on the server.** `TextEntry::limit()` sends `Grace...` rather than the whole value plus a CSS rule.
- **`columns()` is clamped to four.** Asking for six gives four rather than silently collapsing to one.
- **An entry action inside a repeatable is dropped.** The wrapped row has no key for the endpoint to resolve.

## See also

- [Infolists overview](../infolists/overview.md)
- [Entries](../infolists/entries.md)
- [Entry reference](../infolists/entry-reference.md)
- [Layouts](../infolists/layouts.md)
- [Repeatable entries](../infolists/repeatable-entries.md)
- [Infolist actions](../infolists/actions.md)
- [Custom entries](../infolists/custom-entries.md)
- [Resources reference](resources.md)
- [Actions reference](actions.md)
- [Forms reference](forms.md)
