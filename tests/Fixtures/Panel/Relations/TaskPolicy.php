<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use App\Models\User;

/**
 * The related record's own policy, which is where reading and writing *it*
 * live. Each flag is separate so a test can refuse exactly one ability and
 * see only that one affordance disappear.
 */
final class TaskPolicy
{
    public static bool $viewable = true;

    public static bool $creatable = true;

    public static bool $updatable = true;

    public static bool $deletable = true;

    public static bool $restorable = true;

    public function viewAny(User $user): bool
    {
        return self::$viewable;
    }

    public function view(User $user, Task $task): bool
    {
        return self::$viewable;
    }

    public function create(User $user): bool
    {
        return self::$creatable;
    }

    public function update(User $user, Task $task): bool
    {
        return self::$updatable;
    }

    public function delete(User $user, Task $task): bool
    {
        return self::$deletable;
    }

    public function restore(User $user, Task $task): bool
    {
        return self::$restorable;
    }

    public function restoreAny(User $user): bool
    {
        return self::$restorable;
    }

    public function forceDeleteAny(User $user): bool
    {
        return self::$deletable;
    }

    public function deleteAny(User $user): bool
    {
        return self::$deletable;
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return self::$deletable;
    }

    public static function reset(): void
    {
        self::$viewable = true;
        self::$creatable = true;
        self::$updatable = true;
        self::$deletable = true;
        self::$restorable = true;
    }
}
