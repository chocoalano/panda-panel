# Relation Manager

Two relation managers on the product resource from [Product Resource](product-resource.md): variants, which a product owns (`hasMany`), and tags, which it shares with other products (`belongsToMany` with a pivot column). Read this page when a record has children that belong beside it rather than in a resource of their own. The two relation shapes are covered together because they need different actions, different policies, and different questions asked — and picking the wrong shape is the mistake this page exists to prevent.

## A minimal working example

```bash
php artisan make:panel-relation-manager variants --panel=Admin --resource=Product --type=has-many
```

```php
// app/Panels/Admin/Resources/Products/ProductResource.php

use PandaPanel\Resources\RelationManager;

/**
 * @return list<class-string<RelationManager>>
 */
public static function relationManagers(): array
{
    return [VariantsRelationManager::class];
}
```

The table now appears beneath the product's view and edit pages. `Resource::relationManagers()` is the only list there is — a manager the resource does not declare cannot be addressed by any request.

## The generator

```bash
php artisan make:panel-relation-manager
    {name}                # the relation method on the owner model: variants
    --panel=              # required
    --resource=           # required; Product or Products, either works
    --type=has-many       # has-many or belongs-to-many
    --soft-deletes        # trashed filter, restore and force-delete actions
    --page                # also generate a ManageRelatedRecords page
    --force
```

`--type` is an option rather than something derived from the name because the relation's *shape* decides which actions belong on it, and no generator can read that off a class name. A `hasMany` is created and deleted; a `belongsToMany` is attached and detached. Picking the wrong one produces a manager offering an operation the relation cannot perform, which is a visible mistake rather than a silently missing one.

`--resource` is singularised and studly-cased, then pluralised for the directory, so `--resource=Products` and `--resource=Product` both find `app/Panels/Admin/Resources/Products/`.

The command finishes by telling you the one thing it cannot do for you:

```text
Add VariantsRelationManager::class to ProductResource::relationManagers(). Nothing is registered until you do.
```

## The models

```php
// app/Models/Variant.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Variant extends Model
{
    protected $fillable = ['name', 'sku', 'price_cents', 'stock'];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

```php
// app/Models/Product.php — the two relations the managers read

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @return HasMany<Variant, $this>
 */
public function variants(): HasMany
{
    return $this->hasMany(Variant::class);
}

/**
 * @return BelongsToMany<Tag, $this>
 */
public function tags(): BelongsToMany
{
    return $this->belongsToMany(Tag::class)->withPivot('position');
}
```

`withPivot('position')` is what makes the pivot column readable as `pivot.position` in a column and writable through `pivotForm()`. Without it Eloquent does not select it and the cell reads as null.

## A `hasMany` manager

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\RelationManagers;

use App\Panels\Admin\Resources\Products\ProductResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\DissociateAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\TableSchema;

final class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants';

    protected static ?string $icon = 'layers';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('sku')->label('SKU')->searchable(),
                NumberColumn::make('price_cents')
                    ->label('Price')
                    ->prefix('$')
                    ->decimals(2)
                    ->formatUsing(static fn (mixed $value): float => (int) $value / 100)
                    ->alignment(Alignment::End),
                NumberColumn::make('stock')->alignment(Alignment::End)->sortable(),
            ])
            ->recordActions([
                // Both arguments are needed: the manager for the scope, the
                // owner for the ability.
                EditRelatedAction::make(ProductResource::class, self::class, $owner),
                DissociateAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ])
            ->emptyState(
                heading: 'No variants yet',
                description: 'Add sizes, colours, or bundles for this product.',
            );
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('sku')->label('SKU')->required()->maxLength(64),
            NumberInput::make('price_cents')->label('Price (cents)')->integer()->min(0)->required(),
            NumberInput::make('stock')->integer()->min(0)->default(0)->required(),
        ]);
    }
}
```

There is no `product_id` field. Every write goes through `$owner->variants()`, so the foreign key is set by the relation and a field for it would be a way to set it to something else.

## A `belongsToMany` manager

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\RelationManagers;

