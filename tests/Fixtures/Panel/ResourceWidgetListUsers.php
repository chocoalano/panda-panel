<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Resources\Pages\ListRecords;

final class ResourceWidgetListUsers extends ListRecords
{
    protected static string $resource = ResourceWidgetUserResource::class;
}
