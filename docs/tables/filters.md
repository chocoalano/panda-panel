# Filters

A filter is the only thing allowed to turn a URL value into a query constraint. You reach for one whenever the table needs to answer "show me a subset" — a status, a date range, a yes/no, or a whole form's worth of criteria. Everything a filter accepts is declared, and a value it rejects never reaches the builder.

## A minimal filtered table

```php
use PandaPanel\Tables\Filters\DateFilter;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\TableSchema;

return $table
    ->columns([/* ... */])
    ->filters([
        SelectFilter::make('status')->options([
            'open' => 'Open',
            'done' => 'Done',
        ]),

        TernaryFilter::make('published_at')
            ->label('Published')
            ->nullable()
            ->labels('Published', 'Draft', 'Anyone'),

        DateFilter::make('created')->label('Created between')->column('created_at'),
    ]);
```

The state lives in the query string as `?filters[status]=open&filters[created][from]=2026-01-01`. Two filters may not share a name: filter state is keyed by name, so the second control would write over the first.

## The filter types

| Class | `type()` | Value it accepts |
| --- | --- | --- |
| `SelectFilter` | `select` | one declared option key |
| `BooleanFilter` | `boolean` | `true`/`false`/`'1'`/`'0'`/`'true'`/`'false'`/`1`/`0` |
| `TernaryFilter` | `ternary` | `'true'` or `'false'` (plus `1`/`0` synonyms) |
| `DateFilter` | `date` | `{from?: string, to?: string}` as `Y-m-d` |
| `TrashedFilter` | `select` | `'without'`, `'with'`, `'only'` |
| `FormFilter` | `form` | the validated data of its own `FormSchema` |
| `QueryBuilderFilter` | `query_builder` | a list of declared rules |

Those strings are the cases of `PandaPanel\Tables\Enums\FilterType`, and they are the discriminant the Vue filter renderer switches on.

## `SelectFilter`

```php
use PandaPanel\Tables\Filters\SelectFilter;

SelectFilter::make('status')
    ->label('Order status')
    ->options(['open' => 'Open', 'shipped' => 'Shipped', 'done' => 'Done'])
    ->placeholder('Any status');
```

| Method | Signature |
| --- | --- |
| `options()` | `options(array $options): self` — keyed by the value stored |
| `placeholder()` | `placeholder(string $placeholder): self` |

Only a declared option key is accepted. A value that would be a perfectly valid column value but is not in `options()` is rejected: the declared options are the whitelist. The default constraint is `where($column, '=', $value)`.

## `BooleanFilter`

```php
use PandaPanel\Tables\Filters\BooleanFilter;

BooleanFilter::make('verified')
    ->label('Email verification')
    ->column('email_verified_at')
    ->nullable()
    ->labels('Verified', 'Unverified');
```

| Method | Signature | Default |
| --- | --- | --- |
| `labels()` | `labels(string $true, string $false): self` | `Yes`, `No` |
| `nullable()` | `nullable(bool $nullable = true): self` | `false` |

`nullable()` treats the column as *set* versus *not set* (`whereNotNull` / `whereNull`) rather than true versus false, which is what lets a nullable timestamp act as a boolean filter without a second column. Without it, the constraint is `where($column, '=', $value)`.

## `TernaryFilter`

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Filters\TernaryFilter;

TernaryFilter::make('email_verified_at')
    ->nullable()
    ->labels('Verified', 'Unverified', 'Anyone')
    ->default(TernaryFilter::TRUE);

TernaryFilter::make('has_manager')->queries(
    static fn (Builder $query) => $query->whereHas('manager'),
    static fn (Builder $query) => $query->whereDoesntHave('manager'),
);
```

| Member | Signature |
| --- | --- |
| `TernaryFilter::TRUE` | `'true'` |
| `TernaryFilter::FALSE` | `'false'` |
| `labels()` | `labels(string $true, string $false, ?string $blank = null): self` — defaults `Yes`, `No`, `All` |
| `nullable()` | `nullable(bool $nullable = true): self` |
| `queries()` | `queries(Closure $true, Closure $false): self` |

Three states where the third is an *answer* the table means rather than an empty control. Each branch can own its query, so the two answers need not be inverses: "has a manager" and "has none" are `whereHas` and `whereDoesntHave`. `1` and `0` are accepted as synonyms, because those are what arrive from a bookmarked URL over a boolean column.

## `DateFilter`

```php
use PandaPanel\Tables\Filters\DateFilter;

