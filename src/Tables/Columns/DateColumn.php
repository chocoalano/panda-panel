<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Enums\ColumnType;

class DateColumn extends Column
{
    protected string $format = 'M j, Y';

    protected bool $relative = false;

    public function type(): ColumnType
    {
        return ColumnType::Date;
    }

    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Shows "3 days ago" as the display value. The ISO value is still sent,
     * so the frontend can show the exact timestamp on hover without a second
     * request.
     */
    public function relative(bool $relative = true): static
    {
        $this->relative = $relative;

        return $this;
    }

    /**
     * @return array{display: string, iso: string}|null
     */
    public function toCell(Model $record): ?array
    {
        $value = $this->resolveValue($record);

        if (! $value instanceof CarbonInterface) {
            return null;
        }

        return [
            'display' => $this->relative ? $value->diffForHumans() : $value->format($this->format),
            'iso' => $value->toIso8601String(),
        ];
    }
}
