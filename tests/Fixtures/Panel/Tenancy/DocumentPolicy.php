<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything allowed. Tenancy is not authorization and must not be tested
 * through one: a policy that also refused would make it impossible to tell a
 * scope that worked from a gate that said no.
 */
final class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Model $record): bool
    {
        return true;
    }

    public function delete(User $user, Model $record): bool
    {
        return true;
    }
}
