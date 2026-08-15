# Lifecycle Hooks

The points a create or edit page lets you interfere with: shaping the values a form opens with, shaping what is validated and saved, running a side effect afterwards, or stopping the whole thing. Every hook lives on `PandaPanel\Resources\Concerns\HasLifecycleHooks`, which every resource page already uses, so overriding one is all the wiring there is.

## The minimal hook

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Pages;

use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Resources\Pages\CreateRecord;

final class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
    {
        $data['slug'] = Str::slug((string) $data['title']);

        return $data;
    }
}
```

Nothing else changes. The hook is called on the way to the write, and `slug` is persisted alongside the fields the form declared.

## Two kinds, and the split is the point

A `mutate*` hook takes data and returns it; it exists to shape what is shown or saved. Every other hook returns nothing and exists for side effects and for halting. Conflating them would leave two places to change a value and no obvious place to stop.

```text
fill:    beforeFill → mutateFormDataBeforeFill → afterFill

create:  beforeValidate → validate → afterValidate → beforeCreate
         → mutateFormDataBeforeCreate → mutateFormDataBeforeSave → beforeSave
         → handleRecordCreation → saveRelations → afterCreate → afterSave

update:  beforeValidate → validate → afterValidate
         → mutateFormDataBeforeSave → beforeSave → handleRecordUpdate
         → saveRelations → afterSave
```

The order is asserted by the package's own tests against real invocations, not against this page.

## Rendering a form

```php
protected function beforeFill(): void;
protected function mutateFormDataBeforeFill(array $data): array;
protected function afterFill(array $data): void;
```

These run on both create and edit, while the page is being rendered. On create the values are the field defaults; on edit they come from the record.

The data is a flat `name => value` map rather than the serialized component tree, so a page shaping one field does not have to know how the schema is nested.

```php
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
protected function mutateFormDataBeforeFill(array $data): array
{
    $data['author_id'] = auth()->id();

    return $data;
}
```

`beforeFill()` may halt. A halt while rendering means the page decided not to be shown, and the user is redirected to the resource index.

## Validating

```php
protected function beforeValidate(array $input): array;
protected function afterValidate(array $data): array;
```

`beforeValidate()` receives the raw request body and returns what will be validated, so a value the user never sees can still be validated:

```php
/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
protected function beforeValidate(array $input): array
{
    $input['team_id'] = auth()->user()->current_team_id;

    return $input;
}
```

`afterValidate()` receives the validated data and returns it. Only fields the schema declares are validated at all — an extra key in the request body is discarded rather than mass-assigned.

`beforeValidate()` also runs for a wizard's per-step validation, so a value it injects is available to the step check as well as to the final submit.

## Creating

```php
protected function beforeCreate(): void;
protected function mutateFormDataBeforeCreate(array $data): array;
```

`beforeCreate()` runs after validation and before anything is shaped or written — the last place to halt cheaply. `mutateFormDataBeforeCreate()` is the create-only shaping step; anything that applies to both create and update belongs one step later.

Neither is called on an update. An `EditRecord` that overrides them is overriding something that never runs.

## Saving, on both paths

```php
protected function mutateFormDataBeforeSave(array $data, ?Model $record): array;
protected function beforeSave(?Model $record): void;
```

`$record` is `null` on create and the record being edited on update, which is what lets one implementation serve both:

```php
use Illuminate\Database\Eloquent\Model;

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
{
    $data['edited_by'] = auth()->id();

    if ($record === null) {
        $data['created_by'] = auth()->id();
    }

    return $data;
}
```

`beforeSave()` runs immediately before the write — and **outside** the transaction, because the transaction opens around the persist step itself.

## The write

```php
use Illuminate\Database\Eloquent\Model;

protected function handleRecordCreation(array $attributes): Model;              // CreateRecord
protected function handleRecordUpdate(Model $record, array $attributes): Model; // EditRecord
```

These are the write itself, and they receive dehydrated attributes rather than form data. Override one to persist through a service, a factory, or a relationship rather than a bare save:

```php
use App\Services\PostPublisher;
use Illuminate\Database\Eloquent\Model;

/**
 * @param  array<string, mixed>  $attributes
 */
protected function handleRecordCreation(array $attributes): Model
{
    return app(PostPublisher::class)->create($attributes);
}
```

Immediately after, and inside the same transaction, the schema writes the record's related rows and pivot rows. They need a key that did not exist a moment earlier, which is why they are not part of the attributes — and why an `afterCreate()` hook already sees them.

## Afterwards

```php
use Illuminate\Database\Eloquent\Model;

protected function afterCreate(Model $record): void;   // create only
protected function afterSave(Model $record): void;     // create and update
```

```php
use App\Jobs\WarmPostCache;
use Illuminate\Database\Eloquent\Model;

