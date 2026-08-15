<?php

declare(strict_types=1);

namespace PandaPanel\Pages;

use PandaPanel\Core\PanelManager;
use PandaPanel\Support\Breadcrumb;
use PandaPanel\Widgets\Widget;

/**
 * The default landing page of every panel.
 *
 * A dashboard is just a page whose widgets come from the panel's widget
 * registry rather than from its own list. Making it a Page means metadata,
 * breadcrumbs, header actions, authorization, and lazy widgets all work the
 * same way here as anywhere else.
 */
class Dashboard extends Page
{
    protected static ?string $title = 'Dashboard';

    protected static ?string $slug = 'dashboard';

    protected static string $component = 'panel/Dashboard';

    protected static ?string $navigationIcon = 'layout-grid';

    /**
     * The dashboard is reached at the panel root, so it must not also
     * register a `/dashboard` page route.
     */
    protected static bool $shouldRegisterNavigation = true;

    /**
     * @return list<class-string<Widget>>
     */
    public function widgets(): array
    {
        /** @var list<class-string<Widget>> $widgets */
        $widgets = app(PanelManager::class)
            ->widgets($this->panel())
            ->all();

        return $widgets;
    }

    /**
     * @return list<Breadcrumb>
     */
    public function breadcrumbs(): array
    {
        return [
            Breadcrumb::make('Dashboard')->current(),
        ];
    }
}
