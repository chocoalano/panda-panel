# Pivot Fields

A many-to-many join row can carry columns of its own — a role, a position, a note — and a relation manager declares them with `pivotForm()`. They render beside the related record's fields, validate in the same pass, and persist to the join table rather than to the record. Reach for this whenever the fact you want to store belongs to the *pairing* and not to either record.

## A manager with pivot columns

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Projects\RelationManagers;

use App\Panels\Admin\Resources\Projects\ProjectResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class LabelsRelationManager extends RelationManager
{
    protected static string $relationship = 'labels';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                // Pivot columns read through the same dotted attribute path
                // any relation column uses.
                TextColumn::make('pivot.role')->label('Role'),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectResource::class, self::class, $owner),
                DetachAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            Select::make('role')->options([
                'primary' => 'Primary',
                'secondary' => 'Secondary',
            ]),
        ]);
    }
}
```

The relation on the owner model has to say the column exists, or Eloquent never selects it:

```php
public function labels(): BelongsToMany
{
    return $this->belongsToMany(Label::class)->withPivot('role');
}
```

## The signature

```php
public static function pivotForm(FormSchema $schema, Model $owner): FormSchema;
```

Empty by default — a plain join row needs nothing declared. It receives a schema already set to the operation's page (`create` or `edit`), so `hiddenOn()` and friends behave as they do on a resource form.

## The `pivot.` namespace

Every field `pivotForm()` declares is prefixed once, when the relation form is assembled:

```php
use PandaPanel\Resources\RelationForm;

RelationForm::PIVOT_PREFIX;   // 'pivot'
```

```php
$field->getName();       // 'pivot.role'  — the wire, the rules, the errors
$field->getAttribute();  // 'role'        — the column on the join table
```

That prefix is the whole point: it keeps a `role` column on the join table from overwriting a `role` column on the record. The two are different fields with different names, and both can appear on one form.

The serialized schema puts the record's fields first and the pivot's after:

```php
// GET /admin/relations/form?resource=projects&record=7&relation=labels&operation=create
// form.schema names:
['name', 'pivot.role']
```

The browser submits the nested shape Laravel validates natively:

```json
{ "name": "Urgent", "pivot": { "role": "primary" } }
```

and an error comes back under `pivot.role`, which is the key the field renders with.

## Where pivot fields appear

| Operation | Pivot fields rendered | Written by |
| --- | --- | --- |
| `create` | yes, on a many-to-many | `BelongsToMany::attach($key, $pivot)` after the record is saved |
| `edit` | yes, on a many-to-many | `BelongsToMany::updateExistingPivot($key, $pivot)` |
| `attach` | yes | `BelongsToMany::attach($key, $pivot)` |
| `associate` | no | — a one-to-many has no join row |
| any operation on a relation that is not a many-to-many | no | — |

Declaring pivot fields on a `hasMany` is a mistake, and rendering them would hide it behind inputs that save nothing. `RelationForm` drops them instead.

## What gets persisted

Only fields `pivotForm()` declares, one at a time, through the same three questions a resource form asks of every field:

```php
$field->shouldDehydrate($value);   // false skips the column entirely
$field->getDehydrateKey();         // the join-table column, from dehydrateTo() or the name
$field->mutate($value, null);      // dehydrateStateUsing()/mutateUsing(), with no record
```

A key in the request body with no field behind it is discarded:

```text
{ "related": "4", "pivot": { "role": "primary", "smuggled": "value" } }
→ the join row has role = primary, and nothing named smuggled is written
```

The record is not a second argument to `mutate()` for a pivot field — there is no pivot model in hand at that point, so a callback that needs one has nothing to be given.

```php
use PandaPanel\Forms\Components\NumberInput;

NumberInput::make('position')
    ->integer()
    ->dehydrateTo('sort_order')          // writes to a differently named column
    ->mutateUsing(static fn (mixed $value): int => (int) $value);
```

## Reading a pivot column back

Any column type reads it through the dotted path, because `Column::resolveValue()` is `data_get()`:

```php
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\DateColumn;
use PandaPanel\Tables\Columns\TextColumn;

TextColumn::make('pivot.role')->label('Role'),
BadgeColumn::make('pivot.status'),
DateColumn::make('pivot.created_at')->label('Added'),
```

This works because a relation table paginates the relation itself rather than a builder taken out of it: `BelongsToMany::paginate()` is what selects and hydrates the pivot, and rows fetched any other way have pivot columns that all read as null. See [Relation tables](relation-tables.md).

Pivot timestamps need `->withTimestamps()` on the relation, exactly as they do outside a panel.

## Validation

Rules are keyed by the full name, so they arrive already namespaced:

```php
use PandaPanel\Resources\RelationForm;
use PandaPanel\Support\RelationOperation;

$form = RelationForm::for(LabelsRelationManager::class, $project, RelationOperation::Attach);

array_keys($form->validationRules());
// ['related', 'pivot.role']
```

Everything in [Validation](../forms/validation.md) applies — `required()`, `maxLength()`, `rules()`, `rulesUsing()` — and the rules are evaluated in the same pass as the record's own.

## What is not supported

- **Pivot fields on anything but a many-to-many.** They are dropped rather than rendered.
- **A pivot form on `associate`.** There is no join row.
- **Sorting or searching by a pivot column out of the box.** `TextColumn::make('pivot.role')->sortable()` would order by a column literally named `pivot.role`. Pass a real column: `sortable(column: 'label_project.role')`.
- **Editing the pivot of several rows at once.** The bulk actions the package ships detach, restore, and force delete; a pivot write is per row.
- **Reading a pivot column the relation does not declare.** `withPivot()` is what puts it in the select.

## Gotchas

- **`withPivot()` is required to read, not to write.** An attach writes any declared column; the table shows null for one the relation never selected. The symptom is a value that is in the database and not on screen.
- **An edit only touches the pivot when there is something to write.** `updateExistingPivot()` runs when the pivot attributes are non-empty, so a form whose pivot fields all dehydrate to nothing leaves the join row alone.
- **Two fields may not share a name across the halves after prefixing.** `name` on the record and `role` on the pivot are `name` and `pivot.role`, which never collide — but two `role` fields inside `pivotForm()` still do.
- **A custom pivot model (`->using()`) is not consulted for the write.** The attach and the update go through the relation's own methods, so a pivot model's casts and events apply exactly as they do for `attach()` and `updateExistingPivot()` anywhere else.
- **Pivot values are validated even when the record half is not rendered.** An attach form is one select plus the pivot fields; the pivot rules still run.

## See also

- [Attach and detach](attach-detach.md)
- [Relation forms](relation-forms.md)
- [Relation tables](relation-tables.md)
- [Relation managers](relation-managers.md)
- [Associate and dissociate](associate-dissociate.md)
- [Relationship forms](../forms/relationships.md)
- [Validation](../forms/validation.md)
- [Hydration and dehydration](../forms/hydration.md)
- [Relationship columns](../tables/relationships.md)