protected function afterCreate(Model $record): void
{
    WarmPostCache::dispatch($record->getKey());
}
```

Both run inside the transaction, so a hook that throws rolls the write back rather than leaving a half-applied change. Dispatching a job from inside one is only safe with a queue connection that respects `after_commit`.

## Halting

```php
protected function halt(): never;
```

`$this->halt()` stops the lifecycle from any hook. Nothing after the calling hook runs, and nothing is written. It throws `PandaPanel\Exceptions\Halt`, which the page catches: a halt is a decision the page made, not an error, so it never surfaces as a 500 or leaks a stack trace.

```php
protected function beforeCreate(): void
{
    if (! auth()->user()->hasQuotaRemaining()) {
        session()->flash('error', 'Your plan is at its limit.');

        $this->halt();
    }
}
```

Where the user lands depends on when it happened: a halt during `handle()` returns them where they were, and a halt during `render()` redirects to the resource index.

## Transactions

```php
protected static ?bool $hasDatabaseTransactions = null;
```

`null` inherits the panel, which has transactions on by default. The persist step, the relation writes, and the `after*` hooks share one transaction; everything before `handleRecord*` runs outside it.

Set it on a page whose write cannot sensibly hold one open — one that also calls an external service, say:

```php
protected static ?bool $hasDatabaseTransactions = false;
```

Outside a panel — a page controller called directly in a test, a queued job — there is no panel to ask and the answer is on.

## What happens afterwards

```php
use Illuminate\Database\Eloquent\Model;

protected function getRedirectUrl(Model $record): string;
protected function createdNotification(Model $record): ?array;   // CreateRecord
protected function savedNotification(Model $record): ?array;     // EditRecord
```

Both notification methods return `['type' => ..., 'message' => ...]`, or `null` for a page that should say nothing. See [CRUD pages](crud-pages.md).

## Deletion has no hooks here

Deleting runs through the action endpoint, which executes without a page instance, so a `beforeDelete()` on this trait could never be called. Use the action's own hooks, which share the action's transaction:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PandaPanel\Actions\DeleteAction;

DeleteAction::make(PostResource::class)
    ->before(static function (Model $record, array $data): void {
        Cache::forget('post:'.$record->getKey());
    })
    ->after(static function (Model $record, array $data): void {
        Log::info('Deleted post '.$record->getKey());
    });
```

Both callbacks receive the record and the action's own form data, and both run inside the action's transaction.

See [Actions](../actions/overview.md).

## Reference

| Hook | Signature | Runs on | Returns |
| --- | --- | --- | --- |
| `beforeFill` | `protected function beforeFill(): void` | render | — |
| `mutateFormDataBeforeFill` | `protected function mutateFormDataBeforeFill(array $data): array` | render | the values the form opens with |
| `afterFill` | `protected function afterFill(array $data): void` | render | — |
| `beforeValidate` | `protected function beforeValidate(array $input): array` | create, update, wizard step | the input to validate |
| `afterValidate` | `protected function afterValidate(array $data): array` | create, update | the validated data |
| `beforeCreate` | `protected function beforeCreate(): void` | create | — |
| `mutateFormDataBeforeCreate` | `protected function mutateFormDataBeforeCreate(array $data): array` | create | the data |
| `mutateFormDataBeforeSave` | `protected function mutateFormDataBeforeSave(array $data, ?Model $record): array` | create, update | the data |
| `beforeSave` | `protected function beforeSave(?Model $record): void` | create, update | — |
| `handleRecordCreation` | `protected function handleRecordCreation(array $attributes): Model` | create | the new record |
| `handleRecordUpdate` | `protected function handleRecordUpdate(Model $record, array $attributes): Model` | update | the record |
| `afterCreate` | `protected function afterCreate(Model $record): void` | create | — |
| `afterSave` | `protected function afterSave(Model $record): void` | create, update | — |
| `halt` | `protected function halt(): never` | anywhere | never returns |

## Notes

- **Every hook has a working no-op default and is genuinely called.** Overriding one is the entire wiring; there is no registration step.
- **A `mutate*` hook that forgets to return the array wipes the data.** They are transformations, not side effects.
- **`beforeSave()` is outside the transaction.** Only the write and the `after*` hooks are inside it, so a side effect in `beforeSave()` survives a failed write.
- **`mutateFormDataBeforeCreate()` and `beforeCreate()` never run on update.** Use `mutateFormDataBeforeSave()` for anything both paths need.
- **A hook is not a place to hide a workflow.** These are for shaping data and small side effects; substantial business logic belongs in an action or a service class that a hook calls.
- **Halting is not failing.** No exception surfaces, nothing is written, and the user is not shown an error page.

## See also

- [CRUD pages](crud-pages.md)
- [Resource pages](resource-pages.md)
- [Model binding](model-binding.md)
- [Resource queries](queries.md)
- [Actions](../actions/overview.md)
- [Action transactions](../actions/transactions.md)
- [Form validation](../forms/validation.md)
- [Hydration and dehydration](../forms/hydration.md)
- [Form relationships](../forms/relationships.md)
