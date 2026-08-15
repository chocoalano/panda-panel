# Query Builder Filter

`QueryBuilderFilter` lets the user compose the conditions themselves: a flat list of rules, each naming a column, an operator, and a value. Reach for it when you cannot predict what a reader will want to ask of the table — a support view, a report, an audit log. When the shape of the question is known, a [`SelectFilter` or a `FormFilter`](filters.md) is the better answer.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Tables;

use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\QueryBuilderFilter;
use PandaPanel\Tables\TableSchema;

final class UsersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
            ])
            ->filters([
                QueryBuilderFilter::make('conditions')
                    ->label('Advanced')
                    ->constraints([
                        TextConstraint::make('name'),
                        TextConstraint::make('email'),
                    ]),
            ]);
    }
}
```

The table now offers an "Advanced" filter where the user adds rules such as `Name contains ada`. Rules are ANDed and applied inside their own group, so they narrow whatever the search and the other filters already left.

## How a rule is checked

Every part of a submitted rule is looked up against a declaration before it reaches the builder. Nothing is concatenated from the request: the column string comes from the `Constraint` object and the comparison from a closed enum.

| Part of the rule | Checked against | Failure |
| --- | --- | --- |
| `column` | `QueryBuilderFilter::constraint($name)` | rule dropped |
| `operator` | `ConstraintOperator::tryFrom()`, then `Constraint::supports()` | rule dropped |
| `value` | `Constraint::accepts($operator, $value)` | rule dropped |

A rule failing any of those is dropped, not repaired — a query the user did not describe is worse than no rule. When every rule is dropped, `sanitize()` returns `null`, the filter applies nothing, and it reports as inactive, so the frontend never shows a chip for a condition the query ignored.

## `QueryBuilderFilter`

```php
use PandaPanel\Tables\Enums\FilterType;
use PandaPanel\Tables\Filters\Constraints\Constraint;
use PandaPanel\Tables\Filters\QueryBuilderFilter;

QueryBuilderFilter::make(string $name): static
QueryBuilderFilter::constraints(array $constraints): self   // array<array-key, Constraint>
QueryBuilderFilter::maxRules(int $max): self                // default 10, floored at 1
QueryBuilderFilter::constraint(string $name): ?Constraint
QueryBuilderFilter::sanitize(mixed $value): ?array
QueryBuilderFilter::type(): FilterType                      // FilterType::QueryBuilder
```

```php
use PandaPanel\Tables\Filters\Constraints\BooleanConstraint;
use PandaPanel\Tables\Filters\Constraints\DateConstraint;
use PandaPanel\Tables\Filters\Constraints\NumberConstraint;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\QueryBuilderFilter;

QueryBuilderFilter::make('conditions')
    ->label('Advanced')
    ->maxRules(5)
    ->constraints([
        TextConstraint::make('name'),
        TextConstraint::make('email'),
        NumberConstraint::make('login_count')->label('Sign-ins'),
        DateConstraint::make('created_at')->label('Registered'),
        BooleanConstraint::make('is_admin')->label('Administrator'),
    ]);
```

`maxRules()` bounds one filter so it cannot become an unbounded pile of conditions. Rules past the limit are discarded while parsing; the frontend also stops offering "add condition" once the list reaches it.

Everything on the base `Filter` is inherited and works here:

```php
use Illuminate\Database\Eloquent\Builder;

QueryBuilderFilter::make('conditions')
    ->label('Advanced')
    ->constraints([TextConstraint::make('name')])
    ->default([['column' => 'name', 'operator' => 'is_filled']])
    ->modifyBaseQueryUsing(static fn (Builder $query) => $query->withoutGlobalScope('published'));
