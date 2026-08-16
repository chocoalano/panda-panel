# Widget Filters

A filter is a small form that changes what a widget or a whole dashboard reports: a date window, a region, a status. You reach for one when the same widget answers a question that has more than one right answer — "sign-ups over the last six months" and "over the last two years" are the same widget with a different parameter, not two widgets.

There are two levels and they compose. A dashboard can filter every widget on it at once; a widget can carry a filter of its own that only makes sense for it. The values live in the query string, so a filtered dashboard is a link somebody can send.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class SignUps extends StatsWidget
{
    protected static ?string $heading = 'Sign-ups';

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('days')
                ->label('Window')
                ->options(['7' => 'Last 7 days', '30' => 'Last 30 days'])
                ->default('30'),
        ]);
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $days = (int) $this->filter('days', 30);

        return [
            Stat::make('New accounts', User::query()
                ->where('created_at', '>=', now()->subDays($days))
                ->count()),
        ];
    }
}
```

A select appears beside the widget heading. Choosing "Last 7 days" navigates to `?widgets[sign-ups][days]=7` and the figure changes.

## Declaring a widget filter

### `filterSchema()`

```php
public function filterSchema(): ?FormSchema
```

Defined on `PandaPanel\Widgets\Widget`, returning `null` by default — which is right for most widgets, since they take the page's filters and nothing more. Return a `PandaPanel\Forms\FormSchema` to declare controls of your own.

Any form field works. Keep it to controls that resolve without a round trip:

```php
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;

public function filterSchema(): FormSchema
{
    return FormSchema::make()->schema([
        Select::make('region')->options(['eu' => 'Europe', 'us' => 'US'])->default('eu'),
        DatePicker::make('since'),
        Toggle::make('verified_only'),
    ]);
}
```

The schema is the whitelist. A key the schema never declared is discarded, exactly as an unknown field is on a form — the query string is a request, not a source of truth.

### `filtersInModal()`

```php
public static function filtersInModal(): bool
```

`false` by default: the controls sit inline above the widget. Return `true` and the widget shows a **Filters** button that opens a dialog instead.

```php
public static function filtersInModal(): bool
{
    return true;
}
```

Worth it for more than a control or two, where an inline form would be bigger than the widget it filters. The dialog title is the widget's `$heading`, or the word "Filters" when it has none.

## Reading the values

### `filter()`

```php
protected function filter(string $name, mixed $default = null): mixed
```

One value, already narrowed by the schema that declared it. `null` and `''` both fall back to `$default`; `false` and `0` do not.

```php
$days = (int) $this->filter('days', 30);
$region = (string) $this->filter('region', 'eu');
$verifiedOnly = (bool) $this->filter('verified_only', false);
```

Always pass a default. It is the value the widget means when nothing has been said, and it is what protects the widget from the edge cases below.

Values arrive as strings from the query string. Cast them, and bound anything that reaches a query:

```php
$months = max(1, min(24, (int) $this->filter('months', 6)));
```

### `filters()`

```php
protected function filters(): array
```

Everything the widget was given, page filters included, as `name => value`.

### `withFilters()`

```php
public function withFilters(array $filters): static
```

Called by the page, and by you in a test:

```php
$widget = (new UserGrowth)->withFilters(['months' => '12']);

expect($widget->labels())->toHaveCount(12);
```

## Dashboard filters

A `PandaPanel\Pages\Page` — and therefore every `PandaPanel\Pages\Dashboard` — may declare one form that every widget on it reads.

```php
public function filterSchema(): ?FormSchema
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Pages\Dashboard;

final class AccountsDashboard extends Dashboard
{
    protected static ?string $title = 'Accounts';

    protected static ?string $slug = 'accounts';

    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('period')
                ->label('Period')
                ->options([
                    'month' => 'This month',
                    'quarter' => 'This quarter',
                    'year' => 'This year',
                ])
                ->default('month'),
        ]);
    }
}
```

The controls render above the widget grid. Every widget on the page reads `period` through `$this->filter('period', 'month')` without declaring anything.

`"this quarter"` is a question about the page, not about one figure on it. A widget's own filter is for a question only that widget can be asked.

## How the two levels compose

A widget is given the page's filters with its own on top:

```php
[...$dashboardFilters, ...$widgetFilters]
```

So a widget that declares `months` means *its* months, and a widget that does not declare it sees the page's. This is `PandaPanel\Widgets\Support\WidgetFilters::for()`.

```php
use PandaPanel\Widgets\Support\WidgetFilters;

$filters = WidgetFilters::fromRequest($request, $pageSchema, ['user-growth' => $widgetSchema]);

