<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\DissociateAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

/**
 * A `hasMany` whose children soft delete: create, edit, delete, restore,
 * force delete, and dissociate all apply, and attach does not.
 */
final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    protected static ?string $icon = 'list';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
            ])
            ->filters([
                TrashedFilter::make('trashed'),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectRelationResource::class, self::class, $owner),
                DissociateAction::make(self::class, $owner),
                RestoreAction::make(self::class, $owner),
                ForceDeleteAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
            ])
            ->bulkActions([]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }
}
