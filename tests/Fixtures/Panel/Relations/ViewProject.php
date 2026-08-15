<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Resources\Pages\ViewRecord;

final class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectRelationResource::class;
}
