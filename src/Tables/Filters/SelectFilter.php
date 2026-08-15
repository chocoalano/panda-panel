<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\FilterType;

final class SelectFilter extends Filter
{
    /** @var array<string, string> */
    private array $options = [];

    private ?string $placeholder = null;

    public function type(): FilterType
    {
        return FilterType::Select;
    }

    /**
     * @param  array<string, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Only a declared option key is accepted. Anything else, including a
     * value that would be a valid column value, is rejected: the declared
     * options are the whitelist.
     */
    public function sanitize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;

        return array_key_exists($value, $this->options) ? $value : null;
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $query->where($this->getColumn(), '=', $value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'options' => array_map(
                static fn (string $label, string $value): array => ['value' => $value, 'label' => $label],
                array_values($this->options),
                array_map(strval(...), array_keys($this->options)),
            ),
            'placeholder' => $this->placeholder,
        ];
    }
}
