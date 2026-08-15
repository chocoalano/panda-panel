<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

/**
 * Occupies the `settings` slug so a standalone page claiming the same slug
 * can be shown to fail. Without that check `/admin/settings` would resolve
 * to whichever registered last.
 */
final class SettingsFixtureResource extends FixtureResource
{
    public static function slug(): string
    {
        return 'settings';
    }
}
