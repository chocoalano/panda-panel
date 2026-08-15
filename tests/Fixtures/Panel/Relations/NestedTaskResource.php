<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A resource that only exists beneath a project.
 *
 * It declares no scope of its own: `Resource::query()` reads the bound parent
 * and starts from its relation, which is what a test can prove by asking for
 * a task belonging to another project and getting a 404.
 */
class NestedTaskResource extends Resource
{
    protected static string $model = Task::class;

    /**
     * Distinct from the relation page's own `projects/{record}/tasks`: two
     * resources cannot claim one path, and whichever registered first would
     * silently shadow the other.
     */
    protected static ?string $slug = 'nested-tasks';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $parentResource = ProjectRelationResource::class;

    protected static ?string $parentRelationship = 'tasks';

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
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListNestedTasks::class,
            'edit' => EditNestedTask::class,
        ];
    }
}
