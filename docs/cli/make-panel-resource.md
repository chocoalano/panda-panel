# `make:panel-resource`

Generates a resource together with the classes it needs to work: its pages, its
table, and its form. Reach for it whenever a model should become a CRUD screen
inside a panel.

```bash
php artisan make:panel-resource User --panel=Admin
```

```text
INFO  Created [app/Panels/Admin/Resources/Users/UserResource.php]
INFO  Created [app/Panels/Admin/Resources/Users/Pages/ListUsers.php]
INFO  Created [app/Panels/Admin/Resources/Users/Pages/CreateUser.php]
INFO  Created [app/Panels/Admin/Resources/Users/Pages/ViewUser.php]
INFO  Created [app/Panels/Admin/Resources/Users/Pages/EditUser.php]
INFO  Created [app/Panels/Admin/Resources/Users/Tables/UsersTable.php]
INFO  Created [app/Panels/Admin/Resources/Users/Forms/UserForm.php]
```

Nothing else is required. The panel's `discoverResources()` path already covers
`app/Panels/Admin/Resources`, so `/admin/users` answers on the next request.

## Signature

```text
make:panel-resource
    {name : The resource name, for example User}
    {--panel= : The panel it belongs to}
    {--model= : The model class, defaults to App\Models\{name}}
    {--simple : Generate only a list page, for modal-based editing}
    {--no-view : Omit the view page}
    {--soft-deletes : Reach trashed records, and add the trashed filter with restore and force-delete actions}
    {--force}
```

| Argument / option | Default | Effect |
| --- | --- | --- |
| `name` | required | Singularized and studly-cased: `users`, `User` and `user` all produce the class `User` in the directory `Users`. |
| `--panel=` | required | The panel directory to generate into. Studly-cased, so `admin` and `Admin` are the same panel. Omitting it fails the command. |
| `--model=` | `App\Models\{name}` | Any fully-qualified model class. |
| `--simple` | off | Only the list page. No create, view or edit page, and no actions that point at them. |
| `--no-view` | off | Drops the view page and the `ViewAction` that opens it. |
| `--soft-deletes` | off | Declares `$softDeletes` on the resource and generates the trashed filter with the restore and force-delete actions, row and bulk. |
| `--force` | off | Overwrite files that already exist. |

```bash
php artisan make:panel-resource User --panel=Admin
php artisan make:panel-resource User --panel=Admin --simple
php artisan make:panel-resource User --panel=Admin --no-view
php artisan make:panel-resource User --panel=Admin --soft-deletes
php artisan make:panel-resource Account --panel=Admin --model="App\Models\User"
```

Every flag changes the output. A flag that were accepted and ignored would be
worse than not having it, because it would read as supported.

## The directory it builds

```text
app/Panels/Admin/Resources/Users/
    UserResource.php          the resource: model, navigation, pages()
    Forms/UserForm.php        the form schema, in a class of its own
    Tables/UsersTable.php     the table schema, in a class of its own
    Pages/ListUsers.php
    Pages/CreateUser.php
    Pages/ViewUser.php
    Pages/EditUser.php
```

Directory plural, class singular. The table and the form live in their own
classes rather than as methods on the resource because both grow: a resource
whose `form()` is eighty lines of fields is a file where the navigation
configuration is impossible to find.

