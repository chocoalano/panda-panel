# Product Resource

A complete CRUD resource built from nothing: the migration, the model, the generator command, the form, the table, the infolist, the policy, and the test that proves it. Read this page when you are adding the first real resource to an application and want the whole path in one place rather than a method reference per screen. Everything here is a `Product` in the Admin panel from [Admin Panel Example](admin-panel.md), and the following three recipes — [Relation Manager](relation-manager.md), [Custom Field](custom-field.md), and [Import and Export](import-export.md) — build on the files this page creates.

## A minimal working example

Two commands and a policy:

```bash
php artisan make:model Product -m
php artisan make:panel-resource Product --panel=Admin --soft-deletes
```

```php
// app/Policies/ProductPolicy.php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->is_admin;
    }
}
```

Nothing is registered anywhere. The Admin panel calls `discoverResources(app_path('Panels/Admin/Resources'))`, so the new class is found the next time the panel builds its registry. `/admin/products` answers immediately.

The rest of this page fills each generated file in.

## The generator

```bash
php artisan make:panel-resource
    {name}                # the resource name, singular: Product
    --panel=              # required
    --model=              # defaults to App\Models\{name}
    --simple              # only a list page, for modal-based editing
    --no-view             # omit the view page
    --soft-deletes        # trashed filter, restore and force-delete actions
    --force               # overwrite existing files
```

Every flag changes the output. `--soft-deletes` is worth being precise about: it writes `protected static bool $softDeletes = true;` on the resource, adds `TrashedFilter::make('trashed')` to the table, and adds `RestoreAction` / `ForceDeleteAction` and their bulk variants. All three together, because a restore action without the filter that puts a trashed record on screen is a button that can never appear.

`--panel` takes the panel's directory name (`Admin`), not its id. The name is singularised and studly-cased, so `make:panel-resource products` and `make:panel-resource Product` produce the same files.

What lands on disk:

```text
app/Panels/Admin/Resources/Products/
├── ProductResource.php
├── Forms/ProductForm.php
├── Tables/ProductsTable.php
└── Pages/
    ├── ListProducts.php
    ├── CreateProduct.php
    ├── ViewProduct.php
    └── EditProduct.php
```

The directory is plural, the resource class singular. That convention is what `make:panel-relation-manager --resource=Product` relies on to find the right directory later.

## The migration and the model

```php
// database/migrations/xxxx_xx_xx_xxxxxx_create_products_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_published')->default(false);
            $table->string('image')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Product extends Model
{
    use SoftDeletes;

    /**
     * `slug` is absent on purpose. It is derived on create and never taken
     * from request input, so a form post cannot set one — the same reasoning
     * that keeps `is_admin` out of the example user model's `$fillable`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'description',
        'price_cents',
        'stock',
        'is_published',
        'image',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'stock' => 'integer',
            'is_published' => 'boolean',
        ];
    }
}
```

`Category` is an ordinary model with a `name` and a `hasMany` back to products. Nothing about it is panel-specific.

## The resource class

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products;

