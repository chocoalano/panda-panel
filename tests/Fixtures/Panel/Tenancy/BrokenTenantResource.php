<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * Names a relationship the model does not have, which must fail loudly rather
 * than quietly running unscoped.
 */
final class BrokenTenantResource extends Resource
{
    protected static string $model = Document::class;

    protected static ?string $slug = 'broken-documents';

    protected static ?string $tenantRelationship = 'nothing_like_this';

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([TextColumn::make('title')]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return ['index' => ListBrokenTenants::class];
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([TextInput::make('title')->required()]);
    }
}
