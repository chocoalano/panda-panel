<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A resource whose table can be dragged into order, backed by a model with a
 * real order column.
 */
final class ReorderableUsersResource extends Resource
{
    protected static string $model = Orderable::class;

    protected static ?string $slug = 'ordered-users';

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->reorderable('position');
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema;
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [];
    }
}
