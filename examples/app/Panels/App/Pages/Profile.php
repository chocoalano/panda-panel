<?php

declare(strict_types=1);

namespace App\Panels\App\Pages;

use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Pages\Page;
use PandaPanel\Pages\Settings\ProfileSettings;

/**
 * A read-only summary of the signed-in user's account.
 *
 * Editing is not duplicated here: the panel's built-in profile settings page
 * owns that, and the header action links to it. Kept as the example of an
 * application page that renders its own component and sends the user on to a
 * framework page.
 */
final class Profile extends Page
{
    protected static ?string $title = 'Account overview';

    protected static ?string $subheading = 'Your account details.';

    protected static ?string $slug = 'profile';

    protected static string $component = 'Panels/App/Pages/Profile';

    protected static ?string $navigationIcon = 'user';

    protected static string|BackedEnum|null $navigationGroup = 'Account';

    protected static int $navigationSort = 5;

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $user = Auth::user();

        return [
            'profile' => $user instanceof User ? [
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'joined' => $user->created_at?->format('M j, Y'),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function headerActions(): array
    {
        return [[
            'name' => 'edit-profile',
            'label' => 'Edit profile',
            'icon' => 'settings',
            'variant' => ActionVariant::Default->value,
            'type' => 'link',
            // The panel's own settings page, so editing never leaves the
            // shell the user is already in.
            'url' => ProfileSettings::url($this->panel()),
            'confirmation' => null,
        ]];
    }
}