```

`default()` takes the same array shape a request would send and is validated the same way. `query()` replaces the whole constraint — it receives the *sanitized* rules, which are `['constraint' => Constraint, 'operator' => ConstraintOperator, 'value' => mixed]` triples rather than raw request arrays, so a custom `query()` here is rarely what you want.

## Constraints

A `Constraint` is one column the user is allowed to talk about. Four are shipped.

| Class | `inputType()` | For |
| --- | --- | --- |
| `PandaPanel\Tables\Filters\Constraints\TextConstraint` | `text` | strings |
| `PandaPanel\Tables\Filters\Constraints\NumberConstraint` | `number` | numeric columns |
| `PandaPanel\Tables\Filters\Constraints\DateConstraint` | `date` | dates and datetimes |
| `PandaPanel\Tables\Filters\Constraints\BooleanConstraint` | `none` | flags |

`inputType()` is the `type` attribute of the value input the frontend renders. `none` means the operators carry their own answer and no value box is drawn.

Every constraint shares this API:

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\ConstraintOperator;
use PandaPanel\Tables\Filters\Constraints\Constraint;

Constraint::make(string $name): static
Constraint::label(string $label): static
Constraint::column(string $column): static      // when the database column differs from the name
Constraint::getName(): string
Constraint::getLabel(): string                  // Str::headline($name) unless labelled
Constraint::getColumn(): string                 // $column ?? $name
Constraint::operators(): array                  // list<ConstraintOperator>
Constraint::inputType(): string
Constraint::supports(ConstraintOperator $operator): bool
Constraint::accepts(ConstraintOperator $operator, mixed $value): bool
Constraint::apply(Builder $query, ConstraintOperator $operator, mixed $value): void
Constraint::toArray(): array
```

```php
use PandaPanel\Tables\Filters\Constraints\TextConstraint;

// The request says "reference"; the query touches `orders.slug`.
TextConstraint::make('reference')->label('Order reference')->column('slug');
```

### Which operators each constraint offers

| Operator | Reads as | `TextConstraint` | `NumberConstraint` | `DateConstraint` | `BooleanConstraint` |
| --- | --- | :-: | :-: | :-: | :-: |
| `Contains` | contains | ✓ | | | |
| `DoesNotContain` | does not contain | ✓ | | | |
| `StartsWith` | starts with | ✓ | | | |
| `EndsWith` | ends with | ✓ | | | |
| `EqualTo` | is | ✓ | ✓ | ✓ | |
| `NotEqualTo` | is not | ✓ | ✓ | | |
| `GreaterThan` | is after | | ✓ | ✓ | |
| `GreaterThanOrEqual` | is at least | | ✓ | ✓ | |
| `LessThan` | is before | | ✓ | ✓ | |
| `LessThanOrEqual` | is at most | | ✓ | ✓ | |
| `IsFilled` | is filled | ✓ | ✓ | ✓ | ✓ |
| `IsBlank` | is blank | ✓ | ✓ | ✓ | ✓ |
| `IsTrue` | is true | | | | ✓ |
| `IsFalse` | is false | | | | ✓ |

### What each operator becomes

`PandaPanel\Tables\Enums\ConstraintOperator` is the only place an operator ever comes from, and each case maps to one builder call.

| Case | Value | Builder call | `needsValue()` |
| --- | --- | --- | :-: |
| `Contains` | `contains` | `where($c, 'like', '%value%')` | true |
| `DoesNotContain` | `does_not_contain` | `whereNot($c, 'like', '%value%')` | true |
| `StartsWith` | `starts_with` | `where($c, 'like', 'value%')` | true |
| `EndsWith` | `ends_with` | `where($c, 'like', '%value')` | true |
| `EqualTo` | `equal_to` | `where($c, '=', $value)` | true |
| `NotEqualTo` | `not_equal_to` | `where($c, '!=', $value)` | true |
| `GreaterThan` | `greater_than` | `where($c, '>', $value)` | true |
| `GreaterThanOrEqual` | `greater_than_or_equal` | `where($c, '>=', $value)` | true |
| `LessThan` | `less_than` | `where($c, '<', $value)` | true |
| `LessThanOrEqual` | `less_than_or_equal` | `where($c, '<=', $value)` | true |
| `IsFilled` | `is_filled` | `whereNotNull($c)` | false |
| `IsBlank` | `is_blank` | `whereNull($c)` | false |
| `IsTrue` | `is_true` | `where($c, '=', true)` | false |
| `IsFalse` | `is_false` | `where($c, '=', false)` | false |

