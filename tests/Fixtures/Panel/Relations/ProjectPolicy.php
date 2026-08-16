<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use App\Models\User;

/**
 * The owner's policy, which is where the *membership* abilities live.
 *
 * Attaching and detaching are questions about the pair, and the pair belongs
 * to the owner: whether a label may be pinned to a project is the project's
 * business, not the label's. `$detachable` lets a test refuse one without
 * refusing the read.
 */
final class ProjectPolicy
{
    /** Lets a test refuse the write without refusing the read. */
    public static bool $creatable = true;

    public static bool $updatable = true;

    public static bool $listable = true;

    public static bool $attachable = true;

    public static bool $detachable = true;

    public static bool $associable = true;

    public function viewAny(User $user): bool
    {
        return self::$listable;
    }

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return self::$creatable;
    }

    public function update(User $user, Project $project): bool
    {
        return self::$updatable;
    }

    public function delete(User $user, Project $project): bool
    {
        return true;
    }

    public function attachAny(User $user, Project $project): bool
    {
        return self::$attachable;
    }

    public function detach(User $user, Project $project, Label $label): bool
    {
        return self::$detachable;
    }

    public function associateAny(User $user, Project $project): bool
    {
        return self::$associable;
    }

    public function dissociate(User $user, Project $project, Task $task): bool
    {
        return self::$associable;
    }

    public static function reset(): void
    {
        self::$creatable = true;
        self::$updatable = true;
        self::$listable = true;
        self::$attachable = true;
        self::$detachable = true;
        self::$associable = true;
    }
}
