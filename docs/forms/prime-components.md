# Prime Components

Prime components are content in a schema: a line of text, an icon, a picture. They hold no value, carry no name, and persist nothing. You reach for one when a form has to *say* something — because saying it with a disabled field would put a name and a validation rule on a sentence.

## A minimal example

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Prime\Text;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        Text::make(static fn (?Model $record): string => $record === null
            ? 'This account will be created immediately.'
            : 'Editing '.$record->getAttribute('name')),

        TextInput::make('name')->required(),
    ]);
}
```

There are three of them, all under `PandaPanel\Forms\Prime`.

## `Text`

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Prime\Text;
use PandaPanel\Tables\Enums\BadgeColor;

Text::make('Changes take effect immediately.')
    ->color(BadgeColor::Warning)
    ->icon('triangle-alert')
    ->small();

Text::make(static fn (?Model $record): string => 'Last saved '.($record?->updated_at?->diffForHumans() ?? 'never'));
```

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string\|Closure(?Model): string $content): self` | |
| `color()` | `color(BadgeColor $color): self` | `null` |
| `icon()` | `icon(string $icon): self` | `null` |
| `small()` | `small(bool $small = true): self` | `false` |

The content may be resolved from the record, so it can state something about what is being edited. The closure runs on the server; only its result crosses the wire.

## `Icon`

```php
use PandaPanel\Forms\Prime\Icon;
use PandaPanel\Tables\Enums\BadgeColor;

Icon::make('shield-check')
    ->color(BadgeColor::Success)
    ->label('This account uses two-factor authentication');
```

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string $icon): self` | |
| `color()` | `color(BadgeColor $color): self` | `null` |
| `label()` | `label(string $label): self` | `null` |

The name is a registry key like every other icon in the panel, so a schema cannot ask the browser for one the build did not compile in. `label()` is the accessible name and is also drawn beside the icon — an icon with none reads as nothing at all.

## `Image`

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Forms\Prime\Image;

Image::make(static fn (?Model $record): ?string => $record === null
    ? null
    : Storage::disk('public')->url((string) $record->getAttribute('avatar')))
    ->alt('Current avatar')
    ->width(96)
    ->rounded();
```

| Method | Signature | Default |
| --- | --- | --- |
| `make()` | `static make(string\|Closure(?Model): ?string $url): self` | |
| `alt()` | `alt(string $alt): self` | `''` |
| `width()` | `width(int $pixels): self` | `null`, floored at 1 |
| `rounded()` | `rounded(bool $rounded = true): self` | `false` |

The URL is produced on the server — from a closure that may read the record — so the browser never builds one from an identifier. A URL that resolves to an empty string or to anything other than a string is serialized as `null`, and the frontend renders nothing rather than a broken image.

## `BadgeColor`

`PandaPanel\Tables\Enums\BadgeColor` is reused rather than a second colour vocabulary being invented, so a status is the same colour wherever it appears: `Neutral`, `Success`, `Warning`, `Danger`, `Info`. It is a closed set because the frontend maps each case to a literal Tailwind class.

## What crosses the wire

| Class | `component` | Keys |
| --- | --- | --- |
| `Text` | `prime-text` | `content`, `color`, `icon`, `small` |
| `Icon` | `prime-icon` | `icon`, `color`, `label` |
| `Image` | `prime-image` | `url`, `alt`, `width`, `rounded` |

All three implement `fields(): array` as `[]`, which is what makes them content: they contribute nothing to validation, nothing to hydration, and nothing to the write.

```php
Text::make('Hello')->fields();      // []
Icon::make('check')->fields();      // []
Image::make('/logo.png')->fields(); // []
```

## Where they can go

Anywhere a `FormComponent` can: at the top level of a schema, inside a `Section`, a `Grid`, a `Tab`, a `Step`, a `Callout`, or a `CustomComponent`.

```php
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Layouts\Section;
use PandaPanel\Forms\Prime\Text;

Section::make('Billing')->schema([
    Text::make('Invoices are sent on the first of the month.')->small(),
    TextInput::make('billing_email')->email(),
]);
```

The record they are resolved against is the record their container was serialized with — so a prime inside a [`Relationship`](relationships.md) group receives the *related* record, not the owner.

## Prime components and callouts

They overlap, and the difference is what each is for:

| | Use |
| --- | --- |
| `Prime\Text` | A sentence in the flow of the form |
| `Callout` | A framed note with a tone, a heading, and optionally the fields it is about |
| `EmptyState` | A section that has nothing to show, and why |

`Callout` and `EmptyState` are layouts, covered in [Form layouts](layouts.md).

## Notes

- **They have no `columnSpan()`.** Spans belong to fields; a prime component takes the flow position it is given.
- **They cannot be hidden per page.** There is no `hiddenOn()` on a prime component — build one conditionally, or put it inside a container you build conditionally.
- **A closure runs on every serialization**, which includes each rebuild from the `form-state` endpoint. Keep it cheap.
- **Inside a repeater's item schema the record is null.** An item is a plain map, not a model, so a record-dependent prime shows its null branch there.
- **An icon name that is not in the registry draws nothing**, and says so in the browser console in development. Run `php artisan panel:icons` after declaring a new one.
- **Content is escaped as text.** A prime component is not a way to render HTML; for formatted stored content use a [rich editor](fields/rich-editor.md) value rendered by your own page.

## See also

- [Form layouts](layouts.md)
- [Custom fields](custom-fields.md)
- [FormSchema basics](overview.md)
- [Infolist entries](../infolists/entries.md)
- [Icons](../frontend/icons.md)
- [Component registries](../concepts/component-registries.md)
