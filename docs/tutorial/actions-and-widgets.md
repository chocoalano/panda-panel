# 7 · Actions and dashboard widgets

**Goal:** a one-click **Publish** button on the rows that need it, and a dashboard that tells you
something at a glance.

An action is a backend-owned operation the frontend can request *by name*. The definition that
crosses the wire carries a label, an icon and confirmation copy — it never carries the handler. The
browser sends an action name, a resource slug and record keys; the server looks the action up in the
schema that declared it, authorizes it, and runs it.

## Do this — the publish action

Add it to the table's record actions. In
`app/Panels/Admin/Resources/Products/Tables/ProductsTable.php`:

```php
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
```

```php
->recordActions([
    Action::make('publish')                                                  // [!code ++]
        ->label('Publish')                                                   // [!code ++]
        ->icon('check')                                                      // [!code ++]
        ->variant(ActionVariant::Outline)                                    // [!code ++]
        ->visible(static fn (?Model $record): bool                           // [!code ++]
            => $record?->getAttribute('status') !== 'published')             // [!code ++]
        ->authorize(static fn (?Model $record): bool                         // [!code ++]
            => $record !== null && ProductResource::canEdit($record))        // [!code ++]
        ->requiresConfirmation(                                              // [!code ++]
            heading: 'Publish this product?',                                // [!code ++]
            description: 'It becomes visible in the catalogue immediately.', // [!code ++]
            button: 'Publish',                                               // [!code ++]
        )                                                                    // [!code ++]
        ->successMessage('Product published.')                               // [!code ++]
        ->action(static function (Product $record): void {                   // [!code ++]
            $record->forceFill([                                             // [!code ++]
                'status' => 'published',                                     // [!code ++]
                'published_at' => $record->published_at ?? Date::now(),       // [!code ++]
            ])->save();                                                      // [!code ++]
        }),                                                                  // [!code ++]
    ViewAction::make(ProductResource::class),
    EditAction::make(ProductResource::class),
    DeleteAction::make(ProductResource::class),
])
```

Reload the list. Every draft row now carries a **Publish** button; published rows do not. Pressing it
opens a confirmation, posts `{"resource": "products", "action": "publish", "record": 42}`, and
redirects back with a toast.

### The two guards do different jobs

```php
->visible(...)      // hides the button; implies nothing about permission
->authorize(...)    // the permission, asked again when the action runs
```

`visible()` is presentation: a published product does not need a Publish button. `authorize()` is
access control, and it is re-asked on execution — a row action the policy refuses is absent from the
row *and* refused at the endpoint. Hiding a button is never the control.

Both closures are called with `null` when the action is serialized without a record — for a header
or bulk action — which is why every built-in begins with `$record !== null &&`.

### One action, three shapes

The kind is derived from what you gave it, never set directly:

| Given | Kind | What the frontend does |
| --- | --- | --- |
| `url()` | link | Navigates to the server-produced URL |
| `schema()` | form | Opens a dialog and fetches the form when it opens |
| neither | callback | Posts the action name to the action endpoint |

```php
// Ask for a reason before rejecting — an action with its own form:
Action::make('archive')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('reason')->required(),
    ]))
    ->action(static function (Product $record, array $data): void {
        $record->forceFill(['status' => 'archived'])->save();
        Log::info('Product archived', ['id' => $record->getKey(), 'reason' => $data['reason']]);
    });
```

`$data` is what the dialog submitted, already validated and dehydrated. A handler declared with one
argument never sees it — which is why adding a form to an existing action is additive.

