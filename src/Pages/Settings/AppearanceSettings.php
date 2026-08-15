<?php

declare(strict_types=1);

namespace PandaPanel\Pages\Settings;

use BackedEnum;
use PandaPanel\Pages\Page;

/**
 * Theme selection. Entirely client-side, so the page ships no props: the
 * choice is held in local storage and a cookie by `useAppearance`.
 */
final class AppearanceSettings extends Page
{
    protected static ?string $title = 'Appearance';

    protected static ?string $subheading = 'Choose how the interface looks on this device.';

    protected static ?string $slug = 'settings-appearance';

    protected static string $component = 'panel/settings/Appearance';

    protected static ?string $navigationIcon = 'palette';

    protected static string|BackedEnum|null $navigationGroup = 'Account';

    protected static int $navigationSort = 30;

    public static function routePath(): string
    {
        return 'settings/appearance';
    }
}
