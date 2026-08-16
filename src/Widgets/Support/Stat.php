<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Support;

use PandaPanel\Support\SafeUrl;
use PandaPanel\Widgets\Enums\StatColor;

/**
 * One figure on a stats widget.
 *
 * Immutable: the fluent methods return a new instance, so a stat cannot be
 * mutated after it has been handed to the widget.
 *
 * Formatting happens here rather than in Vue. A figure is a number *and* how
 * it should be read — "1,204" and "£1,204" and "1,204 ms" are three different
 * statements — and deciding that on the server is what keeps the renderer
 * from having to know what any particular number means.
 */
final readonly class Stat
{
    /**
     * @param  array{direction: 'up'|'down'|'neutral', value: float}|null  $trend
     * @param  list<int|float>  $chart  a sparkline drawn under the figure
     */
    public function __construct(
        public string $label,
        public string|int|float $value,
        public ?string $description = null,
        public ?string $icon = null,
        public StatColor $color = StatColor::Default,
        public ?array $trend = null,
        public array $chart = [],
        public ?string $url = null,
        public ?string $prefix = null,
        public ?string $suffix = null,
        public ?int $decimals = null,
    ) {}

    public static function make(string $label, string|int|float $value): self
    {
        return new self($label, $value);
    }

    public function description(string $description): self
    {
        return $this->with(description: $description);
    }

    public function icon(string $icon): self
    {
        return $this->with(icon: $icon);
    }

    public function color(StatColor $color): self
    {
        return $this->with(color: $color);
    }

    /**
     * @param  'up'|'down'|'neutral'  $direction
     */
    public function trend(string $direction, float $value): self
    {
        return $this->with(trend: ['direction' => $direction, 'value' => $value]);
    }

    /**
     * A sparkline under the figure.
     *
     * The shape of the last N periods, which is the context a single number
     * never has: "412" says nothing about whether that is a good week.
     *
     * @param  list<int|float>  $values
     */
    public function chart(array $values): self
    {
        return $this->with(chart: $values);
    }

    /**
     * Makes the whole stat a link.
     *
     * A URL the server produced, so the destination authorizes for itself
     * when it is followed.
     */
    public function url(string $url): self
    {
        return new self(
            $this->label,
            $this->value,
            $this->description,
            $this->icon,
            $this->color,
            $this->trend,
            $this->chart,
            SafeUrl::sanitize($url),
            $this->prefix,
            $this->suffix,
            $this->decimals,
        );
    }

    /**
     * What the figure wears, and how precisely it is written.
     */
    public function format(?string $prefix = null, ?string $suffix = null, ?int $decimals = null): self
    {
        return $this->with(prefix: $prefix, suffix: $suffix, decimals: $decimals);
    }

    /**
     * The figure as it will be read.
     *
     * A number is grouped and given its prefix and suffix here; anything that
     * is already a string is left exactly as the widget wrote it, because a
     * widget that formatted its own value has said what it wants.
     */
    public function display(): string
    {
        if (! is_int($this->value) && ! is_float($this->value)) {
            return (string) $this->value;
        }

        $formatted = number_format(
            (float) $this->value,
            $this->decimals ?? (is_float($this->value) ? 2 : 0),
        );

        return ($this->prefix ?? '').$formatted.($this->suffix ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'value' => $this->value,
            'display' => $this->display(),
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color->value,
            'trend' => $this->trend,
            'chart' => $this->chart,
            'url' => $this->url,
        ];
    }

    /**
     * One place the copy is made, so adding a property does not mean editing
     * eight constructors' worth of argument lists.
     *
     * @param  array{direction: 'up'|'down'|'neutral', value: float}|null  $trend
     * @param  list<int|float>|null  $chart
     */
    private function with(
        ?string $description = null,
        ?string $icon = null,
        ?StatColor $color = null,
        ?array $trend = null,
        ?array $chart = null,
        ?string $url = null,
        ?string $prefix = null,
        ?string $suffix = null,
        ?int $decimals = null,
    ): self {
        return new self(
            $this->label,
            $this->value,
            $description ?? $this->description,
            $icon ?? $this->icon,
            $color ?? $this->color,
            $trend ?? $this->trend,
            $chart ?? $this->chart,
            $url ?? $this->url,
            $prefix ?? $this->prefix,
            $suffix ?? $this->suffix,
            $decimals ?? $this->decimals,
        );
    }
}
