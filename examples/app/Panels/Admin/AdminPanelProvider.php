<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use App\Models\User;
use App\Panels\Admin\Pages\AccountsDashboard;
use Illuminate\Contracts\Auth\Authenticatable;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;
use PandaPanel\Pages\Dashboard;

/**
 * The administrator panel at /admin.
 *
 * Access is an explicit predicate rather than a hidden convention, so the
 * 403 for a non-admin is provable by a request test.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->name('Administrator')
            ->brandName((string) config('app.name'))
            ->icon('shield')
            // `appearance` styles the rail: 'inset', 'floating', or 'sidebar'.
            // `variant: 'header'` would swap the rail for top navigation.
            ->sidebar(appearance: 'sidebar')
            ->auth()
            ->navigationGroups([
                'User Management',
                'System',
            ])
            // Nothing is listed by hand: the resource, the page, and the
            // four widgets are found under these paths. Explicit
            // registration still works and merges with discovery, which is
            // how a panel pulls in a class that lives elsewhere.
            // The panel root, then a second dashboard of its own. Both are
            // Page classes, so each authorizes, appears in navigation, and
            // carries its own filters independently.
            ->dashboards([
                Dashboard::class,
                AccountsDashboard::class,
            ])
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->discoverPages(app_path('Panels/Admin/Pages'))
            ->discoverWidgets(app_path('Panels/Admin/Widgets'))
            // House style for every action this panel builds, applied as each
            // one is made so a schema that states its own still wins.
            // Destructive work confirms by default: the panel decides that
            // once rather than every table remembering to.
            ->configureActions(static function (Action $action): void {
                if ($action->getVariant() === ActionVariant::Destructive) {
                    $action->requiresConfirmation();
                }
            })
            ->canAccess(static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin);
    }
}
