<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\Widget;

final class ResourceWidgetUserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $slug = 'widget-users';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @return list<class-string<Widget>>
     */
    public static function getWidgets(): array
    {
        return [ContextAwareStatsWidget::class];
    }

    /**
     * @return list<class-string<Widget>>
     */
    public static function getHeaderWidgets(string $page): array
    {
        return match ($page) {
            'view' => [ContextAwareStatsWidget::class],
            default => parent::getHeaderWidgets($page),
        };
    }

    /**
     * @return list<class-string<Widget>>
     */
    public static function getFooterWidgets(string $page): array
    {
        return $page === 'index' ? [FilteredStatsWidget::class] : [];
    }

    public static function table(TableSchema $table): TableSchema
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('email'),
        ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required(),
            TextInput::make('email')->required(),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ResourceWidgetListUsers::class,
            'view' => ResourceWidgetViewUser::class,
        ];
    }
}
