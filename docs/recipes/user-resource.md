# User Resource

The users resource that ships in [`examples/`](../../examples/), taken file by file. It is the largest worked example in the repository — a form that behaves differently on create and edit, eleven columns including an editable one and a bespoke Vue one, six kinds of filter, an infolist with tabs, an exporter, an importer, and a policy that lets a member edit exactly one account. Read this page when you want a real resource to copy from rather than a feature list. Every file named here is in the repository and the test suite runs against it.

## A minimal working example

The whole resource, if you were starting it today:

```bash
php artisan make:panel-resource User --panel=Admin --model=App\\Models\\User
```

That writes the resource, its four pages, a table, and a form. The Admin panel already calls `discoverResources(app_path('Panels/Admin/Resources'))`, so `/admin/users` answers as soon as `App\Policies\UserPolicy` allows it. Everything below is what the example then filled in.

## The files

```text
examples/app/Panels/Admin/Resources/Users/
├── UserResource.php
├── Forms/UserForm.php
├── Tables/UsersTable.php
├── Infolists/UserInfolist.php
├── Exports/UserExporter.php
├── Imports/UserImporter.php
└── Pages/
    ├── ListUsers.php
    ├── CreateUser.php
    ├── ViewUser.php
    └── EditUser.php

examples/app/Policies/UserPolicy.php
examples/resources/js/pages/Panels/Admin/Columns/AccountAge.vue
```

## The resource

`examples/app/Panels/Admin/Resources/Users/UserResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use App\Models\User;
use App\Panels\Admin\Resources\Users\Forms\UserForm;
use App\Panels\Admin\Resources\Users\Infolists\UserInfolist;
use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use App\Panels\Admin\Resources\Users\Tables\UsersTable;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $navigationIcon = 'users';

    protected static string|BackedEnum|null $navigationGroup = 'User Management';

    protected static int $navigationSort = 10;

    /** @var list<string> */
    protected static array $globalSearchAttributes = ['name', 'email'];

    /** @var list<string> */
    protected static array $with = ['passkeys'];

    /**
     * @return array<string, string>
     */
    public static function globalSearchResultDetails(Model $record): array
    {
        return [
            'Email' => (string) $record->getAttribute('email'),
            'Role' => $record instanceof User && $record->is_admin ? 'Administrator' : 'Member',
        ];
    }

    public static function table(TableSchema $table): TableSchema
    {
        return UsersTable::configure($table);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(InfolistSchema $schema): InfolistSchema
    {
        return UserInfolist::configure($schema);
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

The class holds no schema of its own. Three delegations and a page map, which is what keeps a resource readable once its table is three hundred lines.

`$with = ['passkeys']` is not decoration. One column renders passkey names and another counts them; without the eager load that is a query per row, and `Model::shouldBeStrict()` — on outside production in the examples — turns it into a loud failure rather than a quietly slow page.

`$globalSearchAttributes` is the whole opt-in for global search. Empty means the resource is not searchable; naming attributes makes it so, and `globalSearchResultDetails()` is what turns a result from a name into something a person can pick between.

## The form

`Forms/UserForm.php`. The interesting part is that one class produces two different forms.

```php
public static function configure(FormSchema $schema): FormSchema
{
    $isCreate = $schema->getPage() === 'create';

    return $schema
        ->columns(2)
        ->schema([
            Section::make('Personal Information')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Ada Lovelace'),

                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->rulesUsing(static fn (?Model $record): array => [
                            $record === null
                                ? Rule::unique('users', 'email')
                                : Rule::unique('users', 'email')->ignore($record->getKey()),
                        ]),
                    // …
                ]),
            // …
        ]);
}
```

`FormSchema::getPage()` returns `create`, `edit`, or `view` — the same page name `hiddenOn()` and `visibleOn()` are compared against. Branching on it here rather than writing two form classes keeps one description of what a user *is*.

### A password that is required once and optional forever after

```php
PasswordInput::make('password')
    ->confirmed()
    ->rules(['min:8'])
    ->columnSpan(2)
    ->when(
        $isCreate,
        static fn (PasswordInput $field): PasswordInput => $field->required(),
        static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
    );
