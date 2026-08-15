<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A resource in a tenant-scoped panel that names *no* tenant relationship.
 *
 * Not an oversight — the case the opt-in exists for. A table every tenant
 * reads the same way, or a database-per-tenant arrangement where the
 * connection is already the boundary, has nothing to scope by, and a
 * framework that scoped it anyway would be guessing at a column.
 */
final class WorkspaceResource extends Resource
{
    protected static string $model = Workspace::class;

    protected static ?string $slug = 'workspaces';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('name')]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return ['index' => ListWorkspaces::class];
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('name')->required()]);
    }
}