use App\Models\Product;
use App\Panels\Admin\Resources\Products\Forms\ProductForm;
use App\Panels\Admin\Resources\Products\Infolists\ProductInfolist;
use App\Panels\Admin\Resources\Products\Pages\CreateProduct;
use App\Panels\Admin\Resources\Products\Pages\EditProduct;
use App\Panels\Admin\Resources\Products\Pages\ListProducts;
use App\Panels\Admin\Resources\Products\Pages\ViewProduct;
use App\Panels\Admin\Resources\Products\Tables\ProductsTable;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $slug = 'products';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $navigationIcon = 'package';

    protected static string|BackedEnum|null $navigationGroup = 'Catalog';

    protected static int $navigationSort = 10;

    /**
     * Reaches trashed records, and is what the trashed filter and the restore
     * actions are conditioned on.
     */
    protected static bool $softDeletes = true;

    /**
     * The category column reads a relation on every row. Without this it
     * would lazy load once per row, which `Model::shouldBeStrict()` turns
     * into a loud failure outside production and a slow page inside it.
     *
     * @var list<string>
     */
    protected static array $with = ['category'];

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'sku'];

    /**
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        return [
            'SKU' => (string) $record->getAttribute('sku'),
            'Category' => (string) ($record->getAttribute('category')?->name ?? 'Uncategorised'),
        ];
    }

    public static function table(TableSchema $table): TableSchema
    {
        return ProductsTable::configure($table);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return ProductInfolist::configure($schema);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListProducts::class,
            'create' => CreateProduct::class,
            'view' => ViewProduct::class,
            'edit' => EditProduct::class,
        ];
    }
}
```

### Every declaration a resource has

All of these are `protected static` properties with a reader of the same name, so a rule that depends on something a constant cannot express is an override of the method rather than a change to the property.

| Property | Type | Default | What it decides |
| --- | --- | --- | --- |
| `$model` | `string` | required | The Eloquent model. Missing it throws `PanelSchemaException::missingModel()` naming your class |
| `$slug` | `?string` | plural kebab of the **model's** class basename | The URL segment, and the key action payloads use |
| `$label` | `?string` | `Str::headline()` of the model's class basename | "Product" in headings and confirmations |
| `$pluralLabel` | `?string` | plural of the label | "Products" |
| `$recordTitleAttribute` | `?string` | `null`, and `recordTitle()` then reads `name` | The attribute a record is named by; falls back to the key when it is not scalar |
| `$navigationLabel` | `?string` | the plural label | The sidebar entry |
| `$navigationIcon` | `?string` | `null` | An icon registry key |
| `$activeNavigationIcon` | `?string` | `null` | A different icon while the section is open |
| `$navigationGroup` | `string\|BackedEnum\|null` | `null` | Which group the entry sits in |
| `$navigationSort` | `int` | `0` | Order within the group |
| `$shouldRegisterNavigation` | `bool` | `true` | `false` keeps the routes and hides the sidebar entry |
| `$cluster` | `?string` | `null` | The cluster this resource belongs to |
| `$subNavigationPosition` | `?SubNavigationPosition` | `null` | Where a record's sub-navigation is drawn |
| `$globalSearchAttributes` | `list<string>` | `[]` | Empty means not globally searchable |
| `$globalSearchLimit` | `int` | `5` | Results from this resource |
| `$globalSearchSort` | `int` | `0` | Order of this resource's group of results |
| `$with` | `list<string>` | `[]` | Relations eager loaded by `query()` |
| `$softDeletes` | `bool` | `false` | Reaches trashed records |
| `$singular` | `bool` | `false` | One record, no index — settings-style resources |
| `$parentResource` | `?string` | `null` | Makes this a nested resource |
| `$parentRelationship` | `?string` | camel of the slug | The relation on the parent |
| `$tenantRelationship` | `?string` | `null` | The relation leading to the tenant — see [Tenant Panel](tenant-panel.md) |

Two methods are abstract and must be written: `table()` and `form()`. `pages()` is abstract too. `infolist()` returns the schema unchanged by default, which is what makes the view page fall back to a read-only rendering of the form.

```php
abstract public static function table(TableSchema $table): TableSchema;
abstract public static function form(FormSchema $schema): FormSchema;
abstract public static function pages(): array;

public static function infolist(InfolistSchema $schema): InfolistSchema;
public static function relationManagers(): array;   // list<class-string<RelationManager>>
```

## The form

Kept in its own class, which is what the generator writes and what the examples do. A resource that puts the whole form inline stops being readable at about the third section.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Forms;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

final class ProductForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema
            ->columns(2)
            ->schema([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Mechanical keyboard'),

                        // Unique on create, unique-except-self on edit.
                        // Without the ignore, saving a product without
                        // touching its SKU would fail against itself.
                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->maxLength(64)
                            ->rulesUsing(static fn (?Model $record): array => [
                                $record === null
                                    ? Rule::unique('products', 'sku')
                                    : Rule::unique('products', 'sku')->ignore($record->getKey()),
                            ]),

                        Select::make('category_id')
                            ->label('Category')
                            // The options come from the relation, resolved on
                            // the server and bounded. The browser receives
                            // value/label pairs and never the query.
                            ->relationship('category', 'name')
                            ->searchable()
                            ->optionLimit(100),

                        Textarea::make('description')
                            ->rows(5)
                            ->maxLength(2000)
                            ->columnSpan(2),
                    ]),

                Section::make('Pricing and stock')
                    ->description('Prices are stored in cents, so nothing is ever rounded twice.')
                    ->columns(2)
                    ->schema([
                        NumberInput::make('price_cents')
                            ->label('Price (cents)')
                            ->integer()
                            ->min(0)
                            ->step(1)
                            ->required(),

                        NumberInput::make('stock')
                            ->integer()
                            ->min(0)
                            ->default(0)
                            ->required(),

                        Toggle::make('is_published')
                            ->label('Published')
                            ->helperText('Unpublished products are hidden from the storefront.')
                            ->columnSpan(2),
                    ]),

                Section::make('Media')
                    ->schema([
                        FileUpload::make('image')
                            ->image()
                            ->disk('public')
                            ->directory('products')
                            ->maxSize(2048)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
```

Three things in there are worth naming.

`rulesUsing(Closure $callback)` receives the record being edited, or `null` on create, so one field states both rules rather than the form branching on `$schema->getPage()`.

`Select::relationship(string $relation, string $titleAttribute)` reads the options through the relation and dehydrates to the foreign key. `searchable()` moves the lookup to the panel's options endpoint, which is bounded by `optionLimit()` — 50 by default.

`FileUpload` never carries file contents. The browser posts to the panel's upload endpoint, which stores the file and answers with a path; the form then submits that path like any other string. The disk and the directory declared here are enforced by that endpoint *and* again when the form is submitted, so a submitted path outside the directory is refused rather than attached to the record. See [File Uploads](../forms/file-uploads.md).

## The table

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Tables;

use App\Models\Category;
use App\Panels\Admin\Resources\Products\ProductResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\RestoreAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\ImageColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\ToggleColumn;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Summaries\Average;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Sum;
use PandaPanel\Tables\TableSchema;

final class ProductsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->size(40)
                    ->toggleable(false),

                TextColumn::make('name')
                    ->searchable(individually: true)
                    ->sortable()
                    ->toggleable(false),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),

                // A relation read as a column. The value is reached with dot
                // notation and the search runs as a `whereHas`, so nothing
                // has to be denormalised onto `products`.
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->placeholder('Uncategorised'),

                NumberColumn::make('price_cents')
                    ->label('Price')
                    ->prefix('$')
                    ->decimals(2)
                    ->formatUsing(static fn (mixed $value): float => (int) $value / 100)
                    ->alignment(Alignment::End)
                    ->sortable()
                    ->summarize([Average::make()->label('Average')]),

                NumberColumn::make('stock')
                    ->alignment(Alignment::End)
                    ->sortable()
                    ->summarize([Sum::make()->label('Units'), Count::make()->label('Products')]),

                BadgeColumn::make('stock_state')
                    ->label('Availability')
                    ->formatUsing(static fn (mixed $value, Model $record): string => match (true) {
                        (int) $record->getAttribute('stock') === 0 => 'out',
                        (int) $record->getAttribute('stock') < 10 => 'low',
                        default => 'ok',
                    })
                    ->labels(['ok' => 'In stock', 'low' => 'Low', 'out' => 'Out of stock'])
                    ->colors([
                        'ok' => BadgeColor::Success,
                        'low' => BadgeColor::Warning,
                        'out' => BadgeColor::Danger,
                    ]),

                // Editable in place. The endpoint re-checks the `update`
                // ability for this record before writing.
                ToggleColumn::make('is_published')
                    ->label('Published')
                    ->alignment(Alignment::Center),

                DateTimeColumn::make('created_at')
                    ->label('Added')
                    ->sortable()
                    ->visible(false),
            ])
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Visibility')
                    ->labels('Published', 'Draft', 'Any'),

                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(self::categoryOptions())
                    ->placeholder('Any category'),

                TrashedFilter::make('trashed'),
            ])
            ->groups([
                Group::make('category_id')
                    ->label('Category')
                    ->titleUsing(static fn (Model $record): string => (string) ($record
                        ->getAttribute('category')?->name ?? 'Uncategorised')),
            ])
            ->headerActions([
                CreateAction::make(ProductResource::class)->label('New product'),
            ])
            ->recordActions([
                ViewAction::make(ProductResource::class),
                EditAction::make(ProductResource::class),
                RestoreAction::make(ProductResource::class),
                ForceDeleteAction::make(ProductResource::class),
                DeleteAction::make(ProductResource::class),
            ])
            ->bulkActions([
                DeleteBulkAction::make(ProductResource::class),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->searchPlaceholder('Search by name, SKU, or category...')
            ->persistFiltersInSession()
            ->persistColumnsInSession()
            ->emptyState(
                heading: 'No products yet',
                description: 'Add the first one, or adjust the filters.',
                icon: 'package',
            );
    }

    /**
     * @return array<string, string>
     */
    private static function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(strval(...))
            ->all();
    }
}
```

`SelectFilter::options(array $options)` takes a plain array, keyed by the value the filter writes into the query. Building it from a query here is fine: `configure()` runs when the schema is built, which is once per request that renders the table, not while the panel is being configured. The declared keys are the whitelist — `sanitize()` refuses anything not among them, so a hand-written `filters[category_id]=99999` narrows nothing rather than reaching the builder.

`RestoreAction` and `ForceDeleteAction` are hidden for a record that is not trashed, so listing them unconditionally is correct — they appear exactly when the trashed filter has put a deleted record on screen.

## The infolist

The view page renders `Resource::infolist()`. Leave it unimplemented and the page shows a read-only rendering of the form, which is a reasonable default and the wrong one as soon as the form has a password or an upload in it.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Infolists;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\ImageEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;

final class ProductInfolist
{
    public static function configure(InfolistSchema $schema): InfolistSchema
    {
        return $schema
            ->columns(2)
            ->schema([
                Section::make('Product')
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('image')->label('')->columnSpan(2),
                        TextEntry::make('name'),
                        TextEntry::make('sku')->label('SKU'),
                        TextEntry::make('category.name')
                            ->label('Category')
                            ->placeholder('Uncategorised'),
                        TextEntry::make('price_cents')
                            ->label('Price')
                            ->formatUsing(static fn (mixed $value): string
                                => '$'.number_format((int) $value / 100, 2)),
                        BooleanEntry::make('is_published')
                            ->label('Visibility')
                            ->labels('Published', 'Draft'),
                        TextEntry::make('description')
                            ->columnSpan(2)
                            ->placeholder('No description.'),
                    ]),

                Section::make('History')
                    ->columns(2)
                    ->schema([
                        DateTimeEntry::make('created_at')->label('Added'),
                        DateTimeEntry::make('updated_at')->label('Last changed')->since(),
                    ]),
            ]);
    }
}
```

