<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Resources\Pages\EditRecord;

final class EditTrashableTask extends EditRecord
{
    protected static string $resource = TaskSoftDeleteResource::class;
}
