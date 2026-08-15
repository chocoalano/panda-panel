<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;

/**
 * Allows viewing a user but never editing one.
 *
 * Swapped in to prove that a record page the policy refuses is absent from
 * the sub-navigation rather than being offered as a link that would 403.
 */
final class ViewOnlyUserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $record): bool
    {
        return true;
    }

    public function update(User $user, User $record): bool
    {
        return false;
    }
}
