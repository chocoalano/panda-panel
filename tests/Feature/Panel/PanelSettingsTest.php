<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Pages\Settings\AppearanceSettings;
use PandaPanel\Pages\Settings\ProfileSettings;
use PandaPanel\Pages\Settings\SecuritySettings;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
});

it('gives every panel its own settings pages', function (): void {
    expect(ProfileSettings::url('admin'))->toBe('/admin/settings/profile')
        ->and(ProfileSettings::url('app'))->toBe('/app/settings/profile')
        ->and(SecuritySettings::url('admin'))->toBe('/admin/settings/security')
        ->and(AppearanceSettings::url('app'))->toBe('/app/settings/appearance');
});

it('renders the profile settings page in the panel shell', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/settings/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/settings/Profile')
            // The panel prop is what puts it in the panel's own shell rather
            // than the starter kit's.
            ->where('panel.id', 'admin')
            ->where('page.title', 'Profile')
            ->has('mustVerifyEmail')
        );
});

it('renders the appearance settings page with no server state', function (): void {
    $this->actingAs($this->member)
        ->get('/app/settings/appearance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/settings/Appearance')
            ->where('panel.id', 'app')
        );
});

it('guards the security page with password confirmation', function (): void {
    $this->actingAs($this->member)
        ->get('/app/settings/security')
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->member)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/app/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/settings/Security')
            ->where('panel.id', 'app')
        );
});

it('groups the settings pages under Account in navigation', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $account = collect($page->toArray()['props']['navigation'])
                ->firstWhere('label', 'Account');

            expect(array_column($account['items'], 'label'))
                ->toBe(['Profile', 'Security', 'Appearance'])
                ->and(array_column($account['items'], 'href'))
                ->toBe([
                    '/admin/settings/profile',
                    '/admin/settings/security',
                    '/admin/settings/appearance',
                ]);
        });
});

it('lets a panel turn the settings pages off', function (): void {
    $panel = Panel::make('kiosk')->path('kiosk')->settings(false);

    expect($panel->hasSettings())->toBeFalse()
        ->and($panel->getPages())->toBe([]);

    // And the enabled default is not an accident of registration order.
    expect(Panel::make('other')->getPages())->toBe(SETTINGS_PAGES);
});

it('refuses a panel it may not enter, settings included', function (): void {
    $this->actingAs($this->member)
        ->get('/admin/settings/profile')
        ->assertForbidden();
});

it('sends a guest to login rather than rendering settings', function (): void {
    $this->get('/app/settings/profile')->assertRedirect(route('login'));
});

it('redirects the starter kit settings urls into the first panel the user can enter', function (): void {
    // The member cannot enter Admin, so App is where they land. The admin
    // can, and Admin is registered first, so that is where they land.
    $this->actingAs($this->member)->get('/settings')
        ->assertRedirect('/app/settings/profile');

    $this->actingAs($this->admin)->get('/settings/appearance')
        ->assertRedirect('/admin/settings/appearance');
});

it('serializes no closures or class names in the settings page metadata', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/settings/profile')
        ->assertInertia(function (AssertableInertia $page): void {
            $encoded = json_encode($page->toArray()['props']['page']);

            expect($encoded)->toBeString()
                ->and($encoded)->not->toContain('App\\\\Panel')
                ->and($encoded)->not->toContain('Closure');
        });
});

it('keeps the panel action endpoint out of reach of a settings page', function (): void {
    // Settings pages register a GET route only: nothing here accepts a write.
    $this->actingAs($this->admin)
        ->post('/admin/settings/profile')
        ->assertStatus(405);
});
