<?php

declare(strict_types=1);

namespace App\Panels\App;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

/**
 * The end-user panel at /app.
 *
 * Any authenticated, verified user may enter. It registers none of the
 * Admin panel's resources, which is what keeps the two panels isolated.
 */
final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('app')
            ->name('Application')
            ->brandName((string) config('app.name'))
            ->icon('layout-grid')
            ->auth()
            ->navigationGroups([
                'Account',
            ])
            ->discoverResources(app_path('Panels/App/Resources'))
            ->discoverPages(app_path('Panels/App/Pages'))
            ->discoverWidgets(app_path('Panels/App/Widgets'));
    }
}
