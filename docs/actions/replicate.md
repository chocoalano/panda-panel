# Replicate

`PandaPanel\Actions\ReplicateAction` copies a record. You reach for it wherever a new record usually starts life as a near-copy of an existing one — a product variant, next month's invoice template, a campaign that differs from last week's in two fields.

Eloquent's own `replicate()` does the work, which means the copy already excludes the primary key and the timestamps. What it does not know is which of *this* model's columns must not be duplicated — a unique slug, an invoice number, an API token — so `except` names them and they are left at their database defaults rather than carried over into a row that will collide.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Tables;

use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\ReplicateAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class PostsTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('title')->searchable()->sortable()])
            ->recordActions([
                ReplicateAction::make(PostResource::class),
            ]);
    }
}
```

Every row gets a Replicate button. Pressing it confirms, copies the record, and redirects back with `Record replicated.`

## The signature

```php
use Closure;
use PandaPanel\Actions\Action;
use PandaPanel\Resources\Resource as PanelResource;

ReplicateAction::make(
    string $resource,        // class-string<PanelResource>
    array $except = [],      // list<string> — columns the copy must not carry over
    ?Closure $using = null,  // fn (Model $copy, Model $original): void
): Action
```

| Property | Value |
| --- | --- |
| Name | `replicate` |
| Label | Replicate |
| Icon | `copy` |
| Variant | `ActionVariant::Outline` |
| Type | `callback` |
| Confirmation heading | `Replicate this record?` |
| Confirmation description | `A copy will be created. You can edit it afterwards.` |
| Confirmation button | `Replicate` |
| Success message | `Record replicated.` |
| Authorized by | `Resource::canCreate()` **and** `Resource::canView($record)` |

Creating is the ability it needs: a copy is a new record, and being allowed to see one is not being allowed to make another. Both are asked, because copying a record you cannot see is not a thing either.

## Excluding columns

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\ReplicateAction;

ReplicateAction::make(PostResource::class, except: ['slug', 'published_at', 'view_count']);
```

`$except` is passed straight to `Model::replicate($except)`, so the named attributes are absent from the copy and take whatever the database default is on insert. Name every column with a unique index on it — otherwise the save throws an integrity constraint violation, which reaches the user as a 500 rather than as anything actionable.

The key and the model's timestamps are already excluded by Eloquent and do not need listing.

## Adjusting the copy

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\ReplicateAction;

ReplicateAction::make(
    PostResource::class,
    except: ['slug', 'published_at'],
    using: static function (Model $copy, Model $original): void {
        $copy->forceFill([
            'title' => $original->getAttribute('title').' (copy)',
            'slug' => Str::uuid()->toString(),
            'status' => 'draft',
        ]);
    },
);
```

`$using` receives the unsaved copy first and the record it came from second. It runs after `replicate()` and before `save()`, which is the only window in which the copy exists and is still changeable.

Named arguments are worth using here. `ReplicateAction::make(PostResource::class, [], $closure)` is the same call and reads as nothing at all.

The whole operation, in outline:

```php
$copy = $record->replicate($except);

if ($using !== null) {
    $using($copy, $record);
}

$copy->save();
```

## A worked example

From the shipped admin panel, replicating a user:

```php
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Actions\ReplicateAction;

// The copy keeps neither the address nor the verification: an email is
// unique, and a duplicated verification would mark an account confirmed that
// never confirmed anything.
ReplicateAction::make(
    UserResource::class,
    except: ['email', 'email_verified_at'],
    using: static function (Model $copy, Model $original): void {
        $copy->forceFill([
            'name' => $original->getAttribute('name').' (copy)',
            'email' => 'copy-'.Str::random(8).'@example.test',
        ]);
    },
);
```

The two halves work together: `except` prevents a collision, and `using` supplies a value that will not collide either. Excluding a `NOT NULL` column without supplying a replacement moves the failure from the unique index to the null constraint.

## Overriding the rest

The factory returns an ordinary `Action`, so everything else can be chained:

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\ReplicateAction;

ReplicateAction::make(PostResource::class, except: ['slug'])
    ->label('Duplicate')
    ->icon('files')
    ->variant(ActionVariant::Ghost)
    ->requiresConfirmation(
        heading: 'Duplicate this post?',
        description: 'The copy is a draft, and nothing is published.',
        button: 'Duplicate it',
    )
    ->successMessage('Post duplicated.')
    ->after(static fn (Model $record) => logger()->info('replicated', ['id' => $record->getKey()]));
```

`before()` and `after()` receive the **original** record, not the copy — they are the action's hooks, and the action's record is the one it was invoked on. Anything the copy needs belongs in `$using`.

## Where it can go

`ReplicateAction` produces a record action: it needs a record, so it belongs in `recordActions()` or on an infolist.

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use PandaPanel\Actions\ReplicateAction;
use PandaPanel\Infolists\InfolistSchema;

$table->recordActions([ReplicateAction::make(PostResource::class)]);

$infolist->actions([ReplicateAction::make(PostResource::class)]);
```

In a bulk set it would still run — `executeBulk()` falls back to `execute()` per record — but each copy would be a separate transaction and the confirmation copy is written for one record. Write a `bulkAction()` if duplicating a selection is genuinely wanted.

## Testing

```php
use App\Panels\Admin\Resources\Posts\PostResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\ReplicateAction;

it('replicates without the columns that must not be duplicated', function (): void {
    $post = Post::query()->create(['title' => 'Apollo', 'slug' => 'apollo']);

    ReplicateAction::make(
        PostResource::class,
        except: ['slug'],
        using: static fn (Model $copy) => $copy->forceFill(['slug' => 'apollo-copy']),
    )->execute($post);

    expect(Post::query()->count())->toBe(2)
        ->and(Post::query()->latest('id')->value('slug'))->toBe('apollo-copy');
});

it('refuses to replicate for somebody who may not create', function (): void {
    $action = ReplicateAction::make(PostResource::class);

    expect($action->isAuthorizedFor($post))->toBeFalse();
});
```

## Gotchas

- **Relations are not copied.** `replicate()` copies attributes and nothing else, so a post with tags produces a copy with no tags. Copying a relation needs the copy to have a key, which it does not have until `save()` returns — so it belongs on the model, in a `replicating` or `saved` listener, rather than in `$using`.
- **`forceFill()` in `$using` bypasses `$fillable`.** That is deliberate — you are writing server-side values, not request input — but it also means a typo writes an attribute the model never declared.
- **Excluding a `NOT NULL` column without a default is a database error.** `except` leaves the attribute unset; the insert then fails.
- **Files are not copied.** An attribute holding a path is duplicated as a string, so both records point at the same file on disk. Deleting one record's file removes the other's too.
- **There is no "then edit the copy" redirect.** The action redirects back to the list with a flash. Reaching the copy's edit page is a separate navigation.
- **The confirmation is on by default** and describes one record. Change the copy with `requiresConfirmation()` if the wording does not fit.

## See also

- [Built-in actions](built-in-actions.md)
- [CRUD actions](crud-actions.md)
- [Action basics](overview.md)
- [Action authorization](authorization.md)
- [Custom actions](custom-actions.md)
- [Bulk actions](bulk-actions.md)
- [Record actions on a table](../tables/record-actions.md)
- [Resource authorization](../resources/authorization.md)