## Add a bulk version

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
```

```php
->bulkActions([
    Action::make('publish-selected')                                         // [!code ++]
        ->label('Publish selected')                                          // [!code ++]
        ->icon('check')                                                      // [!code ++]
        ->requiresConfirmation()                                             // [!code ++]
        ->successMessageUsing(static fn (int $count): string                 // [!code ++]
            => "{$count} product(s) published.")                             // [!code ++]
        ->authorizeEachUsing(static fn (Model $record): bool                 // [!code ++]
            => ProductResource::canEdit($record))                            // [!code ++]
        ->bulkAction(static function (Collection $records): void {           // [!code ++]
            DB::transaction(static function () use ($records): void {        // [!code ++]
                $records->each->forceFill([                                  // [!code ++]
                    'status' => 'published',                                 // [!code ++]
                    'published_at' => Date::now(),                           // [!code ++]
                ])->each->save();                                            // [!code ++]
            });                                                              // [!code ++]
        }),                                                                  // [!code ++]
    DeleteBulkAction::make(ProductResource::class),
])
```

A bulk run authorizes **every** record before touching any of them, so a selection containing one
forbidden record changes nothing. `executeBulk()` is not itself wrapped in a transaction — with your
own `bulkAction()` handler, the wrapping is yours, which is why the `DB::transaction()` is there.

::: tip The bulk endpoint caps at 500 keys
Anything larger belongs in a table action that queues a job.
:::

## Do this — the dashboard widget

```bash
php artisan make:panel-widget ProductStats --panel=Admin --type=stats
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\Product;
use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class ProductStats extends StatsWidget
{
    protected static int $sort = 0;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3];

    protected static ?string $heading = 'Catalogue';

    protected static ?string $description = 'Everything currently in the products table.';

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $counts = Product::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            Stat::make('Products', (int) $counts->sum())
                ->icon('package')
                ->url(ProductResource::url()),

            Stat::make('Published', (int) $counts->get('published', 0))
                ->icon('check')
                ->color(StatColor::Success)
                ->description('Visible in the catalogue'),

            Stat::make('Catalogue value', (float) Product::query()->sum('price'))
                ->icon('circle-dollar-sign')
                ->format(prefix: '$', decimals: 2),
        ];
    }
}
```

Open `/admin`. The widget is already there — the panel's `discoverWidgets(app_path('Panels/Admin/Widgets'))`
covers that directory, so nothing needs registering.

### What the pieces do

| | |
| --- | --- |
| `$sort` | Order on the page, ascending |
| `$columnSpan` | How much of the page grid the row occupies. Responsive, and clamped to the grid |
| `$heading`, `$description` | The title and sub-line above the widget |
| `Stat::make(label, value)` | A string value is printed exactly as written; a number is formatted |
| `->format(prefix:, suffix:, decimals:)` | Formatting happens on the server — a figure is a number *and* how it should be read |
| `->url(...)` | Makes the card a link. The destination authorizes for itself when followed |
| `->color(StatColor::…)` | A closed set, because each case maps to literal Tailwind classes |

::: details Two more things `Stat` can wear
```php
Stat::make('Revenue', 12_045)->trend('up', 12.4);           // ↗ 12.4% Increased
Stat::make('Sign-ups', 412)->chart([4, 9, 7, 12, 18, 21]);  // a sparkline, needs 2+ values
```
The direction decides the colour, not the sign of the value — pass the magnitude and say which way
it went. And compute a sparkline in **one** query; six queries for decoration is not a trade worth
making.
:::

### Widgets are authorized too

```php
public static function canView(): bool
{
    return auth()->user()?->is_admin === true;
}
```

A widget the user may not see is absent from the payload, not hidden with CSS.

## Check it worked

1. A draft product's row shows **Publish**; press it and the row's badge turns green.
2. A published product's row does not show it.
3. Select two drafts and publish them together — one toast, one count.
4. `/admin` shows three figures, and the first card links to the products list.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| `PanelSchemaException: inert action` | The action has no handler, URL, form or modal | Give it an `action()` |
| `PanelSchemaException: duplicate actions` | Two actions share a name in one set | Rename one |
| The button 400s when pressed | The scope needs a different handler — a bulk action needs `bulkAction()` or `action()` | Match the handler to where the action lives |
| The widget does not appear | It is outside the discovered path, or `panel:cache` is stale | `php artisan panel:clear` |
| The icon is missing | The key is not in the build-time registry | `php artisan panel:icons` |
| A `TypeError` on `$record->…` | The closure dereferenced a null record during serialization | Start with `$record !== null &&` |

## Next

It works on your machine. One page left.

**→ [8 · Go to production](production)**

## See also

- [Action basics](/actions/overview) — the full API, and how execution stays safe
- [Bulk actions](/actions/bulk-actions), [Action forms](/actions/forms), [Modals](/actions/modals)
- [Stats widgets](/widgets/stats), [Chart widgets](/widgets/charts), [Widget layout](/widgets/layout)
- [Widget authorization](/widgets/authorization)
