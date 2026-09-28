# Money

`PandaPanel\Forms\Components\MoneyInput` is an amount of money, typed the way the reader's locale writes it. Thousands are grouped as the amount is typed (`12.500.000` in Indonesian, `12,500,000` in English), the currency symbol sits beside the input rather than inside it, and the value submitted is the plain amount — `12500000`, never `Rp 12.500.000`.

Reach for it instead of a [Number](number.md) whenever the number is money: it knows how many decimals the currency has, validates that precision on the server, never passes the amount through a float, and can store it as an integer count of cents.

## A minimal form

```php
use PandaPanel\Forms\Components\MoneyInput;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    MoneyInput::make('price')
        ->currency('IDR')
        ->min(0)
        ->required(),
]);
```

Set `panda-panel.forms.currency` (or `PANDA_PANEL_CURRENCY`) once for an application that deals in one currency, and drop the `currency()` call:

```php
// config/panda-panel.php
'forms' => [
    'currency' => env('PANDA_PANEL_CURRENCY', 'USD'),
],
```

## Methods

```php
public function currency(string $currency): self            // default: panda-panel.forms.currency
public function decimals(int $decimals): self               // default: the currency's
public function locale(?string $locale): self               // default: null — the panel's locale
public function storedAsMinorUnits(bool $minorUnits = true): self  // default: false
public function min(int|float|null $min): self              // default: null
public function max(int|float|null $max): self              // default: null
```

| Method | Rule it adds | What it changes in the browser |
| --- | --- | --- |
| `currency()` | — (it decides the default `decimals`) | the symbol, and where it sits |
| `decimals()` | `decimal:0,{n}` | how many digits the separator accepts; padding on blur |
| `locale()` | — | the group and decimal separators, and symbol placement |
| `storedAsMinorUnits()` | — | nothing: only what is written to and read from the record |
| `min()` | `min:{n}` | whether a leading minus is accepted at all |
| `max()` | `max:{n}` | — |

`currency()` takes an ISO 4217 code, case-insensitively, and throws on anything else — `currency('Rupiah')` is a mistake worth hearing about at boot rather than a form that labels every amount "RUPIAH".

### How many decimals

The default follows the currency: two for most, none for the ISO zero-decimal currencies (JPY, KRW, VND, …), three for the dinars (KWD, BHD, …).

**IDR defaults to none.** ISO 4217 gives the rupiah two decimals, but nobody prices anything in sen, and a form showing `Rp 150.000,00` for every amount is noise. Call `decimals(2)` where the standard matters — for instance when the amount is exchanged with a payment provider that counts rupiah in hundredths.

### Minor units

```php
MoneyInput::make('price')->currency('USD')->storedAsMinorUnits();
```

The column holds `1999`; the form shows `19.99` and people type `19.99`. `min()` and `max()` stay in the units people type — `min(1)` means one dollar, not one cent. The conversion is on the digits, so an amount of any size survives it exactly.

## The value

The amount crosses the wire as a **decimal string** in both directions: the form receives `"12500.50"` and submits `"12500.5"`. It is never a float, because `0.1 + 0.2` is not `0.3` in PHP or in JavaScript, and a price that drifted by a fraction of a cent between the input and the record is found by an accountant rather than a test.

| Stored | Sent to the form | Written back from `"19.9"` |
| --- | --- | --- |
| decimal column `"12500.5"` | `"12500.50"` | `"19.90"` |
| float column `0.30000000000000004` | `"0.30"` | `"19.90"` |
| `storedAsMinorUnits()`, `1999` | `"19.99"` | `1990` |
| `null` | `null` | `null` (an empty input) |

Rounding — reading a column with more places than the currency — is half away from zero, on the digits: `9.995` becomes `10.00`.

A `mutateUsing()` hook still runs, after the conversion, and receives what it produced — the integer, for a minor-unit field.

## Validation

```php
FormSchema::make()
    ->schema([MoneyInput::make('price')->currency('USD')->min(0)->max(500)])
    ->validationRules();

// ['price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:500']]
```

`decimal:0,{n}` refuses an amount the form would have to round: `12.345` dollars is an error, not `12.35`. The browser cannot type a third decimal, so this only ever catches a request that did not come from the form.

## In the browser

`resources/js/panel/forms/fields/MoneyField.vue` draws the control; the parsing and caret arithmetic are in `resources/js/panel/forms/money.ts`.

- The separators, and whether the symbol goes before or after the number, come from `Intl.NumberFormat` for the field's `locale()` or the panel's.
- Anything but digits, the locale's decimal separator, and a leading minus is ignored as it is typed.
- A digit past the currency's decimals is dropped rather than rounded; a keystroke that changed a digit already typed would be the control arguing with the person typing.
- The caret stays beside the digit it was beside while thousands are regrouped, and Backspace over a group separator deletes the digit before it.
- Leaving the field pads the decimals: `12,5` reads `12,50`.
- A minus is only accepted when `min()` is unset or negative.

## Gotchas

**The locale reads the separators.** In Indonesian `1.500` is fifteen hundred, and so is `1,500` in English. Pasting an English-formatted amount into an Indonesian form reads the `.` as a group separator.

**A currency without decimals ignores the separator.** Typing `1.500,75` into an IDR field is `150075`, not `1500.75` — there is nowhere for the `75` to go.

**`required()` still needs saying.** An empty input is `null`, and `nullable` allows it; `min(1)` is skipped for a null value.

## See also

- [Number](number.md) — for numbers that are not money
- [Validation](../validation.md)
- [Hydration](../hydration.md) — `castForForm()` for each field
- [Forms and Schemas](../overview.md)
