<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Pages;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
