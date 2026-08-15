<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\CreateRecord;

/**
 * Stops before anything is written, the way a page that decides mid-flight
 * that it should not proceed does.
 */
final class HaltingCreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        $this->halt();
    }
}
