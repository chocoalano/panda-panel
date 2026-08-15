<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Pages\AccountsDashboard;
use App\Panels\Admin\Pages\Settings;
use App\Panels\Admin\Resources\Users\UserResource;
use App\Panels\Admin\Widgets\UserStats;
use App\Panels\App\Pages\Profile;
use App\Panels\App\Widgets\AccountSummary;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
});

it('discovers each panel only from its own paths', function (): void {
    $manager = app(PanelManager::class);

    expect($manager->resources('admin')->all())->toBe([UserResource::class])
        ->and($manager->resources('app')->all())->toBe([])
        ->and(array_values(array_diff($manager->pages('admin')->all(), SETTINGS_PAGES)))->toBe([AccountsDashboard::class, Settings::class])
        ->and(array_values(array_diff($manager->pages('app')->all(), SETTINGS_PAGES)))->toBe([Profile::class])
        ->and($manager->widgets('app')->all())->toBe([AccountSummary::class])
        ->and($manager->widgets('admin')->all())->not->toContain(AccountSummary::class);
});

it('does not create a resource route in the panel that never declared it', function (): void {
    expect(Route::has('panel.admin.resources.users.index'))->toBeTrue()
        ->and(Route::has('panel.app.resources.users.index'))->toBeFalse();

    $this->actingAs($this->member)->get('/app/users')->assertNotFound();
    $this->actingAs($this->admin)->get('/app/users')->assertNotFound();
});

it('does not create a page route in the other panel', function (): void {
    expect(Route::has('panel.admin.pages.settings'))->toBeTrue()
        ->and(Route::has('panel.app.pages.settings'))->toBeFalse()
        ->and(Route::has('panel.app.pages.profile'))->toBeTrue()
        ->and(Route::has('panel.admin.pages.profile'))->toBeFalse();

    $this->actingAs($this->admin)->get('/admin/profile')->assertNotFound();
    $this->actingAs($this->member)->get('/app/settings')->assertNotFound();
});

it('refuses to build a url for a resource in the wrong panel', function (): void {
    expect(UserResource::url(panel: 'admin'))->toBe('/admin/users')
        ->and(fn (): string => UserResource::url(panel: 'app'))
        ->toThrow(PanelRegistrationException::class, 'is not registered in the panel [app]');
});

it('keeps each panel navigation to its own classes', function (): void {
    $adminLabels = $this->actingAs($this->admin)->get('/admin')
        ->viewData('page')['props']['navigation'];

    $appLabels = $this->actingAs($this->member)->get('/app')
        ->viewData('page')['props']['navigation'];

    $flatten = fn (array $groups): array => collect($groups)
        ->flatMap(fn (array $group): array => array_column($group['items'], 'label'))
        ->all();

    // Every panel ends with the same built-in Account group; what precedes
    // it is the panel's own.
    $account = ['Profile', 'Security', 'Appearance'];

    expect($flatten($adminLabels))->toBe(['Accounts', 'Users', 'Settings', ...$account])
        ->and($flatten($appLabels))->toBe(['Account overview', ...$account]);
});

it('keeps each dashboard to its own widgets', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $ids = array_column($page->toArray()['props']['widgets'], 'id');

            expect($ids)->toContain(UserStats::id())
                ->and($ids)->not->toContain(AccountSummary::id());
        });

    $this->actingAs($this->member)->get('/app')
        ->assertInertia(function (AssertableInertia $page): void {
            $ids = array_column($page->toArray()['props']['widgets'], 'id');

            expect($ids)->toBe([AccountSummary::id()]);
        });
});

it('refuses an admin resource through the other panel action endpoint', function (): void {
    // The strongest isolation check: the endpoint exists on both panels, so
    // an attacker with a valid App session could try to address an Admin
    // resource through it. The resource is resolved against this panel's
    // registry, so it simply does not exist here.
    $target = User::factory()->create();

    $this->actingAs($this->member)
        ->post('/app/actions/record', [
            'resource' => 'users',
            'action' => 'delete',
            'record' => $target->id,
        ])
        ->assertNotFound();

    expect(User::find($target->id))->not->toBeNull();
});

it('refuses an admin bulk action through the other panel endpoint', function (): void {
    $target = User::factory()->create();

    $this->actingAs($this->member)
        ->post('/app/actions/bulk', [
            'resource' => 'users',
            'action' => 'delete',
            'records' => [$target->id],
        ])
        ->assertNotFound();

    expect(User::find($target->id))->not->toBeNull();
});

it('keeps a normal user out of every admin route', function (): void {
    $this->actingAs($this->member);

    foreach ([
        '/admin',
        '/admin/settings',
        '/admin/users',
        '/admin/users/create',
        "/admin/users/{$this->admin->id}",
        "/admin/users/{$this->admin->id}/edit",
    ] as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->post('/admin/users/create', [])->assertForbidden();
    $this->put("/admin/users/{$this->admin->id}/edit", [])->assertForbidden();
    $this->post('/admin/actions/record', [
        'resource' => 'users',
        'action' => 'delete',
        'record' => $this->admin->id,
    ])->assertForbidden();
});

it('keeps a guest out of both panels', function (): void {
    foreach (['/admin', '/admin/users', '/app', '/app/profile'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
});

it('shares panel props scoped to the panel being viewed', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panel.path', 'admin'));

    $this->actingAs($this->admin)->get('/app')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panel.path', 'app'));

    $this->actingAs($this->admin)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panel', null));
});

it('leaves the starter kit untouched by either panel', function (): void {
    $this->actingAs($this->member);

    $this->get('/')->assertOk();
    $this->get('/dashboard')->assertOk();

    // Settings are the one thing the panel did take over: both addresses
    // now lead into the panel the user can reach.
    $this->get('/settings/profile')->assertRedirect('/app/settings/profile');
    $this->get('/settings/security')->assertRedirect('/app/settings/security');

    // And password confirmation still guards security, now on the panel's
    // own route rather than the starter kit's.
    $this->get('/app/settings/security')->assertRedirect(route('password.confirm'));
});
