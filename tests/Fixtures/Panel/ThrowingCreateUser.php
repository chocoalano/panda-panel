<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\CreateRecord;
use RuntimeException;

/**
 * Throws from `afterSave` so the transaction boundary can be asserted: the
 * record must not survive.
 */
final class ThrowingCreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(Model $record): void
    {
        throw new RuntimeException('afterSave exploded');
    }
}
