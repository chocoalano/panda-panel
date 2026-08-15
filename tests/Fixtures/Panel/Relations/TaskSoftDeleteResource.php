<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\ForceDeleteAction;
use PandaPanel\Actions\ForceDeleteBulkAction;
use PandaPanel\Actions\RestoreAction;
use PandaPanel\Actions\RestoreBulkAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

/**
 * A resource whose records soft delete, wired the way the generator wires
 * one: the declaration, the filter that reveals a trashed record, and the
 * actions that act on it. All three are needed — any one alone is inert.
 */
final class TaskSoftDeleteResource extends Resource
{
    protected static string $model = Task::class;

    protected static ?string $slug = 'trashable-tasks';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('name')->searchable()->sortable()])
            ->filters([TrashedFilter::make('trashed')])
            ->recordActions([
                DeleteAction::make(self::class),
                RestoreAction::make(self::class),
                ForceDeleteAction::make(self::class),
            ])
            ->bulkActions([
                DeleteBulkAction::make(self::class),
                RestoreBulkAction::make(self::class),
                ForceDeleteBulkAction::make(self::class),
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
        return [
            'index' => ListTrashableTasks::class,
            'view' => ViewTrashableTask::class,
            'edit' => EditTrashableTask::class,
        ];
    }
}