use App\Panels\Admin\Resources\Products\ProductResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\DetachBulkAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $title = 'Tags';

    protected static ?string $icon = 'tag';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                // Pivot columns read through the same dotted path any
                // relation column uses.
                TextColumn::make('pivot.position')->label('Position'),
            ])
            ->recordActions([
                EditRelatedAction::make(ProductResource::class, self::class, $owner),
                DetachAction::make(self::class, $owner),
            ])
            ->bulkActions([
                DetachBulkAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    /**
     * The pivot columns an attach or an edit may write. Only fields declared
     * here are validated and persisted to the join row.
     */
    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            NumberInput::make('position')->integer()->min(0)->default(0),
        ]);
    }
}
```

An extra key in the request body is discarded exactly as it is on a resource form: only what `pivotForm()` declared is written to the join row.

The two halves are kept apart when the form is built. A related record's own fields render under their own names; the pivot's are namespaced under `pivot.`, which is what keeps a `position` column on the join row from colliding with one on the tag itself. `PandaPanel\Resources\RelationForm` declares both names:

```php
public const RELATED_FIELD = 'related';   // the select naming the record to attach
public const PIVOT_PREFIX = 'pivot';      // where the pivot fields live
```

## Register both

```php
/**
 * @return list<class-string<RelationManager>>
 */
public static function relationManagers(): array
{
    return [
        VariantsRelationManager::class,
        TagsRelationManager::class,
    ];
}
```

They render in declaration order beneath the record's view and edit pages, each with its own table state namespaced by the manager's key — sorting one leaves the others where they were.

## Everything a manager declares

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$relationship` | `string` | required | The relation method on the owner model |
| `$key` | `?string` | `Str::kebab($relationship)` | The key URLs and action payloads address it by |
| `$title` | `?string` | `Str::headline($relationship)` | The heading above the table |
| `$icon` | `?string` | `null` | Icon registry key, used in the record sub-navigation |
| `$recordTitleAttribute` | `?string` | `null`; `'name'` is used when reading a title | What a record is called in option lists and confirmations |
| `$with` | `list<string>` | `[]` | Relations eager loaded on every row |
| `$softDeletes` | `bool` | `false` | Offers the trashed filter and the restore actions |

```php
abstract public static function table(TableSchema $table, Model $owner): TableSchema;

public static function form(FormSchema $schema, Model $owner): FormSchema;       // returns $schema unchanged
public static function pivotForm(FormSchema $schema, Model $owner): FormSchema;  // returns $schema unchanged
```

`table()` is abstract: a manager without a table has nothing to show. The other two default to the schema untouched, because a manager that only lists, attaches, or detaches has no form — and inheriting one would offer a create button that saves nothing.

The owner travels with all three because a relation table's actions are about the *pair*, not about the related record alone. Whether a tag may be detached is a question with two subjects, and an action built without the owner could not ask it.

### The lookups

| Method | Signature | Returns |
| --- | --- | --- |
| `relationship()` | `static relationship(): string` | the declared name |
| `key()` | `static key(): string` | `$key`, or the kebab-cased relationship |
| `title()` | `static title(): string` | `$title`, or the headlined relationship |
| `icon()` | `static icon(): ?string` | `$icon` |
| `relation()` | `static relation(Model $owner): Relation` | the relation instance; throws when the method is not one |
| `query()` | `static query(Model $owner): Builder` | the relation's builder, with `$with` applied |
| `relationForTable()` | `static relationForTable(Model $owner): Relation` | the relation itself, for pagination |
| `resolveRecord()` | `static resolveRecord(Model $owner, int\|string $key): ?Model` | null for a key belonging to another owner |
| `getRelatedModel()` | `static getRelatedModel(Model $owner): class-string<Model>` | |
| `recordTitle()` | `static recordTitle(Model $record): string` | |
| `isManyToMany()` | `static isManyToMany(Model $owner): bool` | true for `BelongsToMany` and `MorphToMany` |
| `isOneToMany()` | `static isOneToMany(Model $owner): bool` | true for `HasOneOrMany` |
| `usesSoftDeletes()` | `static usesSoftDeletes(Model $owner): bool` | `$softDeletes` **and** the related model using the trait |
| `attachableOptions()` | `static attachableOptions(Model $owner, ?string $search = null, int $limit = 50): array` | value/label pairs for what may be attached |

