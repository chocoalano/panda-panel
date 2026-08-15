<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;

/**
 * Permits editing, so the positive reordering test exercises the write
 * rather than the refusal the missing-policy case already covers.
 */
final class OrderablePolicy
{
    public function update(User $user, Orderable $record): bool
    {
        return true;
    }
}
