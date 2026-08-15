# Columns

A column is one thing a table shows about a record: how it is described to the frontend, how a record becomes a serializable cell, and what per-row extras — tooltip, link, attributes — that cell carries. You reach for a column type by what the value *is*, because the type is the discriminant a Vue renderer switches on.

Every type extends `PandaPanel\Tables\Columns\Column`, so everything on the base class is available on all of them.

## A minimal set of columns

```php
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\TableSchema;

return $table->columns([
    TextColumn::make('reference')->searchable()->sortable()->toggleable(false),
    TextColumn::make('customer.name')->label('Customer')->searchable(),
    BadgeColumn::make('status')
        ->labels(['open' => 'Open', 'done' => 'Done'])
        ->colors(['open' => BadgeColor::Info, 'done' => BadgeColor::Success])
        ->sortable(),
    NumberColumn::make('total')->prefix('$')->decimals(2)->sortable(),
    DateTimeColumn::make('created_at')->label('Placed')->sortable(),
]);
```

`make()` takes the attribute name. It is read with `data_get()`, so `customer.name` walks the relation. The label defaults to `Str::headline()` of the name.

## The column types

| Class | `type()` | Cell shape |
| --- | --- | --- |
| `TextColumn` | `text` | `string\|null` |
| `NumberColumn` | `number` | `{display: string, raw: int\|float}\|null` |
| `BadgeColumn` | `badge` | `{value, label, color}\|null` |
| `BooleanColumn` | `boolean` | `{value: bool, label: string}` |
| `DateColumn` | `date` | `{display, iso}\|null` |
| `DateTimeColumn` | `datetime` | `{display, iso}\|null` |
| `ImageColumn` | `image` | `{url, fallback, alt}` |
| `IconColumn` | `icon` | `{icon, color, label}\|null` |
| `ColorColumn` | `color` | `{color, label}\|null` |
| `CustomColumn` | `custom` | whatever `state()` returns |
| `ToggleColumn`, `CheckboxColumn`, `TextInputColumn`, `SelectColumn` | editable | see [Editable columns](editable-columns.md) |

The values are the cases of `PandaPanel\Tables\Enums\ColumnType`.

## `TextColumn`

```php
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('excerpt')
    ->limit(80)     // truncates on the server, so the payload stays small
    ->wrap();       // let the cell wrap instead of clipping to one line
```

| Method | Signature | Default |
| --- | --- | --- |
| `limit()` | `limit(int $characters): self` | no limit |
| `wrap()` | `wrap(bool $wrap = true): self` | `false` |

A non-scalar value is JSON-encoded; an empty string is treated as no value at all, so the placeholder shows.

## `NumberColumn`

```php
use PandaPanel\Tables\Columns\NumberColumn;

NumberColumn::make('total')->prefix('$')->decimals(2)->suffix(' USD');
```

| Method | Signature | Default |
| --- | --- | --- |
| `decimals()` | `decimals(int $decimals): self` | `0` |
| `prefix()` | `prefix(string $prefix): self` | `null` |
| `suffix()` | `suffix(string $suffix): self` | `null` |

Aligned `end` by default. Formatting happens on the server with `number_format()`, and the cell carries both the finished `display` string and the `raw` number. A value that is not numeric renders as no value.

## `BadgeColumn`

```php
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Enums\BadgeColor;

BadgeColumn::make('email_verified_at')
    ->label('Status')
    ->formatUsing(static fn (mixed $value): string => $value === null ? 'unverified' : 'verified')
    ->labels(['verified' => 'Verified', 'unverified' => 'Unverified'])
    ->colors(['verified' => BadgeColor::Success, 'unverified' => BadgeColor::Warning]);
```

| Method | Signature |
| --- | --- |
| `colors()` | `colors(array $colors): self` — keyed by value, `BadgeColor` or its string |
| `labels()` | `labels(array $labels): self` — keyed by value |

`BadgeColor` is a closed set: `Neutral`, `Success`, `Warning`, `Danger`, `Info`. An unmapped value renders neutral with a `Str::headline()` label, so a new enum case degrades to a plain badge rather than an unstyled one. Booleans key as `'true'` and `'false'`; a backed enum keys on its `value`.

