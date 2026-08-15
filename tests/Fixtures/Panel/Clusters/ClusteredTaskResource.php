<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Clusters;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Project;

/**
 * A resource inside a cluster, for the path prefix and the sub-navigation.
 */
final class ClusteredTaskResource extends Resource
{
    protected static string $model = Project::class;

    protected static ?string $slug = 'clustered-tasks';

    protected static ?string $navigationLabel = 'Tasks';

    protected static ?string $navigationIcon = 'link';

    protected static ?string $activeNavigationIcon = 'check';

    protected static int $navigationSort = 10;

    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = OperationsCluster::class;

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
        return ['index' => ListClusteredTasks::class];
    }
}
