<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Pages\AccountsDashboard;
use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\UserGrowth;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\PanelManager;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\Support\ChartOptions;
use PandaPanel\Widgets\Support\WidgetFilters;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

/**
 * A request with a session, built per call.
 *
 * `request()` is a singleton, so mutating its query bag would leak parameters
 * from one assertion into the next.
 *
 * @param  array<string, mixed>  $query
 */
function filterRequest(array $query = []): Request
{
    $request = Request::create('/', 'GET', $query);

    $request->setLaravelSession(app('session.store'));

    return $request;
}

function schema(): FormSchema
{
    return FormSchema::make()->schema([
        Select::make('months')
            ->options(['6' => '6', '12' => '12'])
            ->default('6'),
    ]);
}

/*
 * Reading the state
 */

it('defaults a filter the request did not mention', function (): void {
    $filters = WidgetFilters::fromRequest(filterRequest(), schema());

    expect($filters->dashboard())->toBe(['months' => '6']);
});

it('takes what the request says over the default', function (): void {
    $filters = WidgetFilters::fromRequest(
        filterRequest(['filters' => ['months' => '12']]),
        schema(),
    );

    expect($filters->dashboard())->toBe(['months' => '12']);
});

it('discards a key the schema never declared', function (): void {
    $filters = WidgetFilters::fromRequest(
        filterRequest(['filters' => ['months' => '12', 'injected' => 'nope']]),
        schema(),
    );

    // The schema is the whitelist, exactly as it is on a form.
    expect($filters->dashboard())->toBe(['months' => '12']);
});

it('remembers a filter between visits, and lets an absent one restore', function (): void {
    WidgetFilters::fromRequest(
        filterRequest(['filters' => ['months' => '12']]),
        schema(),
        sessionKey: 'panel.admin.page.accounts',
    );

    // Absence is the only case that falls back.
    $restored = WidgetFilters::fromRequest(
        filterRequest(),
        schema(),
        sessionKey: 'panel.admin.page.accounts',
    );

    expect($restored->dashboard())->toBe(['months' => '12']);
});

it('lets a cleared filter stay cleared', function (): void {
    WidgetFilters::fromRequest(
        filterRequest(['filters' => ['months' => '12']]),
        schema(),
        sessionKey: 'panel.admin.page.cleared',
    );

    // Present but empty is a decision, and restoring over it would ignore
    // what the user just did.
    $cleared = WidgetFilters::fromRequest(
        filterRequest(['filters' => []]),
        schema(),
        sessionKey: 'panel.admin.page.cleared',
    );

    expect($cleared->dashboard())->toBe(['months' => null]);
});

it('gives a widget the page\'s filters with its own on top', function (): void {
    $filters = WidgetFilters::fromRequest(
        filterRequest([
            'filters' => ['months' => '6'],
            'widgets' => ['user-growth' => ['months' => '24']],
        ]),
        schema(),
        ['user-growth' => FormSchema::make()->schema([
            Select::make('months')->options(['6' => '6', '24' => '24']),
        ])],
    );

    // A widget that declares `months` means *its* months.
    expect($filters->for('user-growth'))->toBe(['months' => '24'])
        ->and($filters->for('other'))->toBe(['months' => '6']);
});

/*
 * What a widget does with them
 */

it('reads its own filter and answers differently', function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $widget = (new UserGrowth)->withFilters(['months' => '12']);

    expect($widget->labels())->toHaveCount(12)
        ->and((new UserGrowth)->withFilters([])->labels())->toHaveCount(6);
});

it('describes its filter form beside its data', function (): void {
    $definition = (new UserGrowth)->withFilters(['months' => '12'])->toDefinition();

    expect($definition['filters'])->not->toBeNull()
        ->and($definition['filters']['form']['schema'][0]['value'])->toBe('12')
        ->and($definition['heading'])->toBe('Sign-ups')
        ->and($definition['description'])->toBe('New accounts per month.');
});

it('carries no filter block for a widget that declares none', function (): void {
    expect((new RecentUsers)->toDefinition()['filters'])->toBeNull();
});

/*
 * The page
 */

it('renders a second dashboard with its own filters and widgets', function (): void {
    $this->actingAs($this->admin)->get('/admin/accounts')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect($props['page']['heading'])->toBe('Accounts')
                ->and($props['filters'])->not->toBeNull()
                ->and(array_column($props['widgets'], 'id'))
                ->toContain(UserGrowth::id());
        });
});

it('polls only the widgets that asked to', function (): void {
    $this->actingAs($this->admin)->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $polling = array_column($page->toArray()['props']['widgets'], 'polling', 'id');

            // A request every interval for every open tab is worth it for a
            // count that moves and absurd for one that does not.
            expect($polling['user-stats'])->toBe(60)
                ->and($polling['recent-users'])->toBeNull();
        });
});

/*
 * Charts
 */

it('describes a chart rather than configuring a library', function (): void {
    $options = ChartOptions::make()
        ->legend(false)
        ->stacked()
        ->range(0, 100)
        ->format(suffix: '%')
        ->toArray();

    expect($options['legend'])->toBeFalse()
        ->and($options['stacked'])->toBeTrue()
        ->and($options['min'])->toBe(0.0)
        ->and($options['max'])->toBe(100.0)
        ->and($options['suffix'])->toBe('%');
});

it('sends a chart its options and its height', function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $data = (new UserGrowth)->data();

    expect($data['variant'])->toBe('area')
        ->and($data['maxHeight'])->toBe(200)
        ->and($data['options']['curved'])->toBeTrue()
        ->and($data['options']['legend'])->toBeFalse();
});

/*
 * Table widgets
 */

it('gives a table widget the table builder\'s own state', function (): void {
    User::factory()->count(8)->create();

    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $data = (new RecentUsers)->data();

    expect($data['rows'])->toHaveCount(5)
        ->and($data['pagination']['total'])->toBe(9)
        ->and($data['pagination']['lastPage'])->toBe(2)
        ->and($data['searchable'])->toBeTrue()
        // Namespaced, or two table widgets on one dashboard would fight over
        // `page`.
        ->and($data['namespace'])->toBe('widgets.recent-users');
});

it('searches and sorts a table widget through its own namespace', function (): void {
    User::factory()->create(['name' => 'Findable']);
    User::factory()->count(5)->create();

    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->app->instance('request', filterRequest([
        'widgets' => ['recent-users' => ['search' => 'Findable']],
    ]));

    $data = (new RecentUsers)->data();

    expect($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0]['cells']['name'])->toBe('Findable');
});

it('names the dashboards a panel declares beyond its root', function (): void {
    expect(panel('admin')->getExtraDashboards())->toBe([AccountsDashboard::class]);
});
