<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Who may do what to a user account.
 *
 * Written as an ordinary Laravel policy, because that is all the panel asks
 * for: every `Resource::can*()` resolves to one of these methods through the
 * gate. Nothing here knows a panel exists, which is the point — the same
 * policy governs a console command, an API controller, and the panel alike.
 *
 * Two rules are worth naming:
 *
 *   A member may read and edit their own record and no other. "Not mine" is a
 *   403 rather than a hidden row, so a guessed URL is refused by the same
 *   rule that hides the link.
 *
 *   An administrator may not delete their own account through the panel.
 *   Locking the last administrator out of the panel is the kind of mistake
 *   nobody makes twice, and the policy is where it is cheapest to prevent.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, User $record): bool
    {
        return $user->is_admin || $user->is($record);
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, User $record): bool
    {
        return $user->is_admin || $user->is($record);
    }

    public function delete(User $user, User $record): bool
    {
        return $user->is_admin && ! $user->is($record);
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function restore(User $user, User $record): bool
    {
        return $user->is_admin;
    }

    public function restoreAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, User $record): bool
    {
        return $user->is_admin && ! $user->is($record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->is_admin;
    }
}