## The resource

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use App\Panels\Admin\Resources\Users\Forms\UserForm;
use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use App\Panels\Admin\Resources\Users\Tables\UsersTable;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'folder';

    protected static int $navigationSort = 0;

    public static function table(TableSchema $table): TableSchema
    {
        return UsersTable::configure($table);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return UserForm::configure($schema);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListUsers::class,
            'create' => CreateUser::class,
            'view' => ViewUser::class,
            'edit' => EditUser::class,
        ];
    }
}
```

The import block is assembled by the command rather than written into the stub,
sorted, because Pint sorts imports and generated code has to pass this project's
own Pint run — a test runs Pint over everything these generators write.

## The page keys are the routes

`pages()` keys are not labels — each one maps to a fixed set of routes:

| Key | Base class | Routes registered |
| --- | --- | --- |
| `index` | `PandaPanel\Resources\Pages\ListRecords` | `GET /` |
| `create` | `PandaPanel\Resources\Pages\CreateRecord` | `GET create`, `POST create`, `POST create/step` |
| `view` | `PandaPanel\Resources\Pages\ViewRecord` | `GET {record}` |
| `edit` | `PandaPanel\Resources\Pages\EditRecord` | `GET {record}/edit`, `PUT {record}/edit`, `POST {record}/edit/step` |

A generated page is four lines, because everything it does comes from the base
class and the resource:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Pages;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
```

## The table

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Tables;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;

final class UsersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(false),

                DateTimeColumn::make('created_at')
                    ->label('Created')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(UserResource::class),
                EditAction::make(UserResource::class),
                DeleteAction::make(UserResource::class),
            ])
            ->bulkActions([
                DeleteBulkAction::make(UserResource::class),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->emptyState(
                heading: 'No records yet',
                description: 'Records will appear here once they exist.',
            );
    }
}
```

Two columns, `id` and `created_at`, because those are the two every model has.
The command does not read the database: a generator that inspected the schema
would produce a table that is right today and silently wrong after the next
migration.

Which actions appear follows from which pages exist. `ViewAction` is generated
only when the view page is, `EditAction` only when the edit page is.

## The form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Forms;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

final class UserForm
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
                            ->maxLength(255),
                    ]),
            ]);
    }
}
```

One field, as a shape to copy. A model without a `name` column needs that line
changed before create or edit will save anything useful.

## `--simple`

```bash
php artisan make:panel-resource User --panel=Admin --simple
```

Generates `UserResource.php`, `Pages/ListUsers.php`, `Tables/UsersTable.php`
and `Forms/UserForm.php`, and nothing else.

```php
public static function pages(): array
{
    return [
        'index' => ListUsers::class,
    ];
}
```

The table it writes carries `DeleteAction` and `DeleteBulkAction` only, because
there is no create, view or edit page for anything else to point at. The form
class is still generated: a simple resource is the shape for modal-based
editing, and a modal action is given the same `FormSchema`.

## `--no-view`

```bash
php artisan make:panel-resource User --panel=Admin --no-view
```

Drops `Pages/ViewUser.php`, the `'view'` entry, and the `ViewAction` from the
table. The record's sub-navigation then has one destination, and one link is not
navigation, so the tabs disappear from the edit page as well.

There is no `--view`, because the view page is generated by default. A flag that
did nothing would read as supported.

## `--soft-deletes`

```bash
php artisan make:panel-resource User --panel=Admin --soft-deletes
```

One flag, three changes, and they only make sense together:

```php
final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'folder';

    protected static int $navigationSort = 0;

    protected static bool $softDeletes = true;
    // ...
}
```

```php
    ->filters([
        TrashedFilter::make('trashed'),
    ])
    ->recordActions([
        ViewAction::make(UserResource::class),
        EditAction::make(UserResource::class),
        DeleteAction::make(UserResource::class),
        RestoreAction::make(UserResource::class),
        ForceDeleteAction::make(UserResource::class),
    ])
    ->bulkActions([
        DeleteBulkAction::make(UserResource::class),
        RestoreBulkAction::make(UserResource::class),
        ForceDeleteBulkAction::make(UserResource::class),
    ])
```

`$softDeletes = true` is what lets a record page resolve a trashed record at
all. The `TrashedFilter` is what puts a deleted row on screen. The two actions
sit on that row. Generate any one of the three without the others and you get
buttons that can never be reached.

The flag is declared rather than inferred from the model, so a model that uses
`SoftDeletes` for something the panel should not expose does not silently grow a
restore button.

## `--model`

```bash
php artisan make:panel-resource Account --panel=Admin --model="App\Models\User"
```

