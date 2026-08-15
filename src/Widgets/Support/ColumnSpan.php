<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Support;

/**
 * Normalizes a widget's column span into one value per breakpoint.
 *
 * The frontend maps each value to a literal Tailwind class, so a value the
 * map does not cover would compile to nothing. Anything unrecognised is
 * clamped to 1 rather than silently producing an unstyled widget.
 */
final class ColumnSpan
{
    /** @var list<string> */
    private const BREAKPOINTS = ['default', 'md', 'lg', 'xl'];

    private const MAX = 4;

    /**
     * @param  int|string|array<string, int|string>  $span
     * @return array{default: int|string, md: int|string, lg: int|string, xl: int|string}
     */
    public static function normalize(int|string|array $span): array
    {
        if (! is_array($span)) {
            $value = self::clamp($span);

            return ['default' => $value, 'md' => $value, 'lg' => $value, 'xl' => $value];
        }

        $normalized = [];
        $previous = 1;

        foreach (self::BREAKPOINTS as $breakpoint) {
            // A breakpoint that is not declared inherits the one below it,
            // which is how CSS breakpoints behave anyway.
            $previous = array_key_exists($breakpoint, $span)
                ? self::clamp($span[$breakpoint])
                : $previous;

            $normalized[$breakpoint] = $previous;
        }

        /** @var array{default: int|string, md: int|string, lg: int|string, xl: int|string} $normalized */
        return $normalized;
    }

    private static function clamp(int|string $value): int|string
    {
        if ($value === 'full') {
            return 'full';
        }

        $numeric = is_numeric($value) ? (int) $value : 1;

        return max(1, min($numeric, self::MAX));
    }
}
