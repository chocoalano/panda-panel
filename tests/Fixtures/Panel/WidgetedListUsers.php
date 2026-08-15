<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Widgets\Widget;

final class WidgetedListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * @return list<class-string<Widget>>
     */
    public function headerWidgets(): array
    {
        return [ContextAwareStatsWidget::class];
    }
}
