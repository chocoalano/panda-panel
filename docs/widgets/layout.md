# Column Span and Layout

Widgets are placed in a responsive CSS grid. Each one declares how many columns it occupies, and at which breakpoints. You reach for `$columnSpan` whenever the default — one column — is wrong: a row of four figures needs the whole grid, a chart needs half of it, a status badge is fine as it is.

## A minimal working example

```php
use PandaPanel\Widgets\StatsWidget;

final class UserStats extends StatsWidget
{
    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    protected static int $sort = 10;

    public function stats(): array { /* ... */ }
}
```

One column on a phone, two from `md`, three from `lg`, the full width from `xl`.

## The grid

Every page that renders widgets uses the same grid, written out in `WidgetGrid.vue`:

```text
grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4
```

| Breakpoint | Columns |
| --- | --- |
| `default` | 1 |
| `md` | 2 |
| `lg` | 3 |
| `xl` | 4 |

Four breakpoints, four column counts, and nothing else. A widget's span is resolved to one value per breakpoint before it is serialized.

## `$columnSpan`

```php
/** @var int|string|array<string, int|string> */
protected static int|string|array $columnSpan = 1;
```

Three forms are accepted.

### An integer

```php
protected static int|string|array $columnSpan = 2;
```

The same span at every breakpoint: `['default' => 2, 'md' => 2, 'lg' => 2, 'xl' => 2]`.

### `'full'`

```php
protected static int|string|array $columnSpan = 'full';
```

The whole row at every breakpoint, whatever the column count is. Rendered as `col-span-full`.

### A per-breakpoint array

```php
protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2];
```

Keys must be `default`, `md`, `lg` or `xl`. A breakpoint you leave out inherits the one below it, which is how CSS breakpoints behave anyway:

```php
['default' => 1, 'lg' => 2]
// resolves to ['default' => 1, 'md' => 1, 'lg' => 2, 'xl' => 2]
```

The inheritance starts at `1`, so an array that omits `default` starts there:

```php
['lg' => 3]
// resolves to ['default' => 1, 'md' => 1, 'lg' => 3, 'xl' => 3]
```

Values may be integers or `'full'`, and the two can be mixed:

```php
['default' => 'full', 'lg' => 2];
```

## `columnSpan()`

```php
/** @return array{default: int|string, md: int|string, lg: int|string, xl: int|string} */
public static function columnSpan(): array
```

The normalized span, and what is serialized into the widget definition. It delegates to `PandaPanel\Widgets\Support\ColumnSpan`:

```php
use PandaPanel\Widgets\Support\ColumnSpan;

/**
 * @param int|string|array<string, int|string> $span
 * @return array{default: int|string, md: int|string, lg: int|string, xl: int|string}
 */
public static function normalize(int|string|array $span, string $context = 'A widget'): array
```

`$context` is only used in exception messages — the widget class name is passed for you.

```php
ColumnSpan::normalize(2);
// ['default' => 2, 'md' => 2, 'lg' => 2, 'xl' => 2]

ColumnSpan::normalize(['default' => 1, 'lg' => 2]);
// ['default' => 1, 'md' => 1, 'lg' => 2, 'xl' => 2]

ColumnSpan::normalize('full')['default'];
// 'full'
```

### Clamping

Numbers outside the grid are clamped to `1..4`:

```php
ColumnSpan::normalize(99);   // ['default' => 4, 'md' => 4, 'lg' => 4, 'xl' => 4]
ColumnSpan::normalize(0);    // ['default' => 1, ...]
```

A span of 99 is somebody asking for more columns than the grid has, and four is the honest answer.

### What throws

Anything that is neither a number nor `'full'` throws `PandaPanel\Exceptions\PanelSchemaException`:

```php
ColumnSpan::normalize('ful');
```

```text
A widget declares a column span of [ful], which is neither a number nor "full". It
would otherwise be read as 1 — a quarter of the width that was asked for, with
nothing to say why.
```

A key that is not one of the four breakpoints throws too:

```php
ColumnSpan::normalize(['default' => 1, 'sm' => 2]);
```

```text
A widget declares a column span at [sm], which is not a breakpoint this grid has. It
has: default, md, lg, xl. A key that is not one of those is a line of configuration
that does nothing.
```

The distinction is deliberate. A number out of range is a request the grid can answer approximately; a typo is a mistake, and clamping it would hide one.

