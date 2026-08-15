<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Resources\ResourceConfiguration;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Support\NavigationBuilder;
use Tests\Fixtures\Panel\ReorderableUsersResource;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($this->admin);
});

/**
 * A second panel holding the same class, configured differently.
 */
function directoryPanel(): Panel
{
    $manager = app(PanelManager::class);

    if (! $manager->has('directory')) {
        $panel = $manager->register(
            Panel::make('directory')
                ->path('directory')
                ->settings(false)
                ->resources([
                    ResourceConfiguration::for(UserResource::class)
                        ->slug('people')
                        ->pluralLabel('People')
                        ->navigationLabel('Directory')
                        ->navigationGroup('Company')
                        ->navigationIcon('building-2')
                        ->navigationSort(99)
                        ->modifyQueryUsing(
                            static fn (Builder $query): Builder => $query->where('is_admin', false),
                        ),
                ]),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return $manager->get('directory');
}

it('keeps the class default when a panel configures nothing', function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    expect(UserResource::slug())->toBe('users')
        ->and(UserResource::pluralLabel())->toBe('Users');
});

it('gives the same class a different slug in another panel', function (): void {
    $directory = directoryPanel();

    expect(UserResource::slugIn(panel('admin')))->toBe('users')
        ->and(UserResource::slugIn($directory))->toBe('people');
});

it('registers routes under the slug the panel chose', function (): void {
    directoryPanel();

    expect(route('panel.directory.resources.people.index', absolute: false))
        ->toBe('/directory/people')
        // The other panel is untouched.
        ->and(route('panel.admin.resources.users.index', absolute: false))
        ->toBe('/admin/users');
});

it('builds urls for the panel being asked about', function (): void {
    $directory = directoryPanel();

    expect(UserResource::url(panel: $directory))->toBe('/directory/people')
        ->and(UserResource::url(panel: panel('admin')))->toBe('/admin/users');
});

it('labels the sidebar entry as the panel asked', function (): void {
    $directory = directoryPanel();

    $items = collect(app(NavigationBuilder::class)->for($directory, '/directory'))
        ->flatMap(fn (array $group): array => $group['items']);

    $item = $items->firstWhere('label', 'Directory');

    expect($item)->not->toBeNull()
        ->and($item['href'])->toBe('/directory/people')
        ->and($item['icon'])->toBe('building-2')
        ->and($item['sort'])->toBe(99);
});

it('groups the entry where the panel put it', function (): void {
    $directory = directoryPanel();

    $groups = collect(app(NavigationBuilder::class)->for($directory, '/directory'));

    expect($groups->pluck('label')->all())->toContain('Company');
});

it('narrows what the panel can reach', function (): void {
    $directory = directoryPanel();
    $manager = app(PanelManager::class);

    User::factory()->count(2)->create(['is_admin' => false]);

    $manager->setCurrentPanel($directory);
    $inDirectory = UserResource::query()->count();

    $manager->setCurrentPanel(panel('admin'));
    $inAdmin = UserResource::query()->count();

    // The directory sees members only; the admin panel sees everyone.
    expect($inDirectory)->toBe(2)
        ->and($inAdmin)->toBe(3);
});

it('makes a record outside the narrowed query a 404, not a filtered row', function (): void {
    $directory = directoryPanel();

    app(PanelManager::class)->setCurrentPanel($directory);

    // Every read goes through query(), so an admin cannot be reached from
    // the directory panel at all.
    expect(fn () => UserResource::resolveRecord($this->admin->getKey()))
        ->toThrow(ModelNotFoundException::class);
});

it('falls back to the class outside any panel', function (): void {
    app(PanelManager::class)->setCurrentPanel(null);

    expect(UserResource::slug())->toBe('users')
        ->and(UserResource::configurationIn(null))->toBeNull();
});

it('refuses two classes configured onto one slug', function (): void {
    $panel = Panel::make('clashing')->path('clashing')->settings(false)->resources([
        ResourceConfiguration::for(UserResource::class)->slug('shared'),
        ResourceConfiguration::for(ReorderableUsersResource::class)->slug('shared'),
    ]);

    // Two resources answering one URL is ambiguous, and staying quiet about
    // it would surface as the wrong records much later.
    expect(fn () => app(PanelManager::class)->register($panel))
        ->toThrow(PanelRegistrationException::class, 'is used by both');
});

it('keeps a configured class from also being registered bare', function (): void {
    $manager = app(PanelManager::class);

    $panel = $manager->register(
        Panel::make('once-only')
            ->path('once-only')
            ->settings(false)
            ->resources([
                ResourceConfiguration::for(UserResource::class)->slug('staff'),
                // The same class again, unconfigured. It must not claim its
                // default slug alongside the configured one.
                UserResource::class,
            ]),
    );

    expect($manager->resources($panel)->slugs())->toBe(['staff']);
});
