<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Scoped;

use PandaPanel\Resources\Pages\ViewRecord;
use Tests\Fixtures\Panel\ScopedUserResource;

final class ViewScopedUser extends ViewRecord
{
    protected static string $resource = ScopedUserResource::class;
}
