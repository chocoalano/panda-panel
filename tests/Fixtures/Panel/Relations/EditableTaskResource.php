<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\SelectColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\TextInputColumn;
use PandaPanel\Tables\Columns\ToggleColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A table whose cells can be written through, including one column that is
 * read-only and one record for which every cell is locked.
 */
final class EditableTaskResource extends Resource
{
    protected static string $model = Task::class;

    protected static ?string $slug = 'editable-tasks';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            // Declared and shown, but not editable.
            TextColumn::make('id'),

            TextInputColumn::make('name')
                ->maxLength(255)
                ->disabledUsing(
                    static fn (Model $record): bool => $record->getAttribute('name') === 'locked',
                ),

            SelectColumn::make('status')->options([
                'open' => 'Open',
                'done' => 'Done',
            ]),

            ToggleColumn::make('is_pinned'),
        ]);
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
        return [];
    }
}
