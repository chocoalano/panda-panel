<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Pages;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ViewRecord;

final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;
}
