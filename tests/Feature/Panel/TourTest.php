<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Enums\TourSide;
use PandaPanel\Pages\Dashboard;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;
use Tests\Fixtures\Panel\TouredCreateUser;
use Tests\Fixtures\Panel\TouredEditUser;
use Tests\Fixtures\Panel\TouredListUsers;
use Tests\Fixtures\Panel\TouredPage;
use Tests\Fixtures\Panel\TouredUserResource;
use Tests\Fixtures\Panel\TouredViewUser;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);

    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

/**
 * A panel holding the toured resource, made current for the rest of the test.
 */
function onTouredPanel(): void
{
    $manager = app(PanelManager::class);

    if (! $manager->has('toured')) {
        $panel = $manager->register(
            Panel::make('toured')
                ->path('toured')
                ->settings(false)
                ->resources([TouredUserResource::class]),
        );

        app(PanelRouteRegistrar::class)->register($panel);
        Route::getRoutes()->refreshNameLookups();
    }

    $manager->setCurrentPanel($manager->get('toured'));
}

/**
 * @return array<string, mixed>|null
 */
function tourOf(object $page, mixed ...$arguments): ?array
{
    $metadata = $page->render(...$arguments)
        ->toResponse(request())
        ->original
        ->getData()['page']['props']['page'];

    // Present on every page, so a frontend never has to tell "no tour" from
    // "a backend that has never heard of tours".
    expect($metadata)->toHaveKey('tour');

    return $metadata['tour'];
}

/*
 * Nothing, unless somebody asks
 */

it('gives a resource page no tour by default', function (string $page, bool $takesRecord): void {
    $record = (string) User::factory()->create()->getKey();
    $arguments = $takesRecord ? [request(), $record] : [request()];

    expect(tourOf(new $page, ...$arguments))->toBeNull();
})->with([
    'list' => [ListUsers::class, false],
    'create' => [CreateUser::class, false],
    'view' => [ViewUser::class, true],
    'edit' => [EditUser::class, true],
]);

it('gives a standalone page no tour by default', function (): void {
    expect(tourOf(new Dashboard))->toBeNull();
});

it('sends nothing for a tour that has no steps', function (): void {
    expect(Tour::make('empty')->toArray('fallback'))->toBeNull();
});

/*
 * Declared on a resource
 */

it('asks the resource for the tour of the page being shown', function (): void {
    onTouredPanel();

    expect(tourOf(new TouredListUsers, request()))->toBe([
        'key' => 'resource:toured-users.index',
        'steps' => [[
            'title' => 'The list',
            'description' => null,
            'target' => '[data-tour="column:name"]',
            'side' => 'bottom',
        ]],
    ]);
});

it('keeps the key a tour names', function (): void {
    onTouredPanel();

    expect(tourOf(new TouredCreateUser, request())['key'])->toBe('new-user');
});

it('shows nothing on a page the resource has no tour for', function (): void {
    onTouredPanel();

    $record = (string) User::factory()->create()->getKey();

    expect(tourOf(new TouredViewUser, request(), $record))->toBeNull();
});

/*
 * Declared on a page
 */

it('lets a resource page answer for itself, with its record', function (): void {
    onTouredPanel();

    $record = User::factory()->create(['name' => 'Grace Hopper']);

    $tour = tourOf(new TouredEditUser, request(), (string) $record->getKey());

    expect($tour['key'])->toBe('resource:toured-users.edit')
        ->and($tour['steps'][0]['title'])->toBe('Editing Grace Hopper');
});

it('sends a standalone page tour keyed by the page', function (): void {
    expect(tourOf(new TouredPage))->toBe([
        'key' => 'page:toured',
        'steps' => [[
            'title' => 'Welcome',
            'description' => null,
            'target' => null,
            'side' => 'bottom',
        ]],
    ]);
});

/*
 * What a step can point at
 */

it('turns what a step points at into a selector', function (TourStep $step, ?string $target): void {
    expect($step->toArray()['target'])->toBe($target);
})->with([
    'nothing' => [fn (): TourStep => TourStep::make('a'), null],
    'a field' => [fn (): TourStep => TourStep::make('a')->field('email'), '[data-tour="field:email"]'],
    'a column' => [fn (): TourStep => TourStep::make('a')->column('author.name'), '[data-tour="column:author.name"]'],
    'an action' => [fn (): TourStep => TourStep::make('a')->action('create'), '[data-tour="action:create"]'],
    'a region' => [fn (): TourStep => TourStep::make('a')->region('table'), '.panel-table'],
    'a selector' => [fn (): TourStep => TourStep::make('a')->selector('#help > a'), '#help > a'],
    'a name with a quote in it' => [fn (): TourStep => TourStep::make('a')->field('a"b'), '[data-tour="field:a\\"b"]'],
    'the last thing named' => [fn (): TourStep => TourStep::make('a')->field('a')->column('b'), '[data-tour="column:b"]'],
]);

it('carries the description and the side a step asks for', function (): void {
    $step = TourStep::make('Search')->description('Type to narrow the list.')->side(TourSide::Left);

    expect($step->toArray())->toBe([
        'title' => 'Search',
        'description' => 'Type to narrow the list.',
        'target' => null,
        'side' => 'left',
    ]);
});

it('refuses a region the shell does not draw', function (): void {
    TourStep::make('a')->region('footer');
})->throws(InvalidArgumentException::class, 'footer');
