<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Pages\Page;
use PandaPanel\Pages\Settings\AppearanceSettings;
use PandaPanel\Pages\Settings\ProfileSettings;
use PandaPanel\Pages\Settings\SecuritySettings;

/**
 * Sends a starter kit's standalone settings URLs into the panel.
 *
 * The panel owns these screens now, so `/settings/profile` is kept as an
 * address rather than as a second implementation: existing links, Wayfinder
 * output, and bookmarks all still resolve.
 *
 * Shipped as an example because this is the shape of the problem every
 * application adopting the panel has, not because the package needs it.
 */
final class SettingsRedirectController
{
    public function profile(Request $request): RedirectResponse
    {
        return $this->toPanel($request, ProfileSettings::class);
    }

    public function security(Request $request): RedirectResponse
    {
        return $this->toPanel($request, SecuritySettings::class);
    }

    public function appearance(Request $request): RedirectResponse
    {
        return $this->toPanel($request, AppearanceSettings::class);
    }

    /**
     * The user's first accessible panel, which is the one they would land in
     * anyway. A user with access to none has nowhere to send them, and a
     * redirect loop back here would be worse than a plain refusal.
     *
     * @param  class-string<Page>  $page
     */
    private function toPanel(Request $request, string $page): RedirectResponse
    {
        $panel = app(PanelManager::class)->firstAccessibleTo($request->user());

        abort_if($panel === null || ! $panel->hasSettings(), 403);

        return redirect($page::url($panel));
    }
}