## `BooleanColumn`

```php
use PandaPanel\Tables\Columns\BooleanColumn;

BooleanColumn::make('is_active')->labels('Active', 'Suspended');
```

`labels(string $true, string $false): self` — defaults `Yes` and `No`. Aligned `center`. The value is cast with `(bool)`, so this column always produces a cell.

## `DateColumn` and `DateTimeColumn`

```php
use PandaPanel\Tables\Columns\DateColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;

DateColumn::make('due_on')->format('d/m/Y');
DateTimeColumn::make('created_at')->relative();
```

| Method | Signature | Default |
| --- | --- | --- |
| `format()` | `format(string $format): static` | `M j, Y` (`M j, Y H:i` for `DateTimeColumn`) |
| `relative()` | `relative(bool $relative = true): static` | `false` |

The cell carries `display` and `iso`, so the frontend can show the exact timestamp on hover without a second request. The attribute must resolve to a `Carbon\CarbonInterface` — cast it on the model, or the cell renders as empty.

## `ImageColumn`

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Tables\Columns\ImageColumn;

ImageColumn::make('avatar')
    ->label('')
    ->circular()
    ->size(40)
    ->fallbackUsing(static fn (Model $record): string => Str::upper(Str::substr($record->email, 0, 2)));
```

| Method | Signature | Default |
| --- | --- | --- |
| `circular()` | `circular(bool $circular = true): self` | `false` |
| `size()` | `size(int $pixels): self` | `32` |
| `fallbackUsing()` | `fallbackUsing(Closure $callback): self` | initials from the record's `name` |

The value is used as the `url` when it is a non-empty string. `alt` falls back to the record's `name` attribute, then to the column label.

## `IconColumn`

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\IconColumn;
use PandaPanel\Tables\Enums\BadgeColor;

IconColumn::make('status')
    ->icons(['published' => 'check', 'draft' => 'pencil'])
    ->colors(['published' => BadgeColor::Success, 'draft' => BadgeColor::Warning]);

IconColumn::make('two_factor_confirmed_at')->boolean(trueIcon: 'shield', falseIcon: 'shield-off');

IconColumn::make('score')->iconUsing(
    static fn (mixed $value, Model $record): ?string => $value > 80 ? 'trending-up' : 'trending-down',
);
```

| Method | Signature |
| --- | --- |
| `icons()` | `icons(array $icons): self` — value to icon registry key |
| `colors()` | `colors(array $colors): self` — value to `BadgeColor` |
| `iconUsing()` | `iconUsing(Closure $callback): self` — `fn (mixed $value, Model $record): ?string` |
| `boolean()` | `boolean(string $trueIcon = 'check', string $falseIcon = 'x'): self` |

Aligned `center`. The closure returns a registry *key*, never markup or a path, so a table cannot ask the browser for an icon that was not compiled in and `panel:icons` can find the names by reading the source. `boolean()` colours itself `Success`/`Danger` and labels itself `Yes`/`No`. A value with no mapping renders nothing.

## `ColorColumn`

```php
use PandaPanel\Tables\Columns\ColorColumn;

ColorColumn::make('brand_color')->copyable();
```

`copyable(bool $copyable = true): self` shows the value as text beside the swatch.

