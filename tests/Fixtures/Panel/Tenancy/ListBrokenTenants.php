<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use PandaPanel\Resources\Pages\ListRecords;

final class ListBrokenTenants extends ListRecords
{
    protected static string $resource = BrokenTenantResource::class;
}
