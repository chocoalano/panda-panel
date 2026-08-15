<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\CreateRecord;

/**
 * Says nothing at all, for a page whose own UI already reports the outcome.
 */
final class SilentCreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return array{type: string, message: string}|null
     */
    protected function createdNotification(Model $record): ?array
    {
        return null;
    }
}