The value is validated on the server as hex (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`), `rgb()`/`rgba()`, or `hsl()`/`hsla()`. Anything else renders nothing. That whitelist matters more here than elsewhere: the value ends up in an inline `background-color`, and an unvalidated string there is arbitrary CSS from a database row.

## `CustomColumn`

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\CustomColumn;

CustomColumn::make('trend')
    ->component('Panels/Admin/Columns/Sparkline')
    ->state(static fn (Model $record): array => ['points' => $record->view_counts]);
```

| Method | Signature |
| --- | --- |
| `component()` | `component(string $component): self` |
| `state()` | `state(Closure $callback): self` — `fn (Model $record): mixed` |

The component is a build-time registry key under `resources/js/pages/Panels/{Panel}/Columns/`, resolved through a Vite glob. Without `state()`, the cell is the column's ordinary resolved value. Whatever `state()` returns must serialize to scalars and arrays like any other cell.

## What every column can do

### Naming and labels

```php
TextColumn::make('created_at')->label('Registered');
```

`getName()`, `getLabel()`. An empty name throws `PanelSchemaException::emptyName('column')`.

### Sorting

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Enums\SortDirection;

TextColumn::make('name')->sortable();
TextColumn::make('display_name')->sortable(column: 'name');   // order by a different column
TextColumn::make('status')->sortUsing(
    static fn (Builder $query, SortDirection $direction) => $query
        ->orderByRaw("field(status, 'urgent', 'open', 'closed') {$direction->value}"),
);
```

| Method | Signature |
| --- | --- |
| `sortable()` | `sortable(bool $sortable = true, ?string $column = null): static` |
| `sortUsing()` | `sortUsing(Closure $callback): static` — also makes the column sortable |
| `sortableByRelation()` | `sortableByRelation(string $relation, string $column): static` |

Sorting is a declaration, not behaviour: `TableQuery` reads it as a whitelist. See [Sorting](sorting.md).

### Searching

```php
TextColumn::make('name')->searchable();
TextColumn::make('name')->searchable(columns: ['first_name', 'last_name']);
TextColumn::make('reference')->searchable(individually: true);
TextColumn::make('author.name')->searchable();   // routed to whereHas
```

`searchable(bool $searchable = true, ?array $columns = null, bool $individually = false): static`. A dotted name is matched with `whereHas` rather than a `LIKE` on a column this table does not have. See [Search](search.md).

### Visibility

```php
TextColumn::make('id')->toggleable(false);   // the user can never hide it
TextColumn::make('notes')->visible(false);   // hidden until the user shows it
```

| Method | Signature | Default |
| --- | --- | --- |
| `visible()` | `visible(bool $visible = true): static` | `true` |
| `toggleable()` | `toggleable(bool $toggleable = true): static` | `true` |

See [Column manager](column-manager.md).

### Empty values

```php
TextColumn::make('title')->placeholder('Untitled');
TextColumn::make('title')->default('—');
```

They are not the same thing. `default()` substitutes for a null value *before* the cell is formatted, so it goes through whatever `formatUsing()` does; `placeholder()` is presentation for the absence itself and is rendered instead of a cell. The placeholder lives on the base class so an empty date and an empty text column read the same way.

### Formatting

```php
use Illuminate\Database\Eloquent\Model;

TextColumn::make('status')->formatUsing(
    static fn (mixed $value, Model $record): string => ucfirst((string) $value),
);
```

`formatUsing(Closure $callback): static`. It runs on the server; only its result is serialized.

### Layout

```php
use PandaPanel\Tables\Enums\Alignment;

NumberColumn::make('total')
    ->alignment(Alignment::End)
    ->headerAlignment(Alignment::Start)
    ->width('12rem')
    ->wrapHeader()
    ->headerTooltip('Including tax');
```

| Method | Signature | Default |
| --- | --- | --- |
| `alignment()` | `alignment(Alignment\|string $alignment): static` | `Start` (`End` on `NumberColumn`, `Center` on `BooleanColumn`, `IconColumn`, `ToggleColumn`, `CheckboxColumn`) |
| `headerAlignment()` | `headerAlignment(Alignment\|string $alignment): static` | follows the cell alignment |
| `width()` | `width(string $width): static` | `null` |
| `wrapHeader()` | `wrapHeader(bool $wrap = true): static` | `false` |
| `headerTooltip()` | `headerTooltip(string $tooltip): static` | `null` |

`Alignment` is logical — `Start`, `Center`, `End`, `Justify` — so a right-to-left locale flips without every table being rewritten. `'left'` and `'right'` are accepted and mean what they always meant; anything unrecognised falls back to `Start`. `width()` is any CSS length applied inline, never a Tailwind class: `w-${n}` would have to be interpolated, and an interpolated class does not exist in the bundle.

### Per-row extras

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Model;

TextColumn::make('title')
    ->tooltip(static fn (Model $record): string => $record->slug)
    ->url(static fn (Model $record): string => PostResource::url('view', $record))
    ->extraAttributes(static fn (Model $record): array => ['data-status' => $record->status]);
```

| Method | Signature |
| --- | --- |
| `tooltip()` | `tooltip(Closure\|string $tooltip): static` |
| `url()` | `url(Closure $callback): static` — `fn (Model $record): ?string` |
| `extraAttributes()` | `extraAttributes(Closure\|array $attributes): static` |
| `action()` | `action(Action $action): static` |

These travel in `cellMeta` beside `cells`, not inside them, so a cell value stays exactly the shape its renderer's guard narrows and a table using none of this ships an empty map. `toCellMeta()` returns `null` when a column has none.

`extraAttributes` is spread onto an element, so it takes scalars only and refuses any key starting with `on` — an event handler there would be a way to put executable content on a page from a schema.

### Cell actions

```php
use PandaPanel\Actions\Action;
use Illuminate\Database\Eloquent\Model;

TextColumn::make('reference')->action(
    Action::make('approve')->action(static fn (Model $record) => $record->approve()),
);
```

The action is resolved per record, so a cell the user may not act on renders as an ordinary value rather than a button that answers 403. `TableSchema::getRecordAction()` finds column actions as well as row actions, because a column action names a row, authorizes it, and changes it. Give a column an action *or* a `url()`, not both — a cell that went somewhere and did something would be a coin toss.

### Freezing

```php
use PandaPanel\Tables\Enums\ColumnPin;

TextColumn::make('reference')->frozen();
NumberColumn::make('balance')->frozen(ColumnPin::End);
TextColumn::make('notes')->frozen(false);
```

`frozen(ColumnPin|bool $pin = true): static`. `true` means `ColumnPin::Start`, `false` unpins. See [Frozen and pinned columns](pinned-columns.md).

### Summaries

```php
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Sum;

NumberColumn::make('total')->summarize([Sum::make(), Average::make()]);
```

`summarize(array $summarizers): static`. See [Summaries](summaries.md).

### Relationship state

```php
NumberColumn::make('posts_count')->counts('posts')->sortable();
BooleanColumn::make('posts_exists')->exists('posts');
NumberColumn::make('orders_sum_total')->sum('orders', 'total');
TextColumn::make('author_name')->sortableByRelation('author', 'name');
```

`counts()`, `exists()`, `sum()`, `avg()`, `min()`, `max()` are computed in the select by `TableSchema::applyColumnQueries()`, so they cost one query for the whole page. The result lands on the attribute Eloquent generates, and the cell reads that attribute rather than the column's own name. See [Relationship columns](relationships.md).

## Gotchas

- **`sortable()` resets a sort column set earlier.** Its second parameter defaults to `null` and is assigned unconditionally, so `->counts('posts')->sortable()` clears the aggregate alias that `counts()` had filled in. Name an aggregate column after the attribute Eloquent generates (`posts_count`) and the two agree either way.
- **`default()` goes through `formatUsing()`, `placeholder()` does not.** Reach for the placeholder when you want to say "nothing here", and for the default when you want a stand-in value treated as real.
- **A `DateColumn` over an uncast attribute renders empty.** The cell requires a `CarbonInterface`; a raw string is not one.
- **`ColorColumn` silently renders nothing for an unrecognised value.** That is deliberate — the alternative is repairing a string into something plausible and putting it in a style attribute.
- **A duplicate column name throws at the `columns()` setter**, so the stack trace points at the line in the resource that declared it.
- **Every column is `toggleable()` by default.** Mark the identifying column `toggleable(false)` or a user can hide the only thing that says which row is which.

## See also

- [TableSchema basics](overview.md)
- [Editable columns](editable-columns.md)
- [Relationship columns](relationships.md)
- [Summaries](summaries.md)
- [Frozen and pinned columns](pinned-columns.md)
- [Column manager](column-manager.md)
- [Sorting](sorting.md) and [Search](search.md)
- [Record actions](record-actions.md)
- [Table API reference](api.md)
