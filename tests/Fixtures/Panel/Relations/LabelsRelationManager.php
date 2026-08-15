<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Relations\DetachAction;
use PandaPanel\Actions\Relations\DetachBulkAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A `belongsToMany` with a pivot column, so attach, detach, and pivot
 * attributes all have something to act on.
 */
final class LabelsRelationManager extends RelationManager
{
    protected static string $relationship = 'labels';

    protected static ?string $title = 'Labels';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                // Pivot columns read through the same dotted attribute path
                // any relation column uses.
                TextColumn::make('pivot.role')->label('Role'),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectRelationResource::class, self::class, $owner),
                DetachAction::make(self::class, $owner),
            ])
            ->bulkActions([
                DetachBulkAction::make(self::class, $owner),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    public static function pivotForm(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('role')->maxLength(50),
        ]);
    }
}
