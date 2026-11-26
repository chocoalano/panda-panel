<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;

/**
 * A resource that tours its index and its create form, and nothing else.
 */
final class TouredUserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'toured-users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function tour(string $page): ?Tour
    {
        return match ($page) {
            'index' => Tour::make()->steps([TourStep::make('The list')->column('name')]),
            'create' => Tour::make('new-user')->steps([TourStep::make('The name')->field('name')]),
            default => null,
        };
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('name')->required()]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => TouredListUsers::class,
            'create' => TouredCreateUser::class,
            'view' => TouredViewUser::class,
            'edit' => TouredEditUser::class,
        ];
    }
}