Tables paginate through `relationForTable()` rather than `query()`. A many-to-many hydrates its pivot inside `BelongsToMany::paginate()`, and a builder taken out of the relation would produce rows whose pivot columns all read as null.

## The relation is the scope

```php
public static function query(Model $owner): Builder
{
    return static::relation($owner)->getQuery();   // plus $with
}
```

Every read and every write starts from `$owner->{relationship}()` — exactly the role `Resource::query()` plays for a resource. A record reachable from another owner is simply not in that builder:

```php
VariantsRelationManager::resolveRecord($apollo, 12);   // ?Model — null when variant 12 is another product's
```

```text
POST /admin/relations/action
{ "resource": "products", "record": 3, "relation": "variants", "action": "delete", "related": 99 }
→ 404 when variant 99 belongs to product 4, and nothing is deleted
```

The owner itself is loaded through `Resource::query()`, so a product the resource cannot reach is a 404 here too rather than a relation served for a record the user could not have opened.

## Which actions exist, and where they come from

Create, attach, and associate are **not** declared on the manager. `RelationTable` resolves all three for every relation, because each is an answer to what the relation *is* rather than a choice — a manager that had to list them would be able to offer an attach on a `hasMany`.

```php
CreateRelatedAction::make(string $resource, string $manager, Model $owner): Action
AttachAction::make(string $resource, string $manager, Model $owner): Action
AssociateAction::make(string $resource, string $manager, Model $owner): Action
```

Attach and associate are mutually exclusive by construction — each is hidden for the shape the other belongs to — so a relation offers one way to bring in an existing record, never two.

Everything else is declared in the manager's `recordActions()` and `bulkActions()`:

| Action | Signature | Applies to |
| --- | --- | --- |
| `EditRelatedAction` | `make(string $resource, string $manager, Model $owner)` | any relation |
| `DeleteRelatedAction` | `make(string $manager, Model $owner)` | any relation |
| `DetachAction` | `make(string $manager, Model $owner)` | many-to-many |
| `DetachBulkAction` | `make(string $manager, Model $owner)` | many-to-many |
| `DissociateAction` | `make(string $manager, Model $owner)` | one-to-many |
| `PandaPanel\Actions\Relations\RestoreAction` | `make(string $manager, Model $owner)` | soft-deleting related models |
| `PandaPanel\Actions\Relations\ForceDeleteAction` | `make(string $manager, Model $owner)` | soft-deleting related models |

Delete and detach are different operations and mean different things. Deleting a variant removes the record; detaching a tag removes the join row and leaves the tag alone. Dissociating a variant nulls its foreign key — which is why it is offered for a one-to-many and attach is not.

## Authorization: two policies, two questions

The split is the thing to get right. Reading and writing the *related record* are abilities on that record's own policy. Attaching and detaching are abilities on the **owner's** policy, because whether a tag may be pinned to a product is the product's business, not the tag's.

```php
// On TagPolicy — about the tag itself.
public function viewAny(User $user): bool;
public function view(User $user, Tag $tag): bool;
public function create(User $user): bool;
public function update(User $user, Tag $tag): bool;
public function delete(User $user, Tag $tag): bool;
public function restore(User $user, Tag $tag): bool;
public function forceDelete(User $user, Tag $tag): bool;
```

```php
// On ProductPolicy — about membership of the product's relations.
public function attachAny(User $user, Product $product): bool;
public function detach(User $user, Product $product, Tag $tag): bool;
public function associateAny(User $user, Product $product): bool;
public function dissociate(User $user, Product $product, Variant $variant): bool;
```

The manager methods that ask them:

| Manager method | Ability | Policy |
| --- | --- | --- |
| `canViewAny(Model $owner)` | `viewAny` | the related model's |
| `canView(Model $owner, Model $record)` | `view` | the related model's |
| `canCreate(Model $owner)` | `create` | the related model's |
| `canEdit(Model $owner, Model $record)` | `update` | the related model's |
| `canDelete(Model $owner, Model $record)` | `delete` | the related model's |
| `canRestore(Model $owner, Model $record)` | `restore` | the related model's |
| `canForceDelete(Model $owner, Model $record)` | `forceDelete` | the related model's |
| `canAttach(Model $owner)` | `attachAny` | the **owner's** |
| `canDetach(Model $owner, Model $record)` | `detach` | the **owner's**, with the record as the second argument |
| `canAssociate(Model $owner)` | `associateAny` | the **owner's** |
| `canDissociate(Model $owner, Model $record)` | `dissociate` | the **owner's**, with the record as the second argument |