DateFilter::make('registered')->label('Registered between')->column('created_at');
```

The value is `{from?: string, to?: string}` with `Y-m-d` dates. Each bound is parsed strictly and dropped if it is not a real date, so a malformed range narrows to whatever part of it was valid instead of failing the request. A reversed range is swapped. The constraint compares against `startOfDay()` and `endOfDay()`, so both bounds are inclusive.

### The control

Each bound is a [shadcn-vue date picker](https://www.shadcn-vue.com/docs/components/date-picker) —
the same `PanelDatePicker.vue` a [`DatePicker` form field](../forms/fields/date.md#what-the-control-is)
mounts, so a date is chosen the same way here as on a form. It replaced a pair
of `<input type="date">`, whose appearance was the browser's rather than the
panel's.

Each picker bounds the other: choosing a **from** date greys out earlier days in
the **to** calendar, and the reverse. That is a convenience rather than the
rule — a reversed range arriving from a hand-edited URL is still swapped by
`sanitize()`, because a control cannot be the thing that enforces this.

Clearing one bound with its `×` leaves the range open-ended on that side.
Clearing both removes the filter, which is the same thing as closing its chip:

```text
from 1 Jan 2026, to cleared   →  filters[registered][from]=2026-01-01
both cleared                  →  filters=          (the filter is gone)
```

The chip reads the range in words — `Registered between: 1 Jan 2026 – 1 Feb 2026`,
or `from 1 Jan 2026` for a one-sided range. See [Indicators](#indicators).

## `TrashedFilter`

```php
use PandaPanel\Tables\Filters\TrashedFilter;

TrashedFilter::make('trashed');
```

| Member | Value | Label |
| --- | --- | --- |
| `TrashedFilter::WITHOUT` | `'without'` | Hidden |
| `TrashedFilter::WITH` | `'with'` | Included |
| `TrashedFilter::ONLY` | `'only'` | Only deleted |

The label defaults to `Deleted records`. Leaving the filter unset keeps Eloquent's own `withoutTrashed` scope, so a table that declares it still hides deleted rows until somebody asks. `with` and `only` lift `SoftDeletingScope` by hand rather than through the `withTrashed()` macro, because the macros only exist on a builder the trait extended. On a model that does not soft delete, the filter does nothing rather than throwing. `php artisan make:panel-resource --soft-deletes` writes it in for you.

## `FormFilter`

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Filters\FormFilter;

FormFilter::make('activity')
    ->label('Passkey activity')
    ->form(static fn (FormSchema $schema): FormSchema => $schema->schema([
        Select::make('has')->options(['yes' => 'Has passkeys', 'no' => 'None registered']),
        DatePicker::make('usedSince')->label('Used since'),
    ]))
    ->query(static function (Builder $query, mixed $data): void {
        if (($data['has'] ?? null) === 'yes') {
            $query->whereHas('passkeys');
        }

        if (is_string($data['usedSince'] ?? null) && $data['usedSince'] !== '') {
            $query->whereHas(
                'passkeys',
                static fn (Builder $q) => $q->whereDate('last_used_at', '>=', $data['usedSince']),
            );
        }
    });
```

| Method | Signature |
| --- | --- |
| `form()` | `form(Closure $callback): self` — `fn (FormSchema $schema): FormSchema` |
| `schema()` | `schema(): FormSchema` — the built schema, for inspection |

For a question that takes more than one answer. The form is an ordinary `FormSchema`, so the fields render, validate, and serialize exactly as they do on a resource form. The value reaching `query()` is the *validated* form data: a key the schema never declared is discarded before the closure sees it, and a form whose every field is blank sanitizes to `null` and narrows nothing.

