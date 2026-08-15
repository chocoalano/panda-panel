<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use App\Models\User;

final class LabelPolicy
{
    public static bool $viewable = true;

    public function viewAny(User $user): bool
    {
        return self::$viewable;
    }

    public function view(User $user, Label $label): bool
    {
        return self::$viewable;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Label $label): bool
    {
        return true;
    }

    public function delete(User $user, Label $label): bool
    {
        return true;
    }

    public static function reset(): void
    {
        self::$viewable = true;
    }
}
