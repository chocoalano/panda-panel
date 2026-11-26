<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Resources\Pages\CreateRecord;

final class TouredCreateUser extends CreateRecord
{
    protected static string $resource = TouredUserResource::class;
}