```

`when()` comes from Laravel's `Conditionable`, which `FormComponent` uses, so it is available on every field and layout.

```php
public function optionalWhenFilled(): self
{
    return $this
        ->required(false)
        ->dehydrateWhen(static fn (mixed $value): bool => is_string($value) && $value !== '');
}
```

That is the whole reason `dehydrateWhen()` exists. On edit the field must be optional, still validated when the user types something, and **dropped entirely** when they leave it blank — otherwise the stored hash is overwritten with an empty string.

`confirmed()` adds Laravel's `confirmed` rule, which expects a `password_confirmation` field alongside it.

### A toggle over a timestamp

The users table has no `status` column, so the honest thing to toggle is the verification timestamp — and a boolean control over a nullable datetime needs all three state hooks:

```php
Toggle::make('verified')
    ->label('Email verified')
    ->helperText('Marks the address as verified without sending an email.')
    ->columnSpan(2)
    ->formatUsing(static fn (mixed $value, ?Model $record): bool => $record instanceof User
        && $record->email_verified_at !== null)
    ->dehydrateTo('email_verified_at')
    ->mutateUsing(static function (mixed $value, ?Model $record): mixed {
        if ($value !== true) {
            return null;
        }

        return $record instanceof User && $record->email_verified_at !== null
            ? $record->email_verified_at
            : Date::now();
    });
```

| Method | Signature | What it does here |
| --- | --- | --- |
| `formatUsing()` | `formatUsing(Closure $callback): static` | Turns the stored timestamp into the boolean the control shows |
| `dehydrateTo()` | `dehydrateTo(string $attribute): static` | Writes to `email_verified_at` although the field is called `verified` |
| `mutateUsing()` | `mutateUsing(Closure $callback): static` | Turns the boolean back into a timestamp, keeping an existing one |

Keeping the existing timestamp matters: without it, every save of an already-verified account would move the verification date to now.

## The table

`Tables/UsersTable.php` is the longest file in the examples. Taken a piece at a time.

### The schema

```php
return $table
    ->columns(self::columns())
    ->filters(self::filters())
    ->groups([/* … */])
    ->headerActions(self::headerActions())
    ->toolbarActions([/* … */])
    ->recordActions([/* … */])
    ->recordActionsPosition(RecordActionsPosition::AfterColumns)
    ->recordActionsLabel('Manage')
    ->bulkActions([/* … */])
    ->defaultSort('created_at', SortDirection::Descending)
    ->defaultSortOptionLabel('newest first')
    ->searchPlaceholder('Search by name, email, or passkey...')
    ->searchDebounce(400)
    ->splitSearchTerms()
    ->persistSearchInSession()
    ->persistSortInSession()
    ->persistFiltersInSession()
    ->persistColumnsInSession()
    ->deferFilters()
    ->filtersApplyLabel('Apply filters')
    ->filtersResetLabel('Clear all')
    ->reorderableColumns()
    ->columnManagerInModal()
    ->columnManagerTrigger('Columns', 'settings')
    ->emptyStateActions(self::headerActions())
    ->emptyState(
        heading: 'No users match this view',
        description: 'Adjust the search or filters, or add a new user.',
        icon: 'users',
    );
```

Four of those are decisions rather than defaults:

`splitSearchTerms()` narrows on each word separately, so "ada admin" finds the administrator called Ada rather than looking for that exact string.

`deferFilters()` waits for an explicit apply. The table carries a query builder that can take several rules; running the table on every keystroke of a half-built condition is wasted work.

`emptyStateActions(self::headerActions())` reuses the header's actions. An empty table is where "add a user" is most useful and hardest to find.

`persist*InSession()` remembers a view per user, keyed by panel and resource. The URL still wins when it says something.

### Columns

Eleven of them, and each is a different thing a column can be.

```php
// A picture, never toggled off.
ImageColumn::make('avatar')
    ->label('')
    ->circular()
    ->width('3.5rem')
    ->toggleable(false),