A form filter has no default constraint. Without a `query()` closure it does nothing, because what its fields mean is the schema author's knowledge.

## `QueryBuilderFilter`

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
        NumberConstraint::make('total'),
        DateConstraint::make('created_at')->label('Created'),
        BooleanConstraint::make('is_active'),
    ]);
```

Rules the user composes, each naming a declared column, a supported operator, and an accepted value. Everything else is dropped. Covered in full on [Query builder filters](query-builder.md).

## What every filter can do

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Filters\SelectFilter;

SelectFilter::make('status')
    ->label('Order status')
    ->column('orders.status')
    ->default('open')
    ->query(static fn (Builder $query, mixed $value) => $query->whereIn('status', [$value, 'pending']))
    ->modifyBaseQueryUsing(static fn (Builder $query) => $query->withoutGlobalScopes());
```

| Method | Signature | Notes |
| --- | --- | --- |
| `make()` | `static make(string $name): static` | the name is the query-string key |
| `label()` | `label(string $label): static` | defaults to `Str::headline()` of the name |
| `column()` | `column(string $column): static` | defaults to the filter name |
| `query()` | `query(Closure $callback): static` | `fn (Builder $query, mixed $value): void`, replaces the default constraint |
| `modifyBaseQueryUsing()` | `modifyBaseQueryUsing(Closure $callback): static` | runs first, outside the constraint grouping |
| `default()` | `default(mixed $value): static` | the value applied while the request says nothing |

Read-side: `getName()`, `getLabel()`, `getColumn()`, `hasDefault()`, `getDefault()`, `type()`, `sanitize(mixed $value)`, `indicator(mixed $value)`, `toArray()`.

`apply()` and `applyBaseQuery()` are `final` on the base class. Both sanitize first, so a custom `query()` closure only ever sees a value the filter accepted.

### `modifyBaseQueryUsing()`

For a filter that has to change what the query *is* rather than narrow what it returns — lifting a global scope, joining a table the other constraints then read. It runs before the search and outside the grouping ordinary constraints run in, because an `orWhere` in the search's group would widen the search rather than the filter.

A filter that declares **only** a base-query modifier applies no ordinary constraint. It has already said where its work happens, and falling through would invent a `where` on a column named after the filter — which is rarely a column at all in that case.

## Defaults

```php
SelectFilter::make('status')->options(['open' => 'Open'])->default('open');
```

A default applies while the request says nothing about filters *at all*. Once any filter is present, an absent one means the user removed it rather than never having set it — otherwise a default could never be cleared. A default reports as *active* in `state()['filters']`, because it is a decision the table made.

`TableSchema::defaultFilters()` returns the map of every filter that declared one.

## Clearing, and the empty-map problem

A query string cannot hold an empty array, so `?filters=` is how a URL spells "filters, and there are none". The frontend writes that key after any filter mutation. Three situations produce an empty map and they do not mean the same thing:

| Request | Meaning | Default applies? |
| --- | --- | --- |
| no `filters` key at all | silence | yes |
| `?filters=` | the user cleared everything | no |
| no key, but a session remembers an empty map | the user cleared everything on a previous visit | no |

## Filter bar behaviour

```php
$table
    ->deferFilters()
    ->filtersTrigger('Refine', 'filter')
    ->filtersApplyLabel('Run')
    ->filtersResetLabel('Start over')
    ->showFiltersResetAction(false)
    ->persistFiltersInSession();
```

| Method | Default |
| --- | --- |
| `deferFilters(bool $defer = true)` | `false` — a filter takes effect as it is set |
| `filtersTrigger(string $label, ?string $icon = null)` | `Filters`, no icon |
| `filtersApplyLabel(string $label)` | `Apply filters` |
| `filtersResetLabel(string $label)` | `Clear` |
| `showFiltersResetAction(bool $show = true)` | `true` |
| `persistFiltersInSession(bool $persist = true)` | `false` |