## The pages

The generator writes four one-line classes. They exist so a page can be given behaviour later without the resource changing shape.

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Pages;

use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Resources\Pages\ListRecords;

final class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
}
```

`CreateProduct`, `ViewProduct`, and `EditProduct` are the same, extending `CreateRecord`, `ViewRecord`, and `EditRecord`.

### Deriving the slug on create

The slug is not a form field, so it has to be written somewhere. A lifecycle hook on the create page is where:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Pages;

use App\Panels\Admin\Resources\Products\ProductResource;
use Illuminate\Support\Str;
use PandaPanel\Resources\Pages\CreateRecord;

final class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = Str::slug((string) ($data['name'] ?? '')).'-'.Str::lower(Str::random(6));

        return $data;
    }
}
```

The hooks a resource page may override, in the order they run:

```text
Rendering:  beforeFill → mutateFormDataBeforeFill → afterFill
Create:     beforeValidate → validate → afterValidate → beforeCreate
            → mutateFormDataBeforeCreate → mutateFormDataBeforeSave → beforeSave
            → handleRecordCreation → afterCreate → afterSave
Update:     beforeValidate → validate → afterValidate
            → mutateFormDataBeforeSave → beforeSave → handleRecordUpdate → afterSave
```

A `mutate*` hook takes data and returns it. Every other hook returns nothing and exists for side effects and for `halt()`, which stops the lifecycle before anything is written. Deletion has no hooks here — it runs through the action endpoint without a page instance — so use `Action::before()` and `Action::after()` instead. Full reference: [Lifecycle Hooks](../resources/lifecycle-hooks.md).