// Editable in place. `individually: true` adds a per-column search box.
TextInputColumn::make('name')
    ->maxLength(255)
    ->rules(['required'])
    ->searchable(individually: true)
    ->sortable()
    ->width('14rem')
    ->toggleable(false)
    ->tooltip(static fn (Model $record): string => 'Joined '
        .self::asDate($record->getAttribute('created_at'))?->diffForHumans()),

// A link out of the table, plus a data attribute the frontend can hook.
TextColumn::make('email')
    ->searchable(individually: true)
    ->sortable()
    ->headerTooltip('The address sign-in and notifications use')
    ->url(static fn (Model $record): string => 'mailto:'.$record->getAttribute('email'))
    ->extraAttributes(static fn (Model $record): array => [
        'data-user' => (string) $record->getKey(),
    ]),
```

A badge that is also a button — clicking it verifies the account, which is the thing an administrator actually wants to do from that column:

```php
BadgeColumn::make('email_verified_at')
    ->label('Status')
    ->formatUsing(static fn (mixed $value): string => $value === null ? 'unverified' : 'verified')
    ->labels(['verified' => 'Verified', 'unverified' => 'Unverified'])
    ->colors([
        'verified' => BadgeColor::Success,
        'unverified' => BadgeColor::Warning,
    ])
    ->sortable()
    ->action(
        Action::make('verifyOne')
            ->label('Mark as verified')
            ->successMessage('Account marked as verified.')
            ->visible(static fn (?Model $record): bool => $record !== null
                && $record->getAttribute('email_verified_at') === null)
            ->authorize(static fn (?Model $record): bool => self::actorIsAdmin())
            ->action(static function (Model $record): void {
                $record->forceFill(['email_verified_at' => now()])->save();
            }),
    ),
```

`visible()` decides whether the button is drawn; `authorize()` decides whether it may run, and is asked again by the endpoint when it does. The first is a convenience, the second is the control.

A toggle with a guard the policy cannot express:

```php
ToggleColumn::make('is_admin')
    ->label('Admin')
    ->alignment(Alignment::Center)
    ->width('5rem')
    ->disabledUsing(static function (Model $record): bool {
        $actor = auth()->user();

        return ! self::actorIsAdmin()
            || ($actor instanceof User && $actor->is($record));
    }),
```

The policy lets a user edit their own record, which is right for a display name and catastrophic for a privilege flag. `disabledUsing()` is the missing rule, and `/admin/actions/cell` re-checks the same thing — `tests/Feature/Panel/Negative/PrivilegeEscalationTest.php` asserts that a hand-written POST cannot toggle it.

An aggregate over a relation, with summaries under the column:

```php
NumberColumn::make('passkeys_count')
    ->label('Passkeys')
    ->counts('passkeys')
    ->sortable()
    ->alignment(Alignment::End)
    ->width('6rem')
    ->summarize([Sum::make()->label('Total'), Count::make()->label('Accounts')]),
```

`counts()` adds a `withCount`, so the alias is produced by the database rather than by hydrating a relation. The summaries are computed over the whole filtered query, not the page on screen — `UsersTableAppliedTest` asserts exactly that against 31 accounts and a page of ten.

A sort the schema cannot express as a column name:

```php
TextColumn::make('attention')
    ->label('Needs attention')
    ->visible(false)
    ->alignment(Alignment::Center)
    ->formatUsing(static fn (mixed $value, Model $record): string => $record->getAttribute('email_verified_at') === null
        ? 'Unverified'
        : '—')
    ->sortUsing(static function (Builder $query, SortDirection $direction): void {
        $query
            ->orderByRaw('email_verified_at is null '.($direction === SortDirection::Ascending ? 'asc' : 'desc'))
            ->orderBy('created_at');
    }),
