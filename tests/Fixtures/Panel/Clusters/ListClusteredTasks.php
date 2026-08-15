<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Clusters;

use PandaPanel\Resources\Pages\ListRecords;

final class ListClusteredTasks extends ListRecords
{
    protected static string $resource = ClusteredTaskResource::class;
}