A refused manager is **absent and never queried**. `RelationTable::toArray()` asks `canViewAny()` first and returns null, so a manager the user may not read costs nothing — a manager that queried and then hid its rows would still have read them.

## Giving a relation its own page

A record page with four relation tables is a page nobody reads. `ManageRelatedRecords` puts one on a route of its own, joined into the record's sub-navigation.

```bash
php artisan make:panel-relation-manager variants --panel=Admin --resource=Product --type=has-many --page
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Products\Pages;

use App\Panels\Admin\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Panels\Admin\Resources\Products\ProductResource;
use PandaPanel\Resources\Pages\ManageRelatedRecords;

final class ManageProductsVariants extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationManager = VariantsRelationManager::class;
}
```

Register it in `pages()` under the relation's key:

```php
public static function pages(): array
{
    return [
        'index' => ListProducts::class,
        'create' => CreateProduct::class,
        'view' => ViewProduct::class,
        'edit' => EditProduct::class,
        'variants' => ManageProductsVariants::class,
    ];
}
```

It routes to `/admin/products/{record}/variants` and joins the record's sub-navigation automatically.

## The endpoints

Four routes per panel, all resolving the resource and the relation against **this** panel's registry:

| Route name | Method and path | Used for |
| --- | --- | --- |
| `panel.admin.relations.form` | `GET /admin/relations/form` | fetches the form for a create, edit, attach, or associate |
| `panel.admin.relations.save` | `POST /admin/relations/form` | submits it |
| `panel.admin.relations.action` | `POST /admin/relations/action` | runs a record action |
| `panel.admin.relations.bulk` | `POST /admin/relations/bulk` | runs a bulk action |

The form endpoints take their context from the **query string** — `resource`, `record`, `relation`, `operation` — never from the body, so a field that happens to be named `resource` cannot point the request at a different one.

`operation` is `PandaPanel\Support\RelationOperation`, a closed set:

```php
enum RelationOperation: string
{
    case Create = 'create';
    case Edit = 'edit';
    case Attach = 'attach';
    case Associate = 'associate';
}
```

Each case decides which schema is built, which ability is asked, and which write runs, so an unrecognised value is a 404 rather than a fallback. `Attach` and `Associate` also check the relation's shape: an attach on a one-to-many is not "denied", it is not a thing.

## The test

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Panels\Admin\Resources\Products\RelationManagers\VariantsRelationManager;
use PandaPanel\Support\RelationOperation;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->product = Product::factory()->create(['name' => 'Keyboard']);
    $this->other = Product::factory()->create(['name' => 'Mouse']);
});

/**
 * @return array<string, mixed>
 */
function relationsFor(Product $product): array
{
    return test()
        ->get("/admin/products/{$product->getKey()}")
        ->assertOk()
        ->viewData('page')['props']['relations'];
}

it('lists only this product\'s variants', function (): void {
    $this->product->variants()->create(['name' => 'Blue', 'sku' => 'KB-B', 'price_cents' => 100, 'stock' => 1]);
    $this->other->variants()->create(['name' => 'Red', 'sku' => 'MS-R', 'price_cents' => 100, 'stock' => 1]);

    $variants = collect(relationsFor($this->product))->firstWhere('key', 'variants');

    expect(collect($variants['rows'])->pluck('cells.name')->all())->toBe(['Blue']);
});

it('resolves nothing for a variant belonging to another product', function (): void {
    $theirs = $this->other->variants()->create([
        'name' => 'Red', 'sku' => 'MS-R', 'price_cents' => 100, 'stock' => 1,
    ]);

    expect(VariantsRelationManager::resolveRecord($this->product, $theirs->getKey()))->toBeNull()
        ->and(VariantsRelationManager::resolveRecord($this->other, $theirs->getKey()))->not->toBeNull();
});

