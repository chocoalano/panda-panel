# 6 · Shape the form and the table

**Goal:** a form somebody can fill in without guessing, and a table they can actually find a product
in.

Both are *schemas*: PHP descriptions of what renders. Validation, sorting, searching and filtering
run on the server; Vue renders what it is handed. That is why a field you never declared cannot be
submitted and a sort column you never declared is ignored rather than passed to the query builder.

## Do this

### The form

Replace `app/Panels/Admin/Resources/Products/Forms/ProductForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Forms;

use PandaPanel\Forms\Components\DateTimePicker;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

final class ProductForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema
            ->columns(2)
            ->schema([
                Section::make('Product')
                    ->description('What the customer sees.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Bamboo desk lamp'),

                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->maxLength(64)
                            ->helperText('Unique. Used on invoices.')
                            ->rules(['alpha_dash']),

                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Availability')
                    ->columns(2)
                    ->schema([
                        NumberInput::make('price')
                            ->label('Price (USD)')
                            ->required()
                            ->min(0)
                            ->step(0.01)
                            ->placeholder('0.00'),

                        Select::make('status')
                            ->options([
                                'draft' => 'Draft',
                                'published' => 'Published',
                                'archived' => 'Archived',
                            ])
                            ->default('draft')
                            ->required(),

                        DateTimePicker::make('published_at')
                            ->label('Publish at')
                            ->helperText('Leave empty to publish immediately.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
```

### The table

Replace `app/Panels/Admin/Resources/Products/Tables/ProductsTable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Tables;

use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\TableSchema;

final class ProductsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(false),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(individually: true),

                BadgeColumn::make('status')
                    ->labels([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ])
                    ->colors([
                        'draft' => BadgeColor::Warning,
                        'published' => BadgeColor::Success,
                        'archived' => BadgeColor::Info,
                    ])
                    ->sortable(),

                NumberColumn::make('price')->prefix('$')->decimals(2)->sortable(),

                DateTimeColumn::make('published_at')->label('Published')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                    'archived' => 'Archived',
                ]),
                TernaryFilter::make('published_at')
                    ->nullable()
                    ->labels('Published', 'Not published', 'Anyone'),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->searchPlaceholder('Search by name or SKU...')
            ->perPageOptions([10, 25, 50, 100])
            ->defaultPerPage(25)
            ->recordActions([
                ViewAction::make(ProductResource::class),
                EditAction::make(ProductResource::class),
                DeleteAction::make(ProductResource::class),
            ])
            ->bulkActions([
                DeleteBulkAction::make(ProductResource::class),
            ])
            ->emptyState(
                heading: 'No products yet',
                description: 'Add the first one, or clear the filters.',
                icon: 'package',
            );
    }
}
```

Reload `/admin/products`. No build step is needed — both files are PHP, and the schema is rebuilt on
every request.

## What you just used

### Layout is two numbers

A container is divided by `columns()`; a field says how much of that division it takes.

```php
Section::make('Product')->columns(2)->schema([
    TextInput::make('name'),                  // one column
    TextInput::make('sku'),                   // one column
    Textarea::make('description')->columnSpanFull(),   // the whole row
]);
```

`columnSpanFull()` rather than `columnSpan(2)`: the number that means "all of them" belongs to the
container, so a field that spelled it out would silently become half the row the day somebody made
that section four columns.

Counts are responsive, and a declared count is the count on a **wide** screen — `columns(2)` is one
column on a phone. Anything above four is clamped to four, because the renderer has literal Tailwind
classes for one through four.

### Validation is Laravel's

```php
TextInput::make('sku')->required()->maxLength(64)->rules(['alpha_dash']);
```

`required()` on a field is a UX marker *and* a rule; removing it in the browser changes nothing,
because the rules are rebuilt server-side from the same schema. Only declared fields are validated,
and only fields that dehydrate are persisted — so an extra key in the request body is discarded
rather than mass-assigned.

The `Select` option list is itself a whitelist. `status` becomes
`required|in:"draft","published","archived"`, so a value the schema never offered is invalid rather
than merely unexpected.

### The table schema is the whitelist too

Sorting, searching, filtering and column visibility read only what a column or filter declared.
Everything else in the URL is ignored rather than handed to the query builder.

| What you wrote | What it allows |
| --- | --- |
| `->searchable()` | That column joins the global search box |
| `->searchable(individually: true)` | And gets a per-column search input of its own |
| `->sortable()` | `?sort=sku` is accepted; without it, ignored |
| `->toggleable(false)` | The column can never be hidden, however the request asks |
| `filters([...])` | Only these filter names are read from the query string |

### Where the actions came from

`ViewAction`, `EditAction`, `DeleteAction` and `DeleteBulkAction` are built-ins that already know
how to find the resource's pages and ask its policy. Passing a non-empty array to `bulkActions()`
turns row selection on by itself, because a bulk action with no way to select would be useless.

## Try it

Create three or four products with different statuses, then:

1. Type a name fragment in the search box — the URL gains `?search=`, and the server re-queries.
2. Sort by price. Try editing the URL to `?sort=description` — nothing happens, because that column
   never declared `sortable()`.
3. Filter to **Published**, then reload the page. The filter is in the URL, so the view is
   shareable.
4. Select two rows and delete them in one go.

## Check it worked

The table shows a status badge in colour, a price with two decimals and a `$` prefix, a working
search box, and two filters. The form has two sections, a two-column grid, and refuses to save a
product with no name or a duplicate SKU.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| `PanelSchemaException: duplicate column` | The same name declared twice | A column name is the key its cell, visibility, search and sort all live under |
| `PanelSchemaException: unknown default sort` | `defaultSort()` names a column the table does not have | The check runs when the page renders, not when the line is written |
| A field renders but never saves | Two fields share one name | Only one value survives the write; the check throws for exactly this reason |
| `defaultPerPage(20)` silently uses 10 | 20 is not in `perPageOptions()` | Add it to the list, or pick one that is there |
| The badge renders grey | The stored value is not a key in `colors()` | Match the keys to what the column actually holds |

::: tip Filters never narrow a record lookup
They live in the table query, not in `Resource::query()`. A record filtered off the list is still
openable by URL — which is correct, and which is why authorization lives in the policy rather than
in a filter.
:::

## Next

The panel reads well. Now make it *do* something.

**→ [7 · Actions and dashboard widgets](actions-and-widgets)**

## See also

- [FormSchema basics](/forms/overview) and [every field type](/forms/fields/text)
- [TableSchema basics](/tables/overview), [Columns](/tables/columns), [Filters](/tables/filters)
- [Form layouts](/forms/layouts) — `Section`, `Grid`, `Tabs`, `Wizard`
- [Validation](/forms/validation)
