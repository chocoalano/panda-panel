<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Resources\Pages\ListRecords;

final class ListTrashableTasks extends ListRecords
{
    protected static string $resource = TaskSoftDeleteResource::class;
}
