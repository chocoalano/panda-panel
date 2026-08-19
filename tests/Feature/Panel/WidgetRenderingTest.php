<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\SystemInfo;
use App\Panels\Admin\Widgets\UserGrowth;
use App\Panels\Admin\Widgets\UserStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableQuery;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\Support\ColumnSpan;
use PandaPanel\Widgets\Support\Stat;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders every widget type on the dashboard', function (): void {
    $this->get('/admin')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $types = array_column($page->toArray()['props']['widgets'], 'type');

            expect($types)->toContain('stats', 'table', 'chart', 'custom');
        });
});

it('orders widgets by sort', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $ids = array_column($page->toArray()['props']['widgets'], 'id');

            expect($ids)->toBe([
                UserStats::id(),
                RecentUsers::id(),
                UserGrowth::id(),
                SystemInfo::id(),
            ]);
        });
});

it('serializes stats with everything the renderer needs', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $widget = collect($page->toArray()['props']['widgets'])
                ->firstWhere('id', UserStats::id());

            expect($widget['data']['stats'][0])
                ->toHaveKeys(['label', 'value', 'description', 'icon', 'color', 'trend'])
                ->and($widget['data']['stats'][0]['label'])->toBe('Total users')
                ->and($widget['data']['stats'][0]['value'])->toBe(1);
        });
});

it('serializes a table widget with columns and rows', function (): void {
    User::factory()->count(8)->create();

    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $widget = collect($page->toArray()['props']['widgets'])
                ->firstWhere('id', RecentUsers::id());

            expect(array_column($widget['data']['columns'], 'name'))
                ->toBe(['name', 'email', 'created_at'])
                // The widget limits itself: it is a summary, not an index.
                ->and($widget['data']['rows'])->toHaveCount(5)
                ->and($widget['data']['emptyMessage'])->toBe('No one has signed up yet.');
        });
});

it('serializes a custom widget with its component name', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $widget = collect($page->toArray()['props']['widgets'])
                ->firstWhere('id', SystemInfo::id());

            expect($widget['component'])->toBe('Panels/Admin/Widgets/SystemInfo')
                ->and($widget['data'])->toHaveKeys(['laravel', 'php', 'environment', 'debug']);
        });
});

it('withholds a lazy widget payload from the first response', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $widget = collect($page->toArray()['props']['widgets'])
                ->firstWhere('id', UserGrowth::id());

            expect($widget['lazy'])->toBeTrue()
                ->and($widget['data'])->toBeNull();
        });
});

it('omits the deferred prop entirely from the first response', function (): void {
    // Not null: the key is absent until the follow-up request lands. Every
    // Vue component reading `widgetData` must therefore declare it optional,
    // or Vue warns about a missing required prop on the first paint.
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            expect($page->toArray()['props'])->not->toHaveKey('widgetData');
        });
});

it('resolves the lazy payload on the follow-up request', function (): void {
    // A partial reload must send the asset version, otherwise Inertia
    // answers 409 and asks the browser to do a full visit instead.
    $version = $this->get('/admin')->viewData('page')['version'];

    $this->get('/admin', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'panel/Dashboard',
        'X-Inertia-Partial-Data' => 'widgetData',
    ])
        ->assertOk()
        ->assertJsonPath('props.widgetData.'.UserGrowth::id().'.variant', 'area')
        ->assertJsonPath('props.widgetData.'.UserGrowth::id().'.series.0.label', 'Sign-ups');
});

it('charts six months of labels', function (): void {
    $widget = new UserGrowth;

    expect($widget->labels())->toHaveCount(6)
        ->and($widget->series()[0]->values)->toHaveCount(6);
});

it('serializes a column span for every breakpoint', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            foreach ($page->toArray()['props']['widgets'] as $widget) {
                expect($widget['columnSpan'])->toHaveKeys(['default', 'md', 'lg', 'xl']);
            }
        });
});

it('normalizes a scalar span across every breakpoint', function (): void {
    expect(ColumnSpan::normalize(2))
        ->toBe(['default' => 2, 'md' => 2, 'lg' => 2, 'xl' => 2]);
});

