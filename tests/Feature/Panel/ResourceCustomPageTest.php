<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Widgets\PageContext;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Panel\AuditUser;
use Tests\Fixtures\Panel\ContextAwareStatsWidget;
use Tests\Fixtures\Panel\FilteredStatsWidget;
use Tests\Fixtures\Panel\ResourceWidgetUserResource;
use Tests\Fixtures\Panel\SingularSettingsResource;
use Tests\Fixtures\Panel\WidgetedListUsers;
use Tests\Fixtures\Panel\WidgetedViewUser;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);

    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

function resourceWidgetPanel(): Panel
{
    $manager = app(PanelManager::class);

    if ($manager->has('resource-widgets')) {
        return $manager->get('resource-widgets');
    }

    $panel = $manager->register(
        Panel::make('resource-widgets')
            ->path('resource-widgets')
            ->settings(false)
            ->resources([ResourceWidgetUserResource::class]),
    );

    app(PanelRouteRegistrar::class)->register($panel);
    Route::getRoutes()->refreshNameLookups();

    return $panel;
}

/*
 * Custom pages, route paths, and InteractsWithRecord
 */

it('registers a custom page at the path it declares', function (): void {
    expect(AuditUser::routePath('audit'))->toBe('{record}/audit');
});

it('falls back to the page key when no path is declared', function (): void {
    expect(WidgetedListUsers::routePath('index'))->toBe('index');
});

it('resolves and authorizes the record for a custom page', function (): void {
    $record = User::factory()->create();

    $rendered = (new AuditUser)->render((string) $record->getKey());

    $props = $rendered->toResponse(request())->original->getData()['page']['props'];

    expect($props['auditedRecordKey'])->toBe($record->getKey());
});

it('404s a record outside the resource scope on a custom page', function (): void {
    expect(fn () => (new AuditUser)->render('999999'))
        ->toThrow(ModelNotFoundException::class);
});

it('403s a record the user may not view on a custom page', function (): void {
    $record = User::factory()->create();

    // A member may not view another user through the resource policy, and a
    // custom page is not a way around that.
    $this->actingAs(User::factory()->create());

    expect(fn () => (new AuditUser)->render((string) $record->getKey()))
        ->toThrow(HttpException::class);
});

/*
 * Resource widgets, and the context they are given
 */

it('hands a list page widget the resource query and its count', function (): void {
    User::factory()->count(4)->create();

    $rendered = (new WidgetedListUsers)->render(request());

    $props = $rendered->toResponse(request())->original->getData()['page']['props'];
    $stats = $props['headerWidgets'][0]['data']['stats'];

    // Five: four made here plus the acting admin.
    expect($stats[0]['value'])->toBe(5)
        ->and($stats[1]['value'])->toBe('none')
        ->and($props['footerWidgets'])->toBe([]);
});

it('hands a record page widget the record it is showing', function (): void {
    $record = User::factory()->create();

    $rendered = (new WidgetedViewUser)->render(request(), (string) $record->getKey());

    $props = $rendered->toResponse(request())->original->getData()['page']['props'];
    $stats = $props['footerWidgets'][0]['data']['stats'];

    expect($stats[1]['value'])->toBe((string) $record->getKey())
        // No query on a record page, so a count of nothing rather than the
        // whole table.
        ->and($stats[0]['value'])->toBe(0)
        ->and($props['headerWidgets'])->toBe([]);
});

it('sends empty widget lists for a page that declares none', function (): void {
    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('headerWidgets', [])
            ->where('footerWidgets', []));
});

it('places resource widgets on the standard index page', function (): void {
    resourceWidgetPanel();

    User::factory()->count(4)->create();

    $this->get('/resource-widgets/widget-users')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            expect($props['headerWidgets'][0]['id'])->toBe(ContextAwareStatsWidget::id())
                // Five: four made here plus the acting admin.
                ->and($props['headerWidgets'][0]['data']['stats'][0]['value'])->toBe(5)
                ->and($props['footerWidgets'][0]['id'])->toBe(FilteredStatsWidget::id());
        });
});

it('applies resource widget filters from the query string', function (): void {
    resourceWidgetPanel();

    $url = '/resource-widgets/widget-users?'.http_build_query([
        'widgets' => [
            FilteredStatsWidget::id() => [
                'scope' => 'active',
            ],
        ],
    ]);

    $this->get($url)
        ->assertOk()
        ->assertInertia(function ($page): void {
            $widget = $page->toArray()['props']['footerWidgets'][0];

            expect($widget['data']['stats'][0]['value'])->toBe('active')
                ->and($widget['filters']['form']['schema'][0]['value'])->toBe('active');
        });
});

it('passes record context to resource-level widgets on record pages', function (): void {
    resourceWidgetPanel();

    $record = User::factory()->create();

    $this->get('/resource-widgets/widget-users/'.$record->getKey())
        ->assertOk()
        ->assertInertia(function ($page) use ($record): void {
            $stats = $page->toArray()['props']['headerWidgets'][0]['data']['stats'];

            expect($stats[1]['value'])->toBe((string) $record->getKey())
                ->and($stats[0]['value'])->toBe(0);
        });
});

it('refuses to render a context widget placed where there is none', function (): void {
    $widget = new ContextAwareStatsWidget;

    expect(fn () => $widget->data())->toThrow(LogicException::class, 'expects page context');
});

it('counts through the page query only once', function (): void {
    $context = PageContext::forQuery(static fn () => User::query());

    DB::enableQueryLog();

    $context->count();
    $context->count();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

/*
 * Singular resources
 */

it('routes a singular resource without a record segment', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('singleton')
            ->path('singleton')
            ->settings(false)
            ->resources([SingularSettingsResource::class]),
    );

    app(PanelRouteRegistrar::class)->register($panel);

    // Routes added after boot are not in the name lookup until it is rebuilt.
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('panel.singleton.resources.app-settings.edit'))->toBeTrue()
        ->and(route('panel.singleton.resources.app-settings.edit', absolute: false))
        ->toBe('/singleton/app-settings/edit');
});

it('builds a singular url without naming a record', function (): void {
    expect(SingularSettingsResource::isSingular())->toBeTrue();
});

it('resolves the one record itself', function (): void {
    $only = User::query()->firstOrFail();

    expect(SingularSettingsResource::resolveSingularRecord()->is($only))->toBeTrue();
});
