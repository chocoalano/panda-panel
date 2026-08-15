<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Resources\Pages\ListRecords;

final class ListNestedTasks extends ListRecords
{
    protected static string $resource = NestedTaskResource::class;
}