```

`sortUsing()` receives a direction that has already been validated, so the only thing reaching the builder is `asc` or `desc`.

And a column drawn by a Vue component of the application's own:

```php
CustomColumn::make('accountAge')
    ->label('Account age')
    ->component('Panels/Admin/Columns/AccountAge')
    ->width('9rem')
    ->state(static function (Model $record): array {
        $created = self::asDate($record->getAttribute('created_at'));

        return [
            'days' => $created === null ? 0 : (int) $created->diffInDays(now()),
            'label' => $created?->diffForHumans() ?? 'Unknown',
        ];
    }),
```

The component name is a build-time registry key, never a path and never anything a request could supply. `examples/resources/js/pages/Panels/Admin/Columns/AccountAge.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ state: unknown }>();

/** A year of membership fills the bar; past that it simply stays full. */
const FULL_AT_DAYS = 365;

const reading = computed(() => {
    const value = props.state;

    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const { days, label } = value as { days?: unknown; label?: unknown };

    if (typeof days !== 'number' || typeof label !== 'string') {
        return null;
    }

    return { days, label };
});

const percent = computed(() =>
    reading.value === null
        ? 0
        : Math.min(100, Math.round((reading.value.days / FULL_AT_DAYS) * 100)),
);
</script>

<template>
    <div v-if="reading" class="flex flex-col gap-1">
        <span class="text-xs whitespace-nowrap text-muted-foreground">
            {{ reading.label }}
        </span>
        <div
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="img"
            :aria-label="`Account age: ${reading.label}`"
        >
            <div
                class="h-full rounded-full bg-primary"
                :style="{ width: `${percent}%` }"
            />
        </div>
    </div>
    <span v-else class="text-muted-foreground">—</span>
</template>
```

The state arrives as untyped JSON and is narrowed rather than asserted: a shape that does not match renders an empty cell instead of throwing inside the table.

### Filters

Six, covering every filter the framework has except the boolean one.

```php
TernaryFilter::make('verified')
    ->label('Email verification')
    ->column('email_verified_at')
    ->nullable()                       // set versus not set, rather than 1 versus 0
    ->labels('Verified', 'Unverified', 'Anyone'),

TernaryFilter::make('is_admin')
    ->label('Role')
    ->labels('Administrators', 'Members', 'Anyone'),

DateFilter::make('registered')
    ->label('Registered between')
    ->column('created_at'),

// More than one answer, so it is a form rather than a control.
FormFilter::make('passkeyActivity')
    ->label('Passkey activity')
    ->form(static fn (FormSchema $schema): FormSchema => $schema->schema([
        Select::make('has')
            ->label('Passkeys')
            ->options(['yes' => 'Has passkeys', 'no' => 'None registered']),
        DatePicker::make('usedSince')->label('Used since'),
    ]))
    ->query(static function (Builder $query, mixed $data): void {
        // …
    }),

QueryBuilderFilter::make('conditions')
    ->label('Advanced')
    ->maxRules(5)
    ->constraints([
        TextConstraint::make('name'),
        TextConstraint::make('email'),
        DateConstraint::make('created_at')->label('Registered'),
        DateConstraint::make('email_verified_at')->label('Verified at'),
        BooleanConstraint::make('is_admin')->label('Administrator'),
    ]),
