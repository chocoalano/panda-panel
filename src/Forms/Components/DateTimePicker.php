<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Carbon\CarbonInterface;
use PandaPanel\Forms\Enums\FieldType;

/**
 * A date and a time together.
 *
 * Separate from `DatePicker` rather than a flag on it because the two format
 * their value differently, validate differently, and render a different
 * control — a flag would make every one of those a branch.
 */
final class DateTimePicker extends Field
{
    private ?string $minDate = null;

    private ?string $maxDate = null;

    private bool $seconds = false;

    public function type(): FieldType
    {
        return FieldType::DateTime;
    }

    public function minDate(?string $date): self
    {
        $this->minDate = $date;

        return $this;
    }

    public function maxDate(?string $date): self
    {
        $this->maxDate = $date;

        return $this;
    }

    public function seconds(bool $seconds = true): self
    {
        $this->seconds = $seconds;

        return $this;
    }

    private function format(): string
    {
        return $this->seconds ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['date'];

        if ($this->minDate !== null) {
            $rules[] = 'after_or_equal:'.$this->minDate;
        }

        if ($this->maxDate !== null) {
            $rules[] = 'before_or_equal:'.$this->maxDate;
        }

        return $rules;
    }

    /**
     * The control binds to `datetime-local`, which only accepts its own
     * format — a stored timestamp has to be shaped for it here rather than
     * being handed over as whatever the database returned.
     */
    protected function castForForm(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format($this->format());
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'minDate' => $this->minDate,
            'maxDate' => $this->maxDate,
            'seconds' => $this->seconds,
        ];
    }
}
