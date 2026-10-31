<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Enums;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What a column computes over a relation.
 *
 * A closed set because each case maps to a specific Eloquent call and to the
 * attribute name Eloquent generates for it. A free-form function name would
 * be a SQL function chosen by a schema and rendered into a select.
 */
enum RelationshipAggregate: string
{
    case Count = 'count';
    case Exists = 'exists';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';

    /**
     * The attribute the result lands on.
     *
     * Eloquent's own rule — strip punctuation, then snake-case the relation,
     * function, and column — so `counts('lineItems')` reads
     * `line_items_count`, the name a Laravel developer expects. A dot becomes
     * an underscore first rather than vanishing, so a dotted path keeps a
     * readable name. `apply()` then hands this name to the query as an
     * explicit alias, so the cell and the select cannot drift apart even if
     * Eloquent's rule ever changes.
     */
    public function attributeFor(string $relation, ?string $column): string
    {
        $name = sprintf('%s %s %s', $relation, $this->value, strtolower($column ?? ''));

        return Str::snake((string) preg_replace(
            '/[^[:alnum:][:space:]_]/u',
            '',
            str_replace('.', '_', $name),
        ));
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function apply(Builder $query, string $relation, ?string $column): void
    {
        $aliased = $relation.' as '.$this->attributeFor($relation, $column);

        match ($this) {
            self::Count => $query->withCount($aliased),
            self::Exists => $query->withExists($aliased),
            default => $column === null
                ? null
                : $query->withAggregate($aliased, $column, $this->value),
        };
    }
}
