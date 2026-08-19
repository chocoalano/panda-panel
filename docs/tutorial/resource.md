# 5 · Your first resource

**Goal:** `/admin/products` lists products, and you can create, view and edit one.

A resource is one Eloquent model presented inside one panel: its table, its form, its pages, and the
single query all of them read through. Anything that is *not* a model — a report, a settings screen
— is a [standalone page](/pages-navigation/custom-pages) instead.

## Do this

### 1. The model and its table

```bash
php artisan make:model Product -m
```

Fill in the migration:

```php
// database/migrations/xxxx_xx_xx_xxxxxx_create_products_table.php
public function up(): void
{
    Schema::create('products', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('sku')->unique();
        $table->decimal('price', 10, 2)->default(0);
        $table->string('status')->default('draft');   // draft | published | archived
        $table->text('description')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->timestamps();
    });
}
```

And the model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sku', 'price', 'status', 'description', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }
}
```

```bash
php artisan migrate
```

### 2. The resource

```bash
php artisan make:panel-resource Product --panel=Admin
```

```text
INFO  Created [app/Panels/Admin/Resources/Products/ProductResource.php]
INFO  Created [app/Panels/Admin/Resources/Products/Pages/ListProducts.php]
INFO  Created [app/Panels/Admin/Resources/Products/Pages/CreateProduct.php]
INFO  Created [app/Panels/Admin/Resources/Products/Pages/ViewProduct.php]
INFO  Created [app/Panels/Admin/Resources/Products/Pages/EditProduct.php]
INFO  Created [app/Panels/Admin/Resources/Products/Tables/ProductsTable.php]
INFO  Created [app/Panels/Admin/Resources/Products/Forms/ProductForm.php]
```

Nothing needs registering. The panel's `discoverResources(app_path('Panels/Admin/Resources'))` from
step 3 already covers that directory, so `/admin/products` answers on the next request.

Directory plural, class singular. The table and the form live in their own classes because both
grow — a resource whose `form()` is eighty lines of fields is a file where the navigation
configuration is impossible to find.

### 3. The policy

```bash
php artisan make:policy ProductPolicy --model=Product
```

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Product $product): bool
    {
        return true;
    }

    public function delete(User $user, Product $product): bool
    {
        return true;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}
```

::: warning Without this, the resource answers 403
Not 404, and not an empty list — **403**. Every panel screen delegates to Laravel's Gate, the gate
is asked, and with no policy it answers no. That is the intended default: a panel that showed every
record because nobody had written a rule yet would be worse. In development the panel logs which
model is missing one, naming the `make:policy` command.

The `return true` above is a *tutorial* policy. Step 8 replaces it with a real one.
:::

## What the generator wrote

The resource is the small file. It names the model, the navigation, and the page map, and delegates
the two schemas:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products;

use App\Models\Product;
use App\Panels\Admin\Resources\Products\Forms\ProductForm;
use App\Panels\Admin\Resources\Products\Pages\CreateProduct;
use App\Panels\Admin\Resources\Products\Pages\EditProduct;
use App\Panels\Admin\Resources\Products\Pages\ListProducts;
use App\Panels\Admin\Resources\Products\Pages\ViewProduct;
use App\Panels\Admin\Resources\Products\Tables\ProductsTable;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $navigationIcon = 'folder';

    protected static int $navigationSort = 0;

    public static function table(TableSchema $table): TableSchema
    {
        return ProductsTable::configure($table);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return ProductForm::configure($schema);
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

### The four things a resource must declare

| Member | Why it is required |
| --- | --- |
| `$model` | Everything starts from it: the query, the slug, the labels, the policy lookup |
| `table()` | The index has nothing to show without columns |
| `form()` | A resource with no form should say so explicitly, rather than inherit a create page that silently saves nothing |
| `pages()` | The page map is what gets routed; a resource with no pages has no URLs |

### The page keys are not decoration

They become route name suffixes, and the four standard keys have fixed shapes. Every key is
optional, and the framework stops offering links to a page that was never declared — remove
`'create'` and `ListRecords` renders no **New** button; remove `'edit'` and the view page renders
no **Edit** button.

| Key | URL | Route name |
| --- | --- | --- |
| `index` | `/admin/products` | `panel.admin.resources.products.index` |
| `create` | `/admin/products/create` | `…products.create` |
| `view` | `/admin/products/{record}` | `…products.view` |
| `edit` | `/admin/products/{record}/edit` | `…products.edit` |

## Make the navigation read better

Two lines on the resource, both optional, both worth setting now:

```php
final class ProductResource extends Resource
{
    protected static string $model = Product::class;

    protected static ?string $navigationIcon = 'package';       // [!code ++]
    protected static ?string $navigationLabel = 'Products';     // [!code ++]
    protected static string|BackedEnum|null $navigationGroup = 'Catalogue';  // [!code ++]
    protected static int $navigationSort = 10;

    // ...
}
```

Add `use BackedEnum;` at the top for that third line. The group only needs declaring on the panel if
you care about its order:

```php
// app/Panels/Admin/AdminPanelProvider.php
->navigationGroups(['Catalogue', 'System'])
```

::: tip An icon key that is not in the registry renders nothing
Icons are resolved through a build-time registry rather than at runtime. After using a new key,
run `php artisan panel:icons`.
:::

## Check it worked

```bash
php artisan route:list --name=panel.admin.resources
php artisan panel:cache      # should now report 1 panel, 1 resource
php artisan panel:clear
```

Then open `/admin/products`. You get an empty table with a **New product** button. Create one, and
it appears in the list — with whatever placeholder columns the generator wrote, which is what step 6
fixes.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| **403** on `/admin/products` | No policy, or `viewAny()` returns false | Create `ProductPolicy` |
| **404** on `/admin/products` | The class is outside the discovered path, or `panel:cache` is stale | `php artisan panel:clear` |
| No sidebar entry, but the URL works | `viewAny()` says no — navigation hides what the user may not see | Check the policy |
| `PanelSchemaException: model not set` | `$model` was never assigned | Add `protected static string $model = Product::class;` |
| The resource appears in the wrong panel | `--panel=` named a different directory | Move the class, or generate again |

::: details Why `panel:cache` matters here
After `panel:cache`, discovery does not run — a resource added afterwards has no route, no
navigation entry and no error. The manifest records a fingerprint of the discovery paths and warns
in development when it is stale. `php artisan panel:clear` is the fix, and it is the first thing to
try whenever a new class does not show up.
:::

## Next

The resource works. It is not yet pleasant to use.

**→ [6 · Shape the form and the table](form-and-table)**

## See also

- [Creating resources](/resources/creating-resources) — every property and override
- [make:panel-resource](/cli/make-panel-resource) — every flag, and what each one changes
- [Resource authorization](/resources/authorization) — the policy methods each page asks for
- [Directory convention](/resources/directory-convention)