$filters->dashboard();          // ['months' => '6']
$filters->for('user-growth');   // ['months' => '24']  — its own wins
$filters->for('recent-users');  // ['months' => '6']   — the page's
```

## The query string

| Level | Parameter shape | Example |
| --- | --- | --- |
| Page / dashboard | `filters[{field}]` | `?filters[period]=quarter` |
| Widget | `widgets[{widgetId}][{field}]` | `?widgets[user-growth][months]=24` |

The widget id is the kebab-cased class basename — `UserGrowth` is `user-growth`.

The controls apply on change rather than behind an Apply button, with `preserveScroll` and `preserveState`, so the page does not jump and half-typed state elsewhere survives. A value that is `null`, `''` or `false` removes its parameter instead of writing an empty one, and `page` is dropped on every change, because a filtered list showing page four of the unfiltered one is a page of nothing.

## Defaults, clearing, and the session

`WidgetFilters` resolves each schema's values by three rules, and they are worth knowing because they are what makes a cleared filter stay cleared.

| The request | What the widget gets |
| --- | --- |
| the parameter group is absent entirely | the stored session values if there are any, otherwise each field's `default()` |
| the group is present and the key is in it | the submitted value |
| the group is present and the key is missing | `null` — the field was cleared |

Absence is the only case that falls back. A filter cleared to nothing is a decision, and restoring what was stored over it would ignore what the user just did.

State is persisted per page, not per panel:

```php
protected function filterSessionKey(): string
{
    return 'panel.'.$this->panel()->getId().'.page.'.static::slug();
}
```

Page filters are stored under `{key}.filters`, and each widget's under `{key}.widgets.{widgetId}`. Two dashboards filtered differently are two different questions, and restoring one over the other would answer neither. Override `filterSessionKey()` on the page to change it.

Persistence is skipped entirely when the request has no session.

## The plumbing

You rarely call these directly, but they are what the page does.

```php
use PandaPanel\Pages\WidgetCollection;
use PandaPanel\Widgets\Support\WidgetFilters;

/** @return array<string, FormSchema> keyed by widget id */
WidgetCollection::filterSchemas([UserStats::class, UserGrowth::class]);

WidgetFilters::none();   // no filters at all

WidgetFilters::fromRequest(
    Request $request,
    ?FormSchema $dashboardSchema = null,
    array $widgetSchemas = [],       // keyed by widget id
    ?string $sessionKey = null,
);
```

`filterSchemas()` skips widgets whose `canView()` is false, so an unauthorized widget's schema never becomes part of the whitelist.

## What the frontend receives

A widget with a filter carries this in its definition:

```php
'filters' => [
    'inModal' => false,
    'form' => [/* the serialized FormSchema, holding the values the server resolved */],
],
```

`null` for a widget that declares none, so the renderer draws nothing rather than an empty bar. A page's own filters arrive as a separate `filters` prop with the same `form` key.

The values in that form are what the server *applied*, not what was asked for — which is why a discarded key never appears on screen as if it had taken effect.

## Gotchas

- **Resource pages do not resolve filters.** `ResourcePage::widgetProps()` passes page context but no `WidgetFilters`, so a widget with a `filterSchema()` in `headerWidgets()` or `footerWidgets()` renders its controls and changes the URL, but `$this->filter()` always returns the default. Filters are a dashboard and standalone-page feature.
- **Table widgets share the group.** A `TableWidget`'s search, sort and page state lives under `widgets[{id}]` too. Paging the table makes the group *present*, which resolves the widget's own filters to `null` under the rule above. Reading them with an explicit default — `$this->filter('months', 6)` — makes this a non-event, which is why every example does.
- `live()` fields have no state endpoint inside a widget filter, so they behave as ordinary fields: no request, no rebuild, no `afterStateUpdated()`. See [Live fields](../forms/live-fields.md).
- A `Toggle` switched off writes no parameter, so an "off" toggle and an untouched one look the same in the URL. Give the field a `default(false)` and read it with `$this->filter('flag', false)`.
- Filter values are strings. `'0'` is truthy in PHP; cast before testing.
- `filterSchema()` is called more than once per request — once to build the whitelist, once to serialize the definition. Keep it free of queries, or memoize the options.
- Validation rules on a filter field are not run. The schema narrows keys; it does not validate values. Bound them in the widget.

## See also

- [Widgets overview](overview.md)
- [Stats widgets](stats.md)
- [Chart widgets](charts.md)
- [Table widgets](tables.md)
- [Dashboards](../panels/dashboards.md)
- [Forms overview](../forms/overview.md)
- [Live fields](../forms/live-fields.md)
- [Table filters](../tables/filters.md)
- [Polling](polling.md)
