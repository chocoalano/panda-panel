<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

/**
 * A real resource whose model has no policy.
 *
 * Leniently this is indistinguishable from a policy that refuses; under
 * `strictAuthorization()` it must say so.
 */
final class UnpolicedFixtureResource extends Resource
{
    protected static string $model = UnpolicedModel::class;

    protected static ?string $slug = 'unpoliced';

    public static function table(TableSchema $table): TableSchema
    {
        return $table;
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema;
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [];
    }
}
