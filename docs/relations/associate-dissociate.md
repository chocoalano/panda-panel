# Associate And Dissociate

Associating adopts an existing record into a one-to-many relation by writing its foreign key; dissociating removes it by nulling that key. They are the `hasMany`/`hasOne` answer to [attach and detach](attach-detach.md): there is no join row to create or destroy, so the child simply changes whom it belongs to. Each pair is hidden for the shape the other belongs to, so a relation offers one way to bring in an existing record, never two.

## A manager that associates and dissociates

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Projects\RelationManagers;

use App\Panels\Admin\Resources\Projects\ProjectResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\DissociateAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectResource::class, self::class, $owner),
                DissociateAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }
}
```

with a nullable foreign key on the child:

```php
Schema::table('tasks', function (Blueprint $table): void {
    $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
});
```

The associate button appears above the table by itself — `AssociateAction` is a header action resolved by `RelationTable`, not something the manager declares. `DissociateAction` is a record action you place yourself.

## `AssociateAction`

```php
use PandaPanel\Actions\Relations\AssociateAction;

AssociateAction::make(string $resource, string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `associate` |
| Label | `Associate {manager title}` |
| Icon | `link` |
| Variant | `ActionVariant::Outline` |
| Type | form — opens a dialog fetched from the relation form endpoint |
| Visible when | `RelationManager::isOneToMany($owner)` |
| Authorized by | `RelationManager::canAssociate($owner)` → `associateAny` on the **owner** |

The dialog holds one field: a searchable select naming the record to adopt.

```text
GET  /{panel}/relations/form?resource=projects&record=7&relation=tasks&operation=associate
POST /{panel}/relations/form?resource=projects&record=7&relation=tasks&operation=associate
{ "related": "12" }
```

The write is `$relation->save($related)` on a `HasOneOrMany`, which is what sets the foreign key — and the morph type when there is one:

```php
$related = $relation->getRelated()->newQuery()->find($key);

if ($related !== null) {
    $relation->save($related);
}
```

### What may be associated

The options come from `RelationManager::attachableOptions()`, the same method the attach dialog uses: every record of the related model that is **not already in this relation**, capped at 50 and searchable through the options endpoint.

```text
GET /{panel}/options?resource=projects&record=7&relation=tasks&operation=associate&field=related&search=orph
```

"Not already in this relation" is not the same as "belongs to nobody". A child currently owned by another record is offered, and associating it moves it — which is what adopting an existing record means. Narrow `attachableOptions()` on the manager if only orphans should be offered:

```php
/**
 * @return list<array{value: string, label: string}>
 */
public static function attachableOptions(Model $owner, ?string $search = null, int $limit = 50): array
{
    $options = [];

    $query = Task::query()->whereNull('project_id')->orderBy('name')->limit($limit);

    if ($search !== null && $search !== '') {
        $query->where('name', 'like', '%'.$search.'%');
    }

    foreach ($query->get() as $task) {
        $options[] = ['value' => (string) $task->getKey(), 'label' => static::recordTitle($task)];
    }

    return $options;
}
```

Validation is still `exists` on the related table rather than membership of the rendered list — the list is one bounded page, and a real key that sorted past the limit is still a real key. A record already in the relation is refused separately:

```text
POST .../operation=associate   { "related": "12" }
→ 422 "That record is already in this relation."
```

## `DissociateAction`

```php
use PandaPanel\Actions\Relations\DissociateAction;

DissociateAction::make(string $manager, Model $owner): Action;
```

| Property | Value |
| --- | --- |
| Name | `dissociate` |
| Label | `Dissociate` |
| Icon | `unlink` |
| Variant | `ActionVariant::Ghost` |
| Confirmation | "Dissociate this record?" — "The record is kept but no longer belongs to this one." |
| Success message | `Record dissociated.` |
| Visible when | `RelationManager::isOneToMany($owner)` |
| Authorized by | `RelationManager::canDissociate($owner, $record)` → `dissociate` on the **owner**, with the record |

The handler nulls the relation's foreign key on the child and saves it:

```php
$foreignKey = $relation->getForeignKeyName();

$record->setAttribute($foreignKey, null)->save();
```

```text
POST /{panel}/relations/action
{ "resource": "projects", "record": 7, "relation": "tasks", "action": "dissociate", "related": 12 }
→ tasks.project_id is null, the task still exists, and it leaves this table
```

There is no bulk dissociate action in the package. A relation that needs one declares an ordinary bulk action:

```php
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use Symfony\Component\HttpKernel\Exception\HttpException;

Action::make('dissociate')
    ->label('Dissociate selected')
    ->icon('unlink')
    ->variant(ActionVariant::Destructive)
    ->requiresConfirmation(heading: 'Dissociate the selected records?')
    ->successMessage('Selected records dissociated.')
    ->authorize(static fn (?Model $record): bool => $record === null
        || self::canDissociate($owner, $record))
    ->bulkAction(static function (Collection $records) use ($owner): void {
        foreach ($records as $record) {
            if (! self::canDissociate($owner, $record)) {
                throw new HttpException(403, 'You may not dissociate every selected record.');
            }
        }

        $foreignKey = self::relation($owner)->getForeignKeyName();

        DB::transaction(static function () use ($records, $foreignKey): void {
            $records->each(static fn (Model $record) => $record
                ->setAttribute($foreignKey, null)
                ->save());
        });
    });
```

Authorize every record before writing any of them, exactly as `DetachBulkAction` does: a selection containing one forbidden record should change nothing rather than half of it.

## Associate has no pivot half

`RelationForm` builds pivot fields only for a many-to-many, and skips them for `RelationOperation::Associate` even then. A one-to-many has no join row to write, so a `pivotForm()` on such a manager would render inputs that save nothing. See [Pivot fields](pivot-fields.md).

## Customizing the actions

`DissociateAction::make()` returns a `PandaPanel\Actions\Action`, so the builder methods apply:

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Relations\DissociateAction;

DissociateAction::make(self::class, $owner)
    ->label('Move out of project')
    ->variant(ActionVariant::Outline)
    ->requiresConfirmation(
        heading: 'Move this task out?',
        description: 'The task stays in the backlog, unassigned.',
        button: 'Move out',
    )
    ->successMessage('Task moved out of the project.');
```

`AssociateAction` is built by `RelationTable::headerActions()` and is not constructed by the manager, so it is not customizable the same way. What it offers is: `$title` sets its label, and `attachableOptions()` decides its option list.

## Gotchas

- **The foreign key must be nullable.** Nothing in the framework checks it: `DissociateAction` is offered for any one-to-many, and on a `NOT NULL` column the save fails at the database. Where the column cannot be null, the honest operation is a delete.
- **`HasOne` is a one-to-many too.** `isOneToMany()` is a check for `HasOneOrMany`, so associate and dissociate are offered on a `hasOne`. Associating a second record there writes a second row pointing at the owner, and the relation then returns whichever the database orders first.
- **Associate can take a child from another owner.** The option list excludes only records already in *this* relation. Narrow `attachableOptions()` when that is not what you mean.
- **`associateAny` and `dissociate` live on the *owner's* policy.** A missing method is the usual cause of an associate button that never appears — and under `Panel::strictAuthorization()` it is an exception rather than a silent deny. See [Related record policies](policies.md).
- **`->authorize()` replaces the built-in check.** Chaining it onto `DissociateAction::make()` throws away `canDissociate()`; call it inside your own closure if you narrow it.
- **Attach and associate cannot both appear.** Each checks the relation's shape, in the action's visibility *and* at the endpoint: an associate posted against a `belongsToMany` is 403, and an attach posted against a `hasMany` is 403.
- **Dissociating does not delete.** The record survives owning nothing. `DeleteRelatedAction` is the one that removes it.

## See also

- [Attach and detach](attach-detach.md)
- [Relation managers](relation-managers.md)
- [Relation forms](relation-forms.md)
- [Pivot fields](pivot-fields.md)
- [Related record policies](policies.md)
- [Relation tables](relation-tables.md)
- [Nested resource vs relation manager](nested-vs-relation-manager.md)
- [Bulk actions](../actions/bulk-actions.md)
