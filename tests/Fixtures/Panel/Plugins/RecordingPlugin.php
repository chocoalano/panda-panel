<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Plugins;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

/**
 * Records when each phase ran, so the order between them can be asserted
 * without a test reaching into a closure to find out.
 */
final class RecordingPlugin extends Plugin
{
    /** @var list<string> */
    public static array $calls = [];

    public function id(): string
    {
        return 'recording';
    }

    public function register(Panel $panel): void
    {
        self::$calls[] = 'register';
    }

    public function boot(Panel $panel): void
    {
        self::$calls[] = 'boot';
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