## The policy

Nothing in the resource knows about authorization. Every `can*()` on `Resource` resolves to an ability on an ordinary Laravel policy through the Gate:

| Resource method | Ability | Subject |
| --- | --- | --- |
| `canViewAny()` | `viewAny` | the model class |
| `canView($record)` | `view` | the record |
| `canCreate()` | `create` | the model class |
| `canEdit($record)` | `update` | the record |
| `canDelete($record)` | `delete` | the record |
| `canDeleteAny()` | `deleteAny` | the model class |
| `canRestore($record)` | `restore` | the record |
| `canRestoreAny()` | `restoreAny` | the model class |
| `canForceDelete($record)` | `forceDelete` | the record |
| `canForceDeleteAny()` | `forceDeleteAny` | the model class |

A soft-deleting resource needs the last four as well as the first six:

```php
public function restore(User $user, Product $product): bool
{
    return $user->is_admin;
}

public function restoreAny(User $user): bool
{
    return $user->is_admin;
}

public function forceDelete(User $user, Product $product): bool
{
    return $user->is_admin;
}

public function forceDeleteAny(User $user): bool
{
    return $user->is_admin;
}
```

The `*Any` abilities are what a bulk action asks before it has a record to ask about. Each selected record is still authorized individually before any of them is written.

Turn on `strictAuthorization()` while building this, or a missing `restoreAny` reads exactly like a policy that said no:

```php
$panel->strictAuthorization();   // off by default
```

With it on, a model with no registered policy — or a policy missing the ability being asked — throws `PandaPanel\Exceptions\PanelAuthorizationException` naming both. See [Security](security.md).

## Narrowing what the resource can see

`Resource::query()` is the single funnel. The list, the record lookup, every action endpoint, global search, and exports all go through it, so a record it excludes is a **404** everywhere rather than a filtered row on one screen.

```php
use Illuminate\Database\Eloquent\Builder;

public static function query(): Builder
{
    // parent::query() applies $with, the tenant scope, and the panel's own
    // per-panel narrowing. An override that skips it drops all three.
    return parent::query()->where('is_archived', false);
}
```

## What it produces

| URL | Route name | Class |
| --- | --- | --- |
| `/admin/products` | `panel.admin.resources.products.index` | `ListProducts` |
| `/admin/products/create` | `.create`, `.store` | `CreateProduct` |
| `/admin/products/{record}` | `.view` | `ViewProduct` |
| `/admin/products/{record}/edit` | `.edit`, `.update` | `EditProduct` |

```php
use App\Panels\Admin\Resources\Products\ProductResource;

ProductResource::url();                          // /admin/products
ProductResource::url('edit', $product);          // /admin/products/12/edit
ProductResource::routeName('index');             // panel.admin.resources.products.index
```

`url()` asserts the resource is registered in the panel it is asked about, so a URL for a panel that does not register it throws rather than producing a 404 later.

## The test

