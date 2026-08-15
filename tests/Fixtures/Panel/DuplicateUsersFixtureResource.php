<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

/**
 * Claims the same slug as UsersFixtureResource, which must be refused at
 * registration rather than silently shadowing the other resource's routes.
 */
final class DuplicateUsersFixtureResource extends FixtureResource
{
    public static function slug(): string
    {
        return 'users';
    }
}
