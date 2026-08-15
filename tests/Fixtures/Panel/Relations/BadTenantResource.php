<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

/**
 * Scopes by a method that exists on the model and is not a relationship.
 *
 * `getTable()` is the least contrived example there is: every model has it,
 * it returns a string, and pointing `$tenantRelationship` at something like it
 * is exactly the mistake — a scope, an accessor, a helper.
 */
final class BadTenantResource extends Resource
{
    protected static string $model = Project::class;

    protected static ?string $tenantRelationship = 'getTable';

    public static function table(TableSchema $table): TableSchema
    {
        return $table;
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema;
    }

    /** @return array<string, class-string> */
    public static function pages(): array
    {
        return [];
    }
}
