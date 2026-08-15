<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\TableSchema;

/**
 * A resource with exactly one record, the shape application settings take.
 *
 * Backed by User only because it is the model this application has; what
 * matters is that its pages carry no `{record}`.
 */
final class SingularSettingsResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'app-settings';

    protected static bool $singular = true;

    public static function table(TableSchema $table): TableSchema
    {
        return $table;
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema;
    }

    /**
     * No index and no create: there is one record and it always exists.
     *
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return ['edit' => SingularSettingsEdit::class];
    }
}
