<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Enums\SubNavigationPosition;
use PandaPanel\Support\RecordSubNavigation;
use Tests\Fixtures\Panel\ViewOnlyUserPolicy;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->record = User::factory()->create(['name' => 'Grace Hopper']);

    // Building a URL needs the panel context the middleware would have set.
    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

it('links between the pages of one record', function (): void {
    $this->actingAs($this->admin);

    $items = RecordSubNavigation::for(UserResource::class, $this->record, 'view');

    expect(array_column($items, 'key'))->toBe(['view', 'edit'])
        ->and(array_column($items, 'label'))->toBe(['View', 'Edit'])
        ->and($items[0]['href'])->toBe('/admin/users/'.$this->record->getKey())
        ->and($items[1]['href'])->toBe('/admin/users/'.$this->record->getKey().'/edit');
});

it('marks the page being looked at', function (): void {
    $this->actingAs($this->admin);

    $onEdit = RecordSubNavigation::for(UserResource::class, $this->record, 'edit');

    expect(array_column($onEdit, 'active'))->toBe([false, true]);
});

it('drops a page the policy refuses, and with it a navigation of one', function (): void {
    $this->actingAs($this->admin);

    Gate::policy(User::class, ViewOnlyUserPolicy::class);

    // Editing is refused, which leaves only the page being looked at. One
    // link is not navigation, so nothing is offered at all.
    expect(RecordSubNavigation::for(UserResource::class, $this->record, 'view'))->toBe([]);
});

it('offers nothing to a user who may open neither page', function (): void {
    $this->actingAs(User::factory()->create());

    expect(RecordSubNavigation::for(UserResource::class, $this->record, 'view'))->toBe([]);
});

it('ships the sub-navigation with the view page', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/users/'.$this->record->getKey())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $sub = $page->toArray()['props']['page']['subNavigation'];

            expect($sub['position'])->toBe('top')
                ->and(array_column($sub['items'], 'key'))->toBe(['view', 'edit'])
                ->and(array_column($sub['items'], 'active'))->toBe([true, false]);
        });
});

it('ships the sub-navigation with the edit page', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/users/'.$this->record->getKey().'/edit')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $sub = $page->toArray()['props']['page']['subNavigation'];

            expect(array_column($sub['items'], 'active'))->toBe([false, true]);
        });
});

it('sends no sub-navigation on a page with no record', function (): void {
    foreach (['/admin/users', '/admin/users/create'] as $url) {
        $this->actingAs($this->admin)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('page.subNavigation'));
    }
});

it('takes the position from the panel by default', function (): void {
    expect(app(PanelManager::class)->get('admin')->getSubNavigationPosition())
        ->toBe(SubNavigationPosition::Top)
        ->and(UserResource::subNavigationPosition())->toBeNull();
});

it('lets a panel move it', function (): void {
    $panel = app(PanelManager::class)->get('admin')
        ->subNavigationPosition(SubNavigationPosition::Start);

    expect($panel->getSubNavigationPosition())->toBe(SubNavigationPosition::Start);

    $this->actingAs($this->admin)
        ->get('/admin/users/'.$this->record->getKey())
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.subNavigation.position', 'start'));
});

it('serializes the position as the string the frontend switches on', function (): void {
    expect(array_map(
        static fn (SubNavigationPosition $case): string => $case->value,
        SubNavigationPosition::cases(),
    ))->toBe(['top', 'start', 'end']);
});

it('is unaffected by a panel that has no resources', function (): void {
    $panel = Panel::make('bare')->subNavigationPosition(SubNavigationPosition::End);

    expect($panel->getSubNavigationPosition())->toBe(SubNavigationPosition::End);
});
