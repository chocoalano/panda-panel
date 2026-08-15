<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * The owner resource: two relation managers, plus a `hasOne` relation group
 * on its own form.
 */
final class ProjectRelationResource extends Resource
{
    protected static string $model = Project::class;

    protected static ?string $slug = 'projects';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
            Relationship::make('brief')->schema([
                TextInput::make('summary')->maxLength(255),
            ]),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListProjects::class,
            'view' => ViewProject::class,
            'edit' => EditProject::class,
            'tasks' => ManageProjectTasks::class,
        ];
    }

    /**
     * @return list<class-string<RelationManager>>
     */
    public static function relationManagers(): array
    {
        return [
            TasksRelationManager::class,
            LabelsRelationManager::class,
        ];
    }
}
