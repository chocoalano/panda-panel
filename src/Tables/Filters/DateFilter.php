<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Filters;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\FilterType;
use Throwable;

final class DateFilter extends Filter
{
    public function type(): FilterType
    {
        return FilterType::Date;
    }

    /**
     * Accepts `{from?: string, to?: string}` with ISO dates.
     *
     * Each bound is parsed strictly and dropped if it is not a real date, so
     * a malformed range narrows to whatever part of it was valid instead of
     * failing the whole request.
     *
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null}|null
     */
    public function sanitize(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $from = $this->parse($value['from'] ?? null);
        $to = $this->parse($value['to'] ?? null);

        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $column = $this->getColumn();

        if ($value['from'] instanceof CarbonImmutable) {
            $query->where($column, '>=', $value['from']->startOfDay());
        }

        if ($value['to'] instanceof CarbonImmutable) {
            $query->where($column, '<=', $value['to']->endOfDay());
        }
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10)) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