```

Every one of these is a whitelist. A filter name the schema never declared does not exist, a `QueryBuilderFilter` rule naming a constraint that was never offered is refused, and a ternary value outside the three it accepts leaves the query alone. `tests/Feature/Panel/Negative/HostileTableInputTest.php` states each of those as something that must not happen.

### Actions

Header, and reused as the empty state:

```php
private static function headerActions(): array
{
    return [
        // The full form in a dialog, so adding one user does not cost a page.
        CreateAction::modal(UserResource::class)->label('New user'),
        ImportAction::make(UserImporter::class, UserResource::class),
        ExportAction::make(UserExporter::class, UserResource::class),
    ];
}
```

`CreateAction::make()` links to the create page; `CreateAction::modal()` opens the same form in a dialog. Both exist and the resource keeps its create page either way.

Row actions, including a replicate that refuses to duplicate the two attributes that must not be copied:

```php
->recordActions([
    ViewAction::make(UserResource::class)->icon('info'),
    EditAction::make(UserResource::class)->icon('pencil'),
    ReplicateAction::make(
        UserResource::class,
        except: ['email', 'email_verified_at'],
        using: static function (Model $copy, Model $original): void {
            $copy->forceFill([
                'name' => $original->getAttribute('name').' (copy)',
                'email' => 'copy-'.Str::random(8).'@example.test',
            ]);
        },
    ),
    DeleteAction::make(UserResource::class)->icon('trash'),
])
```

```php
public static function make(
    string $resource,
    array $except = [],
    ?Closure $using = null,
): Action
```

An email is unique, and a duplicated verification would mark an account confirmed that never confirmed anything.

A bulk action that authorizes every record before writing any:

```php
Action::make('verify')
    ->label('Mark as verified')
    ->icon('check')
    ->variant(ActionVariant::Outline)
    ->authorizeEachUsing(static fn (Model $record): bool => self::actorIsAdmin())
    ->authorize(static fn (?Model $record): bool => self::actorIsAdmin())
    ->successMessageUsing(static fn (int $count): string => $count === 1
        ? '1 account marked as verified.'
        : "{$count} accounts marked as verified.")
    ->bulkAction(static function (Collection $records): void {
        $records
            ->filter(static fn (Model $record): bool => $record->getAttribute('email_verified_at') === null)
            ->each(static fn (Model $record) => $record
                ->forceFill(['email_verified_at' => now()])
                ->save());
    }),
```

`authorize()` is the gate on the action as a whole; `authorizeEachUsing()` is asked for every selected record **before any of them is written**, so a selection containing one refused account changes nothing.

And a toolbar action that acts on the table rather than on a selection:

```php
Action::make('purgeUnverified')
    ->label('Purge unverified')
    ->icon('trash-2')
    ->variant(ActionVariant::Ghost)
    ->requiresConfirmation(
        heading: 'Delete every unverified account?',
        description: 'Accounts that never confirmed their email address are removed. This cannot be undone.',
        button: 'Delete them',
    )
    ->authorize(static fn (): bool => self::actorIsAdmin())
    ->successMessageUsing(static fn (int $count): string => 'Unverified accounts removed.')
    ->tableAction(static function (): void {
        User::query()
            ->whereNull('email_verified_at')
            ->whereKeyNot(auth()->id())
            ->delete();
    }),
```

`tableAction()` is what makes an action executable without a record. `recordAction`/`bulkAction`/`tableAction` are three different whitelists — an action declared as one does not exist as another, however the request spells it.

## The infolist

`Infolists/UserInfolist.php` is what the view page renders. Its first job is negative: the password is **absent** rather than masked. An infolist that never reads it cannot leak it, which a form-derived view could only promise by filtering.

```php
return $schema
    ->columns(2)
    ->actions([/* an action on the record page */])
    ->schema([
        Tabs::make([
            Tab::make('Account')->icon('user')->columns(2)->schema([
                Section::make('Identity')
                    ->columns(2)
                    ->headerActions([/* resend verification */])
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email'),
                        BadgeEntry::make('role')
                            ->label('Role')
                            ->formatUsing(static fn (mixed $value, Model $record): string => $record instanceof User && $record->is_admin
                                ? 'Administrator'
                                : 'Member')
                            ->colors(['Administrator' => BadgeColor::Info]),
                        BooleanEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->labels('Verified', 'Unverified'),
                    ]),
                // …
            ]),

            Tab::make('Security')->icon('shield')->schema([
                Section::make('Passkeys')
                    ->description('Devices this account can sign in with.')
                    ->schema([
                        RepeatableEntry::make('passkeys')
                            ->label('Registered')
                            ->itemLabel('Passkey')
                            ->placeholder('No passkeys registered.')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextEntry::make('name'),
                                    DateTimeEntry::make('created_at')->label('Added'),
                                    DateTimeEntry::make('last_used_at')
                                        ->label('Last used')
                                        ->since()
                                        ->placeholder('Never'),
                                ]),
                            ]),
                    ]),
                // …
            ]),
        ])->persistTab(),
    ]);
