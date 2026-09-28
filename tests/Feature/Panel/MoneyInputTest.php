<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use PandaPanel\Forms\Components\MoneyInput;
use PandaPanel\Forms\FormSchema;

/**
 * A record holding one attribute, the way a driver hands it back.
 */
function moneyRecord(mixed $value): Model
{
    $record = new class extends Model
    {
        protected $guarded = [];
    };

    return $record->forceFill(['price' => $value]);
}

/**
 * @param  array<string, mixed>  $data
 */
function moneyPasses(MoneyInput $field, array $data): bool
{
    $rules = FormSchema::make()->schema([$field])->validationRules();

    return Validator::make($data, $rules)->passes();
}

/*
 * What the browser is told
 */

it('tells the browser the currency, its decimals and its bounds', function (): void {
    $definition = MoneyInput::make('price')
        ->currency('usd')
        ->locale('en-US')
        ->min(0)
        ->max(1000)
        ->toArray(null, 'create');

    expect($definition['type'])->toBe('money')
        ->and($definition['currency'])->toBe('USD')
        ->and($definition['decimals'])->toBe(2)
        ->and($definition['locale'])->toBe('en-US')
        ->and($definition['min'])->toBe(0)
        ->and($definition['max'])->toBe(1000);
});

it('takes its decimals from the currency unless told otherwise', function (): void {
    expect(MoneyInput::make('a')->currency('USD')->getDecimals())->toBe(2)
        ->and(MoneyInput::make('b')->currency('JPY')->getDecimals())->toBe(0)
        // ISO says two; practice says none, and practice is what people type.
        ->and(MoneyInput::make('c')->currency('IDR')->getDecimals())->toBe(0)
        ->and(MoneyInput::make('d')->currency('KWD')->getDecimals())->toBe(3)
        ->and(MoneyInput::make('e')->currency('IDR')->decimals(2)->getDecimals())->toBe(2);
});

it('uses the configured currency when the field names none', function (): void {
    config()->set('panda-panel.forms.currency', 'idr');

    expect(MoneyInput::make('price')->getCurrency())->toBe('IDR')
        ->and(MoneyInput::make('price')->getDecimals())->toBe(0);
});

it('refuses a currency that is not an ISO code', function (): void {
    MoneyInput::make('price')->currency('Rupiah');
})->throws(InvalidArgumentException::class, 'ISO 4217');

it('refuses a misconfigured default currency by name', function (): void {
    config()->set('panda-panel.forms.currency', 'dollars');

    MoneyInput::make('price')->getCurrency();
})->throws(InvalidArgumentException::class, 'panda-panel.forms.currency');

it('refuses a number of decimals no currency has', function (): void {
    MoneyInput::make('price')->decimals(5);
})->throws(InvalidArgumentException::class);

/*
 * Validation
 */

it('validates the amount, its decimals and its bounds', function (): void {
    $rules = FormSchema::make()
        ->schema([MoneyInput::make('price')->currency('USD')->min(0)->max(500)])
        ->validationRules();

    expect(array_map(strval(...), $rules['price']))->toBe(['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:500']);
});

it('refuses an amount it would have to round', function (): void {
    $field = MoneyInput::make('price')->currency('USD');

    expect(moneyPasses($field, ['price' => '12.34']))->toBeTrue()
        ->and(moneyPasses($field, ['price' => '12.345']))->toBeFalse()
        ->and(moneyPasses($field, ['price' => 'Rp 12']))->toBeFalse()
        ->and(moneyPasses(MoneyInput::make('price')->currency('IDR'), ['price' => '1500.5']))->toBeFalse();
});

it('compares the bounds to the amount, not to its length', function (): void {
    $field = MoneyInput::make('price')->currency('USD')->min(10)->max(100);

    expect(moneyPasses($field, ['price' => '9.99']))->toBeFalse()
        ->and(moneyPasses($field, ['price' => '10']))->toBeTrue()
        ->and(moneyPasses($field, ['price' => '100.01']))->toBeFalse();
});

/*
 * On the way into the form
 */

it('sends the amount as a string with every decimal place', function (mixed $stored, ?string $sent): void {
    $definition = MoneyInput::make('price')
        ->currency('USD')
        ->toArray(moneyRecord($stored), 'edit');

    expect($definition['value'])->toBe($sent);
})->with([
    'a decimal column' => ['12500.5', '12500.50'],
    'an integer column' => [5, '5.00'],
    'a float that is not what it looks like' => [0.1 + 0.2, '0.30'],
    'a negative amount' => ['-3.1', '-3.10'],
    'more places than the currency' => ['1.005', '1.01'],
    'nothing' => [null, null],
    'not an amount' => ['twelve', null],
]);

it('reads a minor-unit column back as the amount people type', function (mixed $stored, ?string $sent): void {
    $definition = MoneyInput::make('price')
        ->currency('USD')
        ->storedAsMinorUnits()
        ->toArray(moneyRecord($stored), 'edit');

    expect($definition['value'])->toBe($sent);
})->with([
    [1250050, '12500.50'],
    [5, '0.05'],
    [-199, '-1.99'],
    ['1250050', '12500.50'],
    [0, '0.00'],
]);

/*
 * On the way to the record
 */

it('rounds on the digits, carrying as far as it has to', function (string $submitted, string $stored): void {
    expect(MoneyInput::make('price')->currency('USD')->mutate($submitted, null))->toBe($stored);
})->with([
    ['12500.5', '12500.50'],
    ['9.995', '10.00'],
    ['999.999', '1000.00'],
    ['-0.004', '0.00'],
    ['0012', '12.00'],
]);

it('writes a minor-unit column as an integer', function (): void {
    $field = MoneyInput::make('price')->currency('USD')->storedAsMinorUnits();

    expect($field->mutate('12500.5', null))->toBe(1250050)
        ->and($field->mutate('0.05', null))->toBe(5)
        ->and($field->mutate('-1.99', null))->toBe(-199)
        ->and(MoneyInput::make('price')->currency('IDR')->storedAsMinorUnits()->mutate('150000', null))
        ->toBe(150000);
});

it('writes nothing as null', function (): void {
    $field = MoneyInput::make('price')->currency('USD');

    expect($field->mutate(null, null))->toBeNull()
        ->and($field->mutate('', null))->toBeNull();
});

it('hands a mutate hook the amount it produced', function (): void {
    $field = MoneyInput::make('price')
        ->currency('USD')
        ->storedAsMinorUnits()
        ->mutateUsing(static fn (mixed $value): mixed => ['received' => $value]);

    expect($field->mutate('1.5', null))->toBe(['received' => 150]);
});

it('dehydrates through the schema like any other field', function (): void {
    $attributes = FormSchema::make()
        ->schema([
            MoneyInput::make('price')->currency('USD')->storedAsMinorUnits(),
            MoneyInput::make('fee')->currency('IDR'),
        ])
        ->dehydrate(['price' => '19.9', 'fee' => '2500']);

    expect($attributes)->toBe(['price' => 1990, 'fee' => '2500']);
});
