<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Stringable;

/**
 * Refuses a JSON number that carries more digits than an amount may have.
 *
 * Laravel's `decimal` reads a value by casting it to a string, and PHP casts a
 * float at 14 significant digits: `10.000000000000002` reads `10`, passes
 * `decimal:0,2`, and is then rounded on its way to the record. This reads the
 * float at 15 significant digits — as many as a double is guaranteed to hand
 * back unchanged — and refuses one that needs more, or that still has too many
 * decimals.
 *
 * Only floats. A string is read digit for digit by `decimal` itself, and an
 * integer is exact.
 *
 * What nothing here can see is a digit the JSON decoder already dropped:
 * `100.00000000000000001` *is* `100.0` before any application code runs. That
 * is why an amount is meant to cross the wire as a string.
 *
 * Stringable because a rule list is read as strings — `assertFieldIsRequired()`
 * does it.
 */
final readonly class ExactFloatAmount implements Stringable, ValidationRule
{
    public function __construct(private int $decimals) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // What `decimal` can see it refuses itself, and one message is enough.
        if (! is_float($value) || ! $this->fits((string) $value)) {
            return;
        }

        // `%h` is `%g` without the C locale's decimal separator.
        $digits = sprintf('%.15h', $value);

        if ((float) $digits === $value && $this->fits($digits)) {
            return;
        }

        $fail('validation.decimal')->translate(['decimal' => '0-'.$this->decimals]);
    }

    public function __toString(): string
    {
        return 'exact_float_amount:'.$this->decimals;
    }

    /**
     * Whether a number is written plainly and with few enough decimals. An
     * exponent is not plain, and `decimal` refuses it too.
     */
    private function fits(string $number): bool
    {
        return preg_match('/^-?\d+(?:\.(\d+))?$/', $number, $matches) === 1
            && strlen($matches[1] ?? '') <= $this->decimals;
    }
}
