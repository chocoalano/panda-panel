<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Resources\Pages\ViewRecord;

final class ResourceWidgetViewUser extends ViewRecord
{
    protected static string $resource = ResourceWidgetUserResource::class;
}
