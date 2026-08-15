<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\App\Widgets\AccountSummary;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->member = User::factory()->create(['name' => 'Grace Hopper']);
});

it('serves the app dashboard to a normal user', function (): void {
    $this->actingAs($this->member)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/Dashboard')
            ->where('panel.id', 'app')
            ->where('panel.name', 'Application')
            ->has('widgets', 1)
        );
});

it('shows the signed-in user their own summary, not the whole table', function (): void {
    User::factory()->count(5)->create();

    $this->actingAs($this->member)
        ->get('/app')
        ->assertInertia(function (AssertableInertia $page): void {
            $stats = $page->toArray()['props']['widgets'][0]['data']['stats'];

            expect(collect($stats)->firstWhere('label', 'Signed in as')['value'])
                ->toBe('Grace Hopper')
                // Nothing here counts other users; that is the Admin panel's job.
                ->and(array_column($stats, 'label'))
                ->toBe(['Signed in as', 'Email', 'Member since']);
        });
});

it('builds the app navigation from its own classes only', function (): void {
    $this->actingAs($this->member)
        ->get('/app')
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
                ->all();

            expect($labels)->toBe(['Account overview', 'Profile', 'Security', 'Appearance']);
        });
});

it('serves the profile page inside the panel', function (): void {
    $this->actingAs($this->member)
        ->get('/app/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Panels/App/Pages/Profile')
            ->where('panel.id', 'app')
            ->where('profile.name', 'Grace Hopper')
            ->where('profile.email', $this->member->email)
            ->where('profile.verified', true)
        );
});

it('links editing to the panel\'s own settings page rather than duplicating it', function (): void {
    $this->actingAs($this->member)
        ->get('/app/profile')
        ->assertInertia(function (AssertableInertia $page): void {
            $action = $page->toArray()['props']['page']['headerActions'][0];

            expect($action['type'])->toBe('link')
                ->and($action['url'])->toBe('/app/settings/profile');
        });

    // And the destination stays inside this panel.
    $this->actingAs($this->member)->get('/app/settings/profile')->assertOk();
});

it('lets an admin use the app panel too', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/app')
        ->assertOk();
});

it('redirects a guest to login', function (): void {
    $this->get('/app')->assertRedirect('/login');
    $this->get('/app/profile')->assertRedirect('/login');
});

it('hides the account widget from a request with no user', function (): void {
    // Behind the panel's auth middleware this cannot happen, but the widget
    // should not assume that if it is ever reused elsewhere.
    expect(AccountSummary::canView())->toBeFalse();
});
