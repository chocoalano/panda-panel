# Date and Time

Three fields, not one with flags: `PandaPanel\Forms\Components\DatePicker`, `PandaPanel\Forms\Components\DateTimePicker`, and `PandaPanel\Forms\Components\TimePicker`. They format their value differently, validate differently, and render a different control, so a flag on one class would have made every one of those a branch. Reach for the one whose value shape matches the column.

`DatePicker` renders the panel's own calendar — a shadcn-vue date picker built from `@/components/ui/popover` and `@/components/ui/calendar`. The other two are still native inputs; see [What the control is](#what-the-control-is).

## The minimal example

```php
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\TimePicker;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    DatePicker::make('born_on'),
    DateTimePicker::make('published_at'),
    TimePicker::make('opens_at'),
]);
```

Cast the attributes on the model so the fields receive Carbon instances rather than raw strings:

```php
protected function casts(): array
{
    return [
        'born_on' => 'date',
        'published_at' => 'datetime',
    ];
}
```

## At a glance

| Field | `FieldType` | Control | Value format | Type rules |
| --- | --- | --- | --- | --- |
| `DatePicker` | `Date` (`'date'`) | `PanelDatePicker.vue` — popover calendar | `Y-m-d` | `date` |
| `DateTimePicker` | `DateTime` (`'datetime'`) | `<input type="datetime-local">` | `Y-m-d H:i` on submit | `date` |
| `TimePicker` | `Time` (`'time'`) | `<input type="time">` | `H:i` or `H:i:s` | `date_format:H:i[:s]` |

## What the control is

