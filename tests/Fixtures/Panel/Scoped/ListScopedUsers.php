<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Scoped;

use PandaPanel\Resources\Pages\ListRecords;
use Tests\Fixtures\Panel\ScopedUserResource;

final class ListScopedUsers extends ListRecords
{
    protected static string $resource = ScopedUserResource::class;
}
