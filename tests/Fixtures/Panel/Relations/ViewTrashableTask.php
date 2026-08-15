<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Resources\Pages\ViewRecord;

final class ViewTrashableTask extends ViewRecord
{
    protected static string $resource = TaskSoftDeleteResource::class;
}