`DatePicker` used to render `<input type="date">`. It now mounts
`resources/js/panel/components/PanelDatePicker.vue` — a
[shadcn-vue date picker](https://www.shadcn-vue.com/docs/components/date-picker):
a `Button` trigger inside a `Popover`, holding the `Calendar` component this
package already publishes.

The reason is that a native date input is the *browser's* control, not the
panel's. Chrome, Firefox and Safari each draw a different one, none of them
themeable, none of them matching the rest of a panel, and their clear
affordances differ too — Firefox has none. A field the panel cannot style is a
field that will not match whatever branding the panel was given.

**What did not change is the value.** The picker emits an ISO `Y-m-d` string,
or `null` when cleared, which is exactly what `<input type="date">` emitted.
`minDate()`, `maxDate()`, `required()` and `disabled()` all still mean what
they meant, and no PHP moved. An application that never touches Vue sees only a
different-looking control.

```text
resources/js/panel/components/PanelDatePicker.vue   the control
resources/js/components/ui/calendar/                the calendar it opens
resources/js/components/ui/popover/                 the popover it opens in
```

All three are published by `vendor:publish --tag=panda-panel-assets`, so an
application owns them and can restyle them. `@internationalized/date` — which
`Calendar` already needed — parses and formats; `panel:install` names it if it
is missing.

The same component renders both bounds of a [date range filter](../../tables/filters.md#datefilter),
so a date is picked the same way in a form and in a table toolbar.

### Still native

`DateTimePicker` and `TimePicker` remain `<input type="datetime-local">` and
`<input type="time">`. shadcn-vue's date picker covers a date; a time and a
date-time need a different control, and swapping them on the same reasoning is
a separate change rather than an implied one.

## `DatePicker`

```php
public function minDate(?string $date): self     // default: null
public function maxDate(?string $date): self     // default: null
```

```php
use PandaPanel\Forms\Components\DatePicker;

DatePicker::make('starts_on')
    ->label('Start date')
    ->minDate('2020-01-01')
    ->maxDate('today')
    ->required();
```

Both are `null` by default and both do two things: they become the control's `min` / `max` attributes, and they become Laravel rules.

```php
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([DatePicker::make('starts_on')->minDate('2020-01-01')->maxDate('2030-12-31')])
    ->validationRules();

// ['starts_on' => ['nullable', 'date', 'after_or_equal:2020-01-01', 'before_or_equal:2030-12-31']]
```

The string is passed through untouched, so anything `after_or_equal` accepts works — including relative strings such as `today`, which Laravel will honour. The calendar only greys out a day when the bound is a real `Y-m-d` date it can parse: `'today'` is a rule the server enforces, not one the control can draw. Passing `null` clears a previously set bound.

### Hydration

```php
protected function castForForm(mixed $value): ?string
```

| Stored value | Sent to the control |
| --- | --- |
| a `CarbonInterface` | `->format('Y-m-d')` |
| a non-empty string | its first ten characters |
| anything else | `null` |

The ten-character truncation is what lets an uncast `datetime` column work: `2026-08-15 09:30:00` becomes `2026-08-15`. Clearing the control submits `null`.

## `DateTimePicker`

```php
public function minDate(?string $date): self      // default: null
public function maxDate(?string $date): self      // default: null
public function seconds(bool $seconds = true): self  // default: false
```

```php
use PandaPanel\Forms\Components\DateTimePicker;

DateTimePicker::make('published_at')
    ->label('Publish at')
    ->minDate('2026-01-01 00:00')
    ->seconds()
    ->helperText('Stored and displayed in the application timezone.');
```

`seconds()` decides the format the value is rendered in — `Y-m-d\TH:i:s` rather than `Y-m-d\TH:i` — and sets the control's `step` to `1`, which is the only thing that makes a browser show a seconds spinner at all. Without it the control rounds to the minute and a value carrying seconds is truncated on the first edit.

The rules are `date` plus the same `after_or_equal` / `before_or_equal` pair as `DatePicker`. `seconds()` adds no rule: `date` accepts both precisions.

### The `T` on the boundary

PHP formats the value for the control with a literal `T` (`2026-08-15T09:30`), which is what `datetime-local` requires. The control submits it back with a **space** (`2026-08-15 09:30`), because that is what a column would rather hold. The translation happens once, in `DateTimeField.vue`, and neither side has to change its mind about the format it prefers.

That is worth knowing when a hook reads the raw submitted value: it is `Y-m-d H:i` (or `Y-m-d H:i:s`), not ISO-8601 with a `T`.

### Hydration

| Stored value | Sent to the control |
| --- | --- |
| a `CarbonInterface` | `->format('Y-m-d\TH:i')`, or `...:s` with `seconds()` |
| a non-empty string | unchanged |
| anything else | `null` |

Unlike `DatePicker`, a raw string is passed through as it is. An uncast column returning `2026-08-15 09:30:00` therefore reaches the control with seconds it may not be able to show. Cast the attribute to `datetime` and the formatting is handled.

## `TimePicker`

```php
public function seconds(bool $seconds = true): self   // default: false
```

```php
use PandaPanel\Forms\Components\TimePicker;

TimePicker::make('opens_at'),
TimePicker::make('cron_at')->seconds(),
```

The rule is a strict format check rather than `date`:

```php
use PandaPanel\Forms\Components\TimePicker;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([TimePicker::make('opens_at'), TimePicker::make('cron_at')->seconds()])
    ->validationRules();

// [
//     'opens_at' => ['nullable', 'date_format:H:i'],
//     'cron_at'  => ['nullable', 'date_format:H:i:s'],
// ]
```

`date_format` is exact. A value with seconds fails a field without `seconds()`, and a value without them fails a field with it — which is the point: the two spellings are not interchangeable and a lenient rule would let a column drift between them.

### Hydration

| Stored value | Sent to the control |
| --- | --- |
| a `CarbonInterface` | `->format('H:i')`, or `'H:i:s'` with `seconds()` |
| a non-empty string | unchanged |
| anything else | `null` |

## What crosses the wire

```ts
interface DateFieldDefinition extends BaseFieldDefinition {
    type: 'date';
    minDate: string | null;
    maxDate: string | null;
}

interface DateTimeFieldDefinition extends BaseFieldDefinition {
    type: 'datetime';
    minDate: string | null;
    maxDate: string | null;
    seconds: boolean;
}

interface TimeFieldDefinition extends BaseFieldDefinition {
    type: 'time';
    seconds: boolean;
}
```

All three controls emit `null` for an empty input rather than `''`, so an optional date field clears cleanly to a nullable column. `DatePicker` carries its own clear button — a small `×` on the trigger — because a popover has no equivalent of the native input's clear affordance. It is hidden on a `required()` field, where there is no empty state to return to.

## Recipes

**A range whose end must not precede its start.** `minDate()` is a rule string, so it can name another field — but it is also the bound the calendar greys days out with, where a field name is meaningless. Set the bound as a rule instead:

```php
use PandaPanel\Forms\Components\DatePicker;

DatePicker::make('starts_on')->required(),
DatePicker::make('ends_on')->rules(['after_or_equal:starts_on']),
```

**A timestamp that must not be in the past on create.** `when()` comes from `Conditionable`, and the page is on the schema:

```php
use PandaPanel\Forms\Components\DateTimePicker;

DateTimePicker::make('scheduled_for')
    ->when(
        $schema->getPage() === 'create',
        static fn (DateTimePicker $field): DateTimePicker => $field->minDate('now'),
    );
```

**A date stored as a Carbon instance rather than a string.** The submitted value is a string; cast it on the way out if the column is not already cast:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use PandaPanel\Forms\Components\DatePicker;

DatePicker::make('starts_on')->mutateUsing(
    static fn (mixed $value, ?Model $record): mixed => $value === null
        ? null
        : Date::parse((string) $value)->startOfDay(),
);
```

## Gotchas

**No timezone handling.** The fields format and parse in whatever timezone Carbon is already using. A panel serving users across timezones has to convert in `formatUsing()` and `mutateUsing()`; the fields will not guess.

**`minDate` and `maxDate` are strings, not `DateTimeInterface`.** Pass `'2026-01-01'`, or `Date::now()->toDateString()` — not the Carbon instance.

**`TimePicker` has no `minTime` / `maxTime`.** Bound it with `rules()` if you need to: `date_format` is the only rule the field generates.

**An uncast column bypasses formatting.** `DateTimePicker` and `TimePicker` pass strings through unchanged, so what the database returns is what the control receives. Cast the attribute, or shape it in `formatUsing()`.

**`before_or_equal` and `after_or_equal`, never the strict versions.** The bounds are inclusive; `maxDate('today')` accepts today.

## See also

- [Number](number.md) — the other bounded scalar field
- [Visibility](../visibility.md)
- [State Lifecycle](../state-lifecycle.md) — `formatUsing()` and `mutateUsing()`
- [Validation](../validation.md)
- [Table Filters](../../tables/filters.md) — the date range filter over the same columns
- [Forms and Schemas](../overview.md)
