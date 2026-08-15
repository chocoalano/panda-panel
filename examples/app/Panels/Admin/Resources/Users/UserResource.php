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

    /**
     * The passkey names column reads this relation, so it is eager loaded
     * rather than lazy loading once per row. `Model::shouldBeStrict()` is on
     * outside production, so forgetting it fails loudly rather than quietly
     * costing a query per record.
     *
     * @var list<string>
     */
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
