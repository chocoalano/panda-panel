<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ViewRecord;
use PandaPanel\Widgets\Widget;

final class WidgetedViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return list<class-string<Widget>>
     */
    public function footerWidgets(): array
    {
        return [ContextAwareStatsWidget::class];
    }
}