it('lets an undeclared breakpoint inherit the one below it', function (): void {
    expect(ColumnSpan::normalize(['default' => 1, 'lg' => 2]))
        ->toBe(['default' => 1, 'md' => 1, 'lg' => 2, 'xl' => 2]);
});

it('clamps a span the frontend has no class for', function (): void {
    // A number out of range is somebody asking for more columns than the grid
    // has, and the largest one is the honest answer.
    expect(ColumnSpan::normalize(99))
        ->toBe(['default' => 4, 'md' => 4, 'lg' => 4, 'xl' => 4])
        ->and(ColumnSpan::normalize(0)['default'])->toBe(1)
        ->and(ColumnSpan::normalize('full')['default'])->toBe('full');
});

it('refuses a span it would otherwise have to guess at', function (): void {
    // This used to answer 1 — a quarter of the width that was asked for, from
    // a typo, with nothing to say why. A word is not a number out of range;
    // it is a mistake, and clamping it hides one.
    expect(fn () => ColumnSpan::normalize('ful'))
        ->toThrow(PanelSchemaException::class, 'neither a number nor "full"');
});

it('refuses a breakpoint this grid does not have', function (): void {
    expect(fn () => ColumnSpan::normalize(['default' => 1, 'sm' => 2]))
        ->toThrow(PanelSchemaException::class, 'It has: default, md, lg, xl');
});

it('counts users with aggregates rather than hydrating them', function (): void {
    User::factory()->count(30)->create();

    // A stat that links needs to know where to link to, and a resource URL
    // is read through the current panel.
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    DB::enableQueryLog();

    $stats = (new UserStats)->data();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $counts = array_values(array_filter(
        $queries,
        static fn (array $query): bool => str_contains($query['query'], 'count(*)'),
    ));

    // Three figures, three aggregates. The fourth query is the sparkline,
    // which reads one bounded column for six months — the shape of a trend
    // cannot be had from a count.
    expect($stats['stats'][0]['value'])->toBe(31)
        ->and($counts)->toHaveCount(3)
        ->and($queries)->toHaveCount(4)
        ->and($stats['stats'][2]['chart'])->toHaveCount(6);
});

it('formats a figure on the server, where what it means is known', function (): void {
    $stat = Stat::make('Revenue', 1204.5)->format(prefix: '£', decimals: 2);

    expect($stat->toArray()['display'])->toBe('£1,204.50')
        // A widget that formatted its own value has said what it wants.
        ->and(Stat::make('Uptime', '99.9%')->toArray()['display'])->toBe('99.9%');
});

it('serializes widget definitions without closures or class names', function (): void {
    $this->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $encoded = json_encode($page->toArray()['props']['widgets']);

            expect($encoded)->toBeString()
                ->and($encoded)->not->toContain('App\\\\Panels')
                ->and($encoded)->not->toContain('Closure');
        });
});

/*
 * What a widget's hidden column costs
 */

it('sends a table widget only the cells the arrangement shows', function (): void {
    // A widget has no column manager, so its arrangement is whatever the
    // schema declared visible. A column hidden by the schema is absent from
    // the rows rather than present and empty.
    $schema = TableSchema::make()->columns([
        TextColumn::make('name'),
        TextColumn::make('email')->visible(false),
    ]);

    $request = Request::create('/', 'GET');
    $query = new TableQuery($schema, $request);
    $record = User::factory()->create();

    $visible = $query->state()['columns']['visible'];

    expect($visible)->toBe(['name'])
        ->and($schema->toRow($record, null, $visible)['cells'])
        ->toBe(['name' => $record->getAttribute('name')]);
});

it('draws a table widget from the arrangement rather than the declaration', function (): void {
    // The renderer's half of the same rule. `columns` carries every declared
    // column including hidden ones, so a template drawing from that list put a
    // header over a column of placeholders — a hidden column read as an empty
    // one rather than as absent.
    $component = File::get(base_path('resources/js/panel/widgets/TableWidget.vue'));

    expect($component)->toContain('v-for="column in visibleColumns"')
        ->and($component)->toContain(':colspan="visibleColumns.length"')
        // The full list survives only where it should: deciding what is
        // sortable, which is a property of the schema rather than the view.
        ->and($component)->not->toContain('v-for="column in columns"');
});
