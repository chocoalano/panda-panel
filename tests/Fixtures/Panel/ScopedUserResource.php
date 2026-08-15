<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\TextInputColumn;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Scoped\EditScopedUser;
use Tests\Fixtures\Panel\Scoped\ListScopedUsers;
use Tests\Fixtures\Panel\Scoped\ViewScopedUser;

/**
 * A resource that narrows its own query, and nothing else.
 *
 * Every route to a record is supposed to go through `query()` — the list, the
 * view page, the edit page, the action endpoint, the bulk endpoint, the
 * editable cell. One that did not would undo the point of a scope: the list
 * shows what you are allowed to see, and a guessed id shows what you are not.
 *
 * The narrowing here stands in for a tenant, a team, or a workspace. There is
 * deliberately no policy involved: a record reachable through this resource
 * would be a scope failing, not a permission.
 */
final class ScopedUserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'scoped-users';

    protected static ?string $recordTitleAttribute = 'name';

    /** The palette reads through `globalSearchQuery()`, which starts at `query()`. */
    protected static array $globalSearchAttributes = ['name', 'email'];

    public static function query(): Builder
    {
        return parent::query()->where('is_admin', false);
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('id'),
            TextInputColumn::make('name')->maxLength(255),
            TextColumn::make('email'),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required(),
            TextInput::make('email')->required(),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListScopedUsers::class,
            'view' => ViewScopedUser::class,
            'edit' => EditScopedUser::class,
        ];
    }
}
