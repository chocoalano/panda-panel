<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Pages\Page;

/**
 * A page nobody may open. Its route must 403 rather than redirect, which is
 * what proves navigation visibility is not the access control.
 */
final class ForbiddenPage extends Page
{
    protected static ?string $title = 'Restricted';

    protected static ?string $slug = 'restricted';

    public static function canAccess(): bool
    {
        return false;
    }
}
