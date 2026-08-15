<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The application's own shared props.
 *
 * Note what is *not* here: `panel`, `navigation`, `panels`, `search`,
 * `notifications`, and `broadcasting` all come from the package's own
 * `SharePanelData`, so installing a new version of the panel never means
 * hand-merging a prop into this file.
 *
 * What is left is what an application shares whether or not it has a panel:
 * who is signed in, what the application is called, and how the sidebar was
 * left.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