The three `LIKE` operators escape `\`, `%`, and `_` in the value, so a term containing a wildcard matches literally instead of scanning the table.

`ConstraintOperator::label()` returns the human reading in the second column of the first table; `needsValue()` decides whether a value input is drawn and whether `accepts()` demands one.

### Which values a constraint accepts

```php
// Constraint (base): a value is required unless the operator carries its own answer.
public function accepts(ConstraintOperator $operator, mixed $value): bool
{
    return ! $operator->needsValue() || (is_scalar($value) && $value !== '');
}
```

`NumberConstraint` narrows that to `is_numeric($value)` — a comparison against a non-number is not a narrower query, it is a meaningless one, so it is refused rather than coerced to zero. `DateConstraint` narrows it to a string `strtotime()` can read, because anything else would be compared as a string, which sorts nothing like a date.

### Writing your own constraint

Two abstract methods, and only those two are required:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Tables\Constraints;

use PandaPanel\Tables\Enums\ConstraintOperator;
use PandaPanel\Tables\Filters\Constraints\Constraint;

final class StatusConstraint extends Constraint
{
    public function inputType(): string
    {
        return 'text';
    }

    /**
     * @return list<ConstraintOperator>
     */
    public function operators(): array
    {
        return [
            ConstraintOperator::EqualTo,
            ConstraintOperator::NotEqualTo,
            ConstraintOperator::IsBlank,
        ];
    }

    public function accepts(ConstraintOperator $operator, mixed $value): bool
    {
        return ! $operator->needsValue()
            || in_array($value, ['open', 'closed', 'archived'], true);
    }
}
```

Override `apply()` only when a rule needs a comparison the enum cannot express; the operator has already been checked against `operators()` before it reaches the method, so the `match` there is total.

## What travels in the URL

The rules live in the table's filter map, so they survive back, forward, refresh, and bookmark like every other piece of table state:

```text
?filters[conditions][0][column]=name
&filters[conditions][0][operator]=contains
&filters[conditions][0][value]=ada
&filters[conditions][1][column]=created_at
&filters[conditions][1][operator]=greater_than
&filters[conditions][1][value]=2026-01-01
```

Turning `persistFiltersInSession()` on remembers that map with the rest of the filters. See [Persisted state](persisted-state.md).

## The indicator

`Filter::indicator()` builds the chip text on the server, because only the filter knows what its value means. For a query builder it is the label, a colon, and each surviving rule joined with `and`:

```text
Advanced: Name contains ada and Registered is after 2026-01-01
```

Rules that were dropped are absent from it, which is the visible signal that one was not applied.

## Serialized definition

`toArray()` adds two keys to the base filter definition:

```php
[
    'name' => 'conditions',
    'label' => 'Advanced',
    'type' => 'query_builder',
    'default' => null,
    'constraints' => [
        [
            'name' => 'name',
            'label' => 'Name',
            'input' => 'text',
            'operators' => [
                ['value' => 'contains', 'label' => 'contains', 'needsValue' => true],
                // ...
            ],
        ],
    ],
    'maxRules' => 10,
]
```

No closure, no query, no model class — the frontend renders the columns and comparisons it was given and can invent neither.

## Notes

- **Nested and/or groups are deliberately absent.** They need a recursive schema on both sides and a UI to match, and a flat list of ANDed conditions answers the question most tables are actually asked. Reach for `FormFilter` when the shape is known and a custom `query()` when it is not.
- **A constraint names a column of the table being queried.** There is no relation traversal: `TextConstraint::make('author.name')` would reach the builder as a table-qualified column, not as a `whereHas`. Search a relation with a [dotted searchable column](relationships.md) or narrow it with a `FormFilter`.
- **`DateConstraint` accepts anything `strtotime()` parses**, which includes relative strings such as `yesterday`. That is intentional — the value still goes to the builder as a bound parameter — but a constraint that must only take calendar dates should override `accepts()`.
- **`IsTrue` and `IsFalse` compare against `true` and `false`.** A `NULL` flag matches neither; ask for it with `IsBlank`.
- **A dropped rule is silent.** The user sees the condition disappear from the indicator rather than an error. That is the deliberate trade: the alternative is repairing a rule into a query nobody described.
- **`maxRules()` truncates.** Rules beyond the limit are discarded while parsing rather than making the whole filter fail.

## See also

- [Filters](filters.md)
- [Search](search.md)
- [Sorting](sorting.md)
- [Persisted state](persisted-state.md)
- [Relationship columns](relationships.md)
- [Table API reference](api.md)
- [Tables overview](overview.md)