it('refuses an action on a variant from another product', function (): void {
    $theirs = $this->other->variants()->create([
        'name' => 'Red', 'sku' => 'MS-R', 'price_cents' => 100, 'stock' => 1,
    ]);

    $this->post('/admin/relations/action', [
        'resource' => 'products',
        'record' => $this->product->getKey(),
        'relation' => 'variants',
        'action' => 'delete',
        'related' => $theirs->getKey(),
    ])->assertNotFound();

    expect($this->other->variants()->count())->toBe(1);
});

it('creates a variant through the relation, without a foreign key field', function (): void {
    $this->post('/admin/relations/form?'.http_build_query([
        'resource' => 'products',
        'record' => $this->product->getKey(),
        'relation' => 'variants',
        'operation' => RelationOperation::Create->value,
    ]), [
        'name' => 'Blue',
        'sku' => 'KB-B',
        'price_cents' => 12900,
        'stock' => 3,
    ])->assertRedirect();

    expect($this->product->variants()->count())->toBe(1);
});

it('writes only the pivot columns the pivot form declared', function (): void {
    $tag = Tag::query()->create(['name' => 'Featured']);

    $this->post('/admin/relations/form?'.http_build_query([
        'resource' => 'products',
        'record' => $this->product->getKey(),
        'relation' => 'tags',
        'operation' => RelationOperation::Attach->value,
    ]), [
        // `related` names the record being attached; the pivot fields are
        // namespaced under `pivot.` so a `position` column on the join row
        // cannot collide with one on the tag itself.
        'related' => $tag->getKey(),
        'pivot' => ['position' => 3],
        // Not a field on either form, so it is discarded rather than written.
        'created_at' => '1999-01-01',
    ])->assertRedirect();

    expect($this->product->tags()->first()?->pivot->position)->toBe(3);
});
```

```bash
php artisan test --compact --filter=Relation
```

`tests/Feature/Panel/RelationManagerTest.php` is the framework's own version of these, against `Project` / `Task` / `Label` fixtures that cover a `hasMany`, a `belongsToMany` with a pivot column, and a `hasOne` at once.

## Gotchas

- **Nothing is registered until `relationManagers()` names it.** The generator writes the class and says so; there is no discovery for relation managers.
- **`--type` is a real decision.** A `belongs-to-many` manager on a `hasMany` relation generates detach actions that can never authorize, because `RelationOperation::Attach` checks the shape as well as the ability.
- **`$softDeletes` needs both halves.** `usesSoftDeletes()` is `$softDeletes = true` **and** the related model actually using the trait. Declaring it on a model that does not is a filter that does nothing.
- **Restore actions need the trashed filter.** The filter is what puts a deleted record on screen; without it the restore button can never appear. `--soft-deletes` adds both for exactly that reason.
- **`pivot.position` needs `withPivot('position')`.** Eloquent does not select a pivot column you did not ask for, and the cell reads as null rather than failing.
- **A relation manager is not a resource.** It has no routes of its own unless you add a `ManageRelatedRecords` page, no global search, and no navigation entry.
- **When to use a nested resource instead.** A relation manager is a table beside a record; a nested resource has its own list, create, view, and edit pages under the parent's URL. See [Nested vs Relation Manager](../relations/nested-vs-relation-manager.md).

## See also

- [Product Resource](product-resource.md) — the owner these managers hang off
- [Relation Managers](../relations/relation-managers.md)
- [Relation Tables](../relations/relation-tables.md), [Relation Forms](../relations/relation-forms.md)
- [Pivot Fields](../relations/pivot-fields.md)
- [Attach and Detach](../relations/attach-detach.md), [Associate and Dissociate](../relations/associate-dissociate.md)
- [Relation Policies](../relations/policies.md)
- [Soft Deleted Relations](../relations/soft-deletes.md)
- [Relation Pages](../relations/relation-pages.md)
- [Nested vs Relation Manager](../relations/nested-vs-relation-manager.md)
- [Nested Resources](../resources/nested-resources.md)
- [make:panel-relation-manager](../cli/make-panel-relation-manager.md)