```php
<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->admin = User::factory()->admin()->create();
    $this->category = Category::query()->create(['name' => 'Peripherals']);

    $this->actingAs($this->admin);
});

it('lists products for an administrator', function (): void {
    $product = Product::factory()->create(['name' => 'Mechanical keyboard']);

    $this->get('/admin/products')->assertOk();

    panelTable(ProductResource::class)
        ->assertCanSeeRecord($product)
        ->assertCount(1);
});

it('keeps a non-administrator out', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/products')
        ->assertForbidden();
});

it('requires the fields the schema declared required', function (): void {
    panelForm(ProductResource::class)
        ->assertHasField('sku')
        ->assertFieldIsRequired('name')
        ->assertFieldIsRequired('sku');
});

it('creates a product and derives its slug', function (): void {
    $this->post('/admin/products/create', [
        'name' => 'Mechanical keyboard',
        'sku' => 'KB-001',
        'category_id' => $this->category->getKey(),
        'price_cents' => 12900,
        'stock' => 4,
        'is_published' => true,
    ])->assertRedirect();

    $product = Product::query()->firstWhere('sku', 'KB-001');

    expect($product)->not->toBeNull()
        ->and($product?->slug)->toStartWith('mechanical-keyboard-');
});

it('writes only what the form declared', function (): void {
    $this->post('/admin/products/create', [
        'name' => 'Mouse',
        'sku' => 'MS-001',
        'price_cents' => 4900,
        'stock' => 1,
        // Not a field on the form. A key the schema never declared is
        // discarded rather than written.
        'slug' => 'chosen-by-the-request',
    ])->assertRedirect();

    expect(Product::query()->firstWhere('sku', 'MS-001')?->slug)
        ->not->toBe('chosen-by-the-request');
});

it('restores a trashed product through the action endpoint', function (): void {
    $product = Product::factory()->create();
    $product->delete();

    $this->post('/admin/actions/record', [
        'resource' => 'products',
        'action' => 'restore',
        'record' => $product->getKey(),
    ])->assertRedirect();

    expect($product->fresh()?->trashed())->toBeFalse();
});
```

`panelTable()` and `panelForm()` are free functions shipped with the package and autoloaded; no import and no base class. They go through the real `TableSchema` and `FormSchema`, so they are a nicer way to ask rather than a second implementation of the answer. See [Testing Helpers](../testing/helpers.md).

```bash
php artisan test --compact --filter=Product
```

## Gotchas

- **A cached manifest means discovery does not run.** After adding the resource in production, run `php artisan panel:cache` again. `optimize` and `optimize:clear` include it.
- **`--soft-deletes` is a declaration, not a detection.** The flag writes `$softDeletes = true` on the resource. A model that uses the `SoftDeletes` trait without the resource declaring it keeps the default scope, so trashed records are simply invisible — which is sometimes what you want.
- **An icon that is not in the registry renders nothing, silently.** `php artisan panel:icons` rewrites the registry from source and fails by name on a Lucide icon that does not exist.
- **`$with` is not optional once a column reads a relation.** `category.name` without `protected static array $with = ['category']` is one query per row.
- **A `query()` override that forgets `parent::query()`** drops the eager loads, the tenant scope, and any per-panel `modifyQueryUsing()` at once, and nothing about the screen says so.
- **The slug property and the model's `slug` column are unrelated.** `Resource::$slug` is the URL segment; the product's own `slug` is an attribute. They collide only in conversation.
- **A record excluded by `query()` is a 404, not a 403.** That is deliberate: as far as this resource is concerned the record does not exist, and a 403 would confirm that it does.

## See also

- [Admin Panel Example](admin-panel.md) — the panel this resource is discovered by
- [User Resource](user-resource.md) — the shipped example, taken further
- [Relation Manager](relation-manager.md) — variants and tags on this product
- [Custom Field](custom-field.md) — a rating field on this form
- [Import and Export](import-export.md) — products as a spreadsheet
- [Creating Resources](../resources/creating-resources.md)
- [Resource API Reference](../resources/api.md)
- [Lifecycle Hooks](../resources/lifecycle-hooks.md)
- [Soft Deletes](../resources/soft-deletes.md)
- [Global Search](../resources/global-search.md)
- [Tables Overview](../tables/overview.md)
- [Forms Overview](../forms/overview.md)
- [Infolists Overview](../infolists/overview.md)
- [make:panel-resource](../cli/make-panel-resource.md)
