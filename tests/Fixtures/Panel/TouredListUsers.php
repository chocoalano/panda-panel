<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Resources\Pages\ListRecords;

final class TouredListUsers extends ListRecords
{
    protected static string $resource = TouredUserResource::class;
}
