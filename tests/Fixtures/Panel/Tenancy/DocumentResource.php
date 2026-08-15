<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A resource that names the relationship leading to the tenant, which is the
 * whole of the opt-in.
 */
final class DocumentResource extends Resource
{
    protected static string $model = Document::class;

    protected static ?string $slug = 'documents';

    protected static ?string $tenantRelationship = 'workspace';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('title')]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return ['index' => ListDocuments::class];
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('title')->required()]);
    }
}