```

`RepeatableEntry` draws one copy of its schema per related record. `persistTab()` keeps the open tab in the URL, so a link to a user's security tab is a link somebody can send.

An action declared on the infolist is looked up in the infolist schema, not the table's — `/admin/actions/infolist` is a separate endpoint with a separate whitelist, because an action shown on one page must not be runnable from the other:

```php
Action::make('note')
    ->label('Add a note')
    ->icon('pencil')
    ->variant(ActionVariant::Outline)
    ->modalHeading('Note about this account')
    ->modalSubmitLabel('Save note')
    ->modalWidth(ModalWidth::Large)
    ->slideOver()
    ->successMessage('Note saved.')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('note')->label('Note')->rows(6)->required()->maxLength(1000),
    ]))
    ->authorize(static fn (?Model $record): bool => $record !== null && self::actorIsAdmin())
    ->action(static function (Model $record, array $data): void {
        logger()->info('Panel note', [
            'user' => $record->getKey(),
            'note' => $data['note'] ?? '',
            'by' => auth()->id(),
        ]);
    }),
```

`schema()` gives the action a form; its values arrive as the second argument to `action()`, already validated by that schema and narrowed to the fields it declared.

## The pages

Four classes, one line each:

```php
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
```

`CreateUser`, `ViewUser`, and `EditUser` extend `CreateRecord`, `ViewRecord`, and `EditRecord`. They are where lifecycle hooks and per-page header actions would go; the example needs neither, so they stay as the generator wrote them.

## The policy

`examples/app/Policies/UserPolicy.php` is an ordinary Laravel policy. Nothing in it knows a panel exists, which is the point — the same policy governs a console command, an API controller, and the panel alike.

```php
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, User $record): bool
    {
        return $user->is_admin || $user->is($record);
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, User $record): bool
    {
        return $user->is_admin || $user->is($record);
    }

    public function delete(User $user, User $record): bool
    {
        return $user->is_admin && ! $user->is($record);
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, User $record): bool
    {
        return $user->is_admin;
    }

    public function restoreAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, User $record): bool
    {
        return $user->is_admin && ! $user->is($record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->is_admin;
    }
}
```

Two rules are worth naming.

A member may read and edit their own record and no other. "Not mine" is a **403** rather than a hidden row, so a guessed URL is refused by the same rule that hides the link.

An administrator may not delete their own account through the panel. Locking the last administrator out is the kind of mistake nobody makes twice, and the policy is where it is cheapest to prevent.

## The model

`examples/app/Models/User.php` shows the three things a panel asks of a user model, and one thing it does not:

```php
class User extends Authenticatable implements MustVerifyEmail, PanelNotifiable, PanelUser, PasskeyUser
{
    use HasFactory;
    use Notifiable;                  // the notification centre
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;    // the security settings page