## Defaults per widget type

| Base class | `$columnSpan` |
| --- | --- |
| `PandaPanel\Widgets\Widget` | `1` |
| `PandaPanel\Widgets\StatsWidget` | `1` (inherited) |
| `PandaPanel\Widgets\CustomWidget` | `1` (inherited) |
| `PandaPanel\Widgets\ChartWidget` | `['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2]` |
| `PandaPanel\Widgets\TableWidget` | `['default' => 1, 'md' => 2, 'lg' => 2, 'xl' => 2]` |

Charts and tables override it because a chart or a table squeezed into a quarter of the page is unreadable. Stats widgets do not, so a row of figures usually declares its own — see the example at the top of this page.

## Ordering

```php
protected static int $sort = 0;

public static function sort(): int
```

Widgets are sorted ascending by `[sort(), id()]`. The id — the kebab-cased class basename — is the tiebreaker, so two widgets with the same `$sort` still have a stable, repeatable order rather than whatever discovery happened to return.

```php
protected static int $sort = 10;   // early
protected static int $sort = 40;   // late
```

Leave gaps. Inserting a widget between two that are 10 and 20 is easier than renumbering.

## What surrounds a widget

`WidgetShell.vue` wraps every widget, in every type, and draws:

- the `$heading`, when there is one;
- the `$description`, under it;
- the filter form or the **Filters** button, on the right of the same row;
- the `panel-widget` CSS hook class on the wrapper;
- and nothing else — the body is whatever the type's renderer draws.

The header row is omitted entirely when the widget has no heading, no description and no filters, so a bare widget pays no vertical space for the possibility.

```php
protected static ?string $heading = 'Recent sign-ups';

protected static ?string $description = 'The newest accounts, searchable and sortable.';
```

Both are static, so they are the same for every request.

### Inner layout

The grid controls the cell; each renderer decides what happens inside it.

| Type | Inside the cell |
| --- | --- |
| stats | an auto-fitting grid, `minmax(240px, 1fr)` per figure |
| chart | a card, plot height fixed by `$maxHeight` (default `220`) |
| table | a search row, a card of rows, and pagination when `lastPage > 1` |
| custom | whatever your component draws |

So a stats widget spanning four columns lays its figures out side by side; the same widget spanning one wraps them. `$columnSpan` decides how wide the row is, not how many figures fit in it.

## Styling hook

The wrapper carries `panel-widget`, one of the panel's CSS hooks:

```php
$panel->cssHooks([
    'widget' => 'rounded-2xl',
]);
```

Only the hook names the shell actually emits are accepted; `widget` is one of them. See [CSS hooks](../frontend/css-hooks.md).

## Resource page widgets

`headerWidgets()` and `footerWidgets()` each render their own `WidgetGrid`, so the two groups do not share a row: a header widget spanning two columns and a footer widget spanning two do not sit beside each other. A page with no widgets in a group renders no markup for it at all.

```php
use PandaPanel\Resources\Pages\ListRecords;

final class ListOrders extends ListRecords
{
    public function headerWidgets(): array
    {
        return [OrderStats::class];       // grid above the table
    }

    public function footerWidgets(): array
    {
        return [RevenueChart::class];     // separate grid below it
    }
}
```

## Notes

- Every span class is written out in full in `WidgetGrid.vue`. An interpolated `md:col-span-${n}` would be invisible to the Tailwind compiler, so the class would not exist in the bundle and every widget would silently be one column wide. This is why the value set is closed and clamped rather than free.
- A span larger than the column count at that breakpoint — `md: 4` in a two-column grid — is not an error and not clamped further; the browser resolves it to the full row.
- The gap is `gap-4` and is not configurable per widget.
- `columnSpan()` is called during serialization, so an invalid `$columnSpan` throws when the page renders, not when the class is loaded.
- Charts have a second size control, `$maxHeight`, that has nothing to do with the grid. See [Charts](charts.md).

## See also

- [Widgets overview](overview.md)
- [Stats widgets](stats.md)
- [Chart widgets](charts.md)
- [Table widgets](tables.md)
- [Custom Vue widgets](custom-vue.md)
- [Dashboards](../panels/dashboards.md)
- [CSS hooks](../frontend/css-hooks.md)
- [Tailwind theme](../frontend/tailwind-theme.md)
