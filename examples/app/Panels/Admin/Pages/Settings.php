<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use BackedEnum;
use PandaPanel\Pages\Page;

/**
 * A standalone Admin page.
 *
 * Exists to prove a page is not a resource: no model, no table, no records,
 * yet it still gets the panel layout, navigation, breadcrumbs, and
 * authorization. It ships its own Vue component to show that a page can,
 * while a page with nothing bespoke to draw would use the generic renderer.
 */
final class Settings extends Page
{
    protected static ?string $title = 'Settings';

    protected static ?string $subheading = 'Application-wide configuration.';

    protected static ?string $slug = 'settings';

    protected static string $component = 'Panels/Admin/Pages/Settings';

    protected static ?string $navigationIcon = 'settings';

    protected static string|BackedEnum|null $navigationGroup = 'System';

    protected static int $navigationSort = 100;

    /**
     * Read-only for now. It reports what the application is actually
     * configured with rather than offering controls that save nowhere.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'settings' => [
                ['label' => 'Application name', 'value' => (string) config('app.name')],
                ['label' => 'Environment', 'value' => app()->environment()],
                ['label' => 'URL', 'value' => (string) config('app.url')],
                ['label' => 'Timezone', 'value' => (string) config('app.timezone')],
                ['label' => 'Locale', 'value' => (string) config('app.locale')],
            ],
        ];
    }
}