    /**
     * `is_admin` is deliberately absent: registration and profile updates
     * both fill from request input, and a privilege flag that is
     * mass-assignable is a privilege anyone can grant themselves.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * A rule about the account, asked on every panel request alongside the
     * panel's own predicate. Both must agree.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasVerifiedEmail();
    }
}
```

`PandaPanel\Contracts\PanelUser` declares one method and applies to every panel at once. The Admin panel's `canAccess()` closure is the rule about *that panel*. Neither can overrule the other; both must say yes.

## Export and import

`Exports/UserExporter.php` and `Imports/UserImporter.php` are wired into the table's header actions and the bulk actions. The exporter has no `password` column and never will; the importer has no password column either, and gives a new account a random one so it goes through the reset flow like anybody else. Both are taken apart in [Import and Export](import-export.md).

## The tests

| File | Asserts |
| --- | --- |
| `tests/Feature/Panel/AdminPanelExampleTest.php` | The dashboard renders, navigation is built from the discovered classes in group order, and the whole user lifecycle works for an administrator |
| `tests/Feature/Panel/UsersTableAppliedTest.php` | Eleven columns, summaries over the whole query rather than the page, and grouped-filtered-searched-sorted all at once |
| `tests/Feature/Panel/Negative/PrivilegeEscalationTest.php` | No guessed URL, hand-written POST, or swapped id gets past the policy |
| `tests/Feature/Panel/ImportExportTest.php` | The exporter never offers a password, and a re-uploaded row updates rather than duplicating |

A representative slice:

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($this->admin);
});

it('shows the resource, its form and its actions to an administrator', function (): void {
    panelTable(UserResource::class)->assertCanSeeRecord($this->admin)->assertCount(1);
    panelForm(UserResource::class)->assertFieldIsRequired('name');
    panelRecordActions(UserResource::class)->assertExists('edit');
});

it('makes the password required on create and optional on edit', function (): void {
    panelForm(UserResource::class, 'create')->assertFieldIsRequired('password');

    panelForm(UserResource::class, 'edit')
        // A blank password is dropped rather than written, so the stored
        // hash is not overwritten with an empty string.
        ->assertDehydratesTo(['name' => 'Ada', 'password' => ''], ['name' => 'Ada']);
});

it('refuses a member a record that is not their own', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($member)->get("/admin/users/{$other->id}")->assertForbidden();
    $this->actingAs($member)->get("/admin/users/{$member->id}")->assertOk();
});

it('does not let an administrator toggle the admin flag on their own account', function (): void {
    $this->post('/admin/actions/cell', [
        'resource' => 'users',
        'column' => 'is_admin',
        'record' => $this->admin->id,
        'value' => false,
    ]);

    expect($this->admin->fresh()->is_admin)->toBeTrue();
});
```

```bash
php artisan test --compact --filter=UsersTableApplied
php artisan test --compact --filter=AdminPanelExample
```

## Gotchas

- **`$with` is load-bearing here.** Remove `['passkeys']` and the passkey-name column lazy loads per row; with `Model::shouldBeStrict()` on, that is an exception rather than a slow page.
- **`ToggleColumn` and `TextInputColumn` write through their own endpoint.** `/admin/actions/cell` re-checks `update` on that record and re-applies `disabledUsing()`; the rendered control is never the control.
- **`CustomColumn` needs a build.** The component registry is an `import.meta.glob` evaluated at build time, so a new `.vue` file is invisible until Vite has seen it.
- **`getPage()` is `create`, `edit`, or `view`.** Comparing it to anything else silently produces the edit branch.
- **`dehydrateTo()` changes the attribute, not the field name.** Validation messages and conditions still use `verified`; only the write goes to `email_verified_at`.
- **An infolist action is not a table action.** They are separate endpoints with separate whitelists; moving one between them changes which endpoint can run it.
- **The example user model keeps `is_admin` out of `$fillable`.** Promoting an account is an explicit `forceFill` or a dedicated action, never a side effect of saving a form.

## See also

- [Admin Panel Example](admin-panel.md) — the panel that discovers this resource
- [Product Resource](product-resource.md) — the same path, built from nothing
- [Import and Export](import-export.md) — the exporter and importer this resource wires up
- [Custom Field](custom-field.md) — the same seam as `AccountAge.vue`, on a form
- [Creating Resources](../resources/creating-resources.md)
- [Tables Overview](../tables/overview.md), [Columns](../tables/columns.md), [Filters](../tables/filters.md)
- [Editable Columns](../tables/editable-columns.md)
- [Forms Overview](../forms/overview.md), [State Lifecycle](../forms/state-lifecycle.md)
- [Infolists Overview](../infolists/overview.md)
- [Actions Overview](../actions/overview.md), [Bulk Actions](../actions/bulk-actions.md)
- [Resource Authorization](../resources/authorization.md)
- [Custom Columns](../frontend/custom-columns.md)
- [Negative Security Tests](../testing/negative-security-tests.md)