Defer for a table where each request is expensive enough that half-built criteria should not be run — a query builder with five rules, for instance. Turn the reset action off for a report that is meaningless unfiltered.

## Indicators

```php
$state = $tableQuery->state();

// [['name' => 'verified', 'label' => 'Email verification: Verified']]
$state['filterIndicators'];
```

Indicators are built on the server, because only a filter knows what its value means: `1` is "Verified", not "1". Override `describe(mixed $value): string` on a custom filter to change the right-hand side; the left-hand side is always the filter's label.

Each built-in filter says its value the way its own control spelled it:

| Filter | Chip |
| --- | --- |
| `SelectFilter` | `Status: Published` — the option's label, not its key |
| `BooleanFilter` | `Verified: Yes` — the label pair, not `1` |
| `TernaryFilter` | `Project: Assigned` |
| `TrashedFilter` | `Deleted records: Only deleted` |
| `DateFilter` | `Created: 1 Jan 2026 – 1 Feb 2026`, or `from …` / `until …` |
| `FormFilter` | each filled field, by label |
| `QueryBuilderFilter` | each rule, joined with `and` |

`Filter::describe()` — the inherited one — casts a scalar and returns `''` for
anything else. A custom filter whose value is an array or an enum **must**
override it, or its chip will name the filter and then say nothing after the
colon.

Closing a chip removes that one filter. The last chip closing writes `filters=`
rather than deleting the key, which is what tells the server "filters, and there
are none" — see [Clearing, and the empty-map problem](#clearing-and-the-empty-map-problem).

## Persistence

`persistFiltersInSession()` remembers the filter map as a whole, not filter by filter: "which filters are set" is one decision, and remembering them individually would make removing one indistinguishable from never setting it. The session key is built from the panel id and the resource slug, never from the request. See [Persisted table state](persisted-state.md).

## Writing a filter

Extend `PandaPanel\Tables\Filters\Filter` and implement three methods.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Filters;

use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\FilterType;
use PandaPanel\Tables\Filters\Filter;

final class MinimumTotalFilter extends Filter
{
    public function type(): FilterType
    {
        return FilterType::Select;
    }

    /** Null makes the filter a no-op for this request. */
    public function sanitize(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function constrain(Builder $query, mixed $value): void
    {
        $query->where($this->getColumn(), '>=', $value);
    }

    /** @return array<string, mixed> */
    protected function extraArray(): array
    {
        return ['options' => [
            ['value' => '100', 'label' => 'Over 100'],
            ['value' => '1000', 'label' => 'Over 1000'],
        ]];
    }
}
```

`type()` has to be one of the existing `FilterType` cases, because the frontend renders from a closed union. A filter needing a control that does not exist yet is a `FormFilter` with one field.

## Gotchas

- **A rejected value is a no-op, not an error.** `?filters[status]=invented` narrows nothing and is absent from `state()['filters']`, so the control never renders as active.
- **`query()` replaces the constraint entirely.** The column, the operator, and the null handling are all yours from that point.
- **A base-query-only filter applies nothing else.** Add a `query()` or a `constrain()` if you want both.
- **Filters never narrow a record lookup.** They live in `TableQuery::paginate()`, not `Resource::query()`, so a record filtered off the list — including one hidden by a base-query modifier — is still openable by URL.
- **`FormFilter::sanitize()` runs the form's validation rules.** A rule that fails drops the whole filter rather than the offending field.
- **Two filters with the same name throw `PanelSchemaException::duplicateFilters()`** at the `filters()` setter.

## See also

- [TableSchema basics](overview.md)
- [Query builder filters](query-builder.md)
- [Tabs](tabs.md) — a scope on the resource query rather than a filter
- [Persisted table state](persisted-state.md)
- [Search](search.md) and [Sorting](sorting.md)
- [Forms and schemas](../forms/overview.md) — for `FormFilter`
- [Soft deletes](../resources/soft-deletes.md) — for `TrashedFilter`
- [Table API reference](api.md)