```php
use App\Models\User;

final class AccountResource extends Resource
{
    protected static string $model = User::class;
```

The resource is named from the argument and the model from the option, which is
how one model gets two resources — a `UserResource` for staff and an
`AccountResource` for support, with different tables, forms and policies. A
leading backslash is stripped, so `\App\Models\User` and `App\Models\User` are
the same.

A resource's default slug comes from its **model** rather than its class name —
`Str::of(class_basename($model))->plural()->kebab()` — so both of those would
claim `users`. Two resources on one model in one panel therefore need one of
them to say which is which:

```php
protected static ?string $slug = 'accounts';
```

Without it the panel refuses to boot with
`PanelRegistrationException::duplicateResourceSlug()`.

## Custom stubs

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

Four stubs back this command, and a published copy wins over the package's:

| Stub | Written to | Placeholders |
| --- | --- | --- |
| `stubs/panel/resource.stub` | `{Plural}/{Class}Resource.php` | `panel`, `class`, `plural`, `model`, `modelBasename`, `imports`, `pageEntries`, `softDeletes` |
| `stubs/panel/resource-page.stub` | `{Plural}/Pages/{PageClass}.php` | `panel`, `class`, `plural`, `pageClass`, `base` |
| `stubs/panel/resource-table.stub` | `{Plural}/Tables/{Plural}Table.php` | `panel`, `class`, `plural`, `imports`, `filters`, `recordActions`, `bulkActions` |
| `stubs/panel/resource-form.stub` | `{Plural}/Forms/{Class}Form.php` | `panel`, `class`, `plural` |

A placeholder is written as `{{ name }}` in the stub, spaces included.

## Exit codes

| Outcome | Code |
| --- | --- |
| At least one file created | `0` |
| Every file already existed and was skipped | `1` |
| `--panel` missing | `1`, with `The --panel option is required.` |

A partially-skipped run still exits `0`: something was created, so the command
did work.

## Gotchas

- **Nothing is registered anywhere.** The resource is found by discovery, which
  means it must live under a path the panel's `discoverResources()` names. Move
  the directory elsewhere and it disappears without an error.
- **A cached manifest hides a new resource completely.** No route, no navigation
  entry, no error. Run `php artisan panel:clear` after generating, and read
  [Caching](../concepts/caching.md) before deploying.
- **The name is singularized before it is studly-cased.** `make:panel-resource
  Media --panel=Admin` produces `MediumResource` in `Media/`, because `Str::singular('Media')`
  is `Medium`. Pass the singular you want.
- **The generator does not read your database.** Two columns and one field are
  a starting point, not a scaffold of your schema.
- **The model is not verified to exist.** A typo in `--model` generates a class
  that references a missing model, and the failure arrives on the first request
  rather than from the command.
- **`--simple` still generates a form class.** It is not dead code — modal
  create and edit actions use it.
- **Policies are not generated.** Authorization goes through the model's policy;
  a resource with no policy behind it is governed by whatever your application's
  default gate does.
- **Relation managers are a separate command.** See
  [make:panel-relation-manager](make-panel-relation-manager.md).

## See also

- [make:panel](make-panel.md) — the panel a resource is generated into
- [make:panel-relation-manager](make-panel-relation-manager.md)
- [panel:clear](panel-clear.md), [panel:cache](panel-cache.md)
- [Creating resources](../resources/creating-resources.md)
- [Directory convention](../resources/directory-convention.md)
- [CRUD pages](../resources/crud-pages.md), [Resource pages](../resources/resource-pages.md)
- [Soft deletes](../resources/soft-deletes.md)
- [Tables overview](../tables/overview.md), [Filters](../tables/filters.md)
- [Forms overview](../forms/overview.md)
- [Actions overview](../actions/overview.md), [Restore and force delete](../actions/restore-force-delete.md)
- [Discovery](../concepts/discovery.md)
- [Publish tags](publish-tags.md)
