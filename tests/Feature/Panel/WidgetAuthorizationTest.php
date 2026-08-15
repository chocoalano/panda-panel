<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Pages\WidgetCollection;
use Tests\Fixtures\Panel\CountingStatsWidget;
use Tests\Fixtures\Panel\ForbiddenStatsWidget;
use Tests\Fixtures\Panel\LazyStatsWidget;
use Tests\Fixtures\Panel\UnregisteredCustomWidget;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());

    CountingStatsWidget::$dataCalls = 0;

    app(PanelManager::class)->setCurrentPanel(
        app(PanelManager::class)->register(
            Panel::make('widget-host')->path('widget-host'),
        ),
    );
});

it('omits a widget the user may not view', function (): void {
    $collection = WidgetCollection::for([
        CountingStatsWidget::class,
        ForbiddenStatsWidget::class,
    ]);

    $ids = array_column($collection->definitions(), 'id');

    expect($ids)->toBe([CountingStatsWidget::id()]);
});

it('never resolves data for an unauthorized widget', function (): void {
    // ForbiddenStatsWidget::stats() throws. Reaching it at all would fail
    // this test, which is how the ordering is proven rather than assumed.
    $collection = WidgetCollection::for([ForbiddenStatsWidget::class]);

    expect($collection->definitions())->toBe([])
        ->and($collection->deferred())->toBeNull();
});

it('resolves an eager widget exactly once', function (): void {
    WidgetCollection::for([CountingStatsWidget::class])->definitions();

    expect(CountingStatsWidget::$dataCalls)->toBe(1);
});

it('does not resolve a lazy widget while building definitions', function (): void {
    $collection = WidgetCollection::for([LazyStatsWidget::class]);

    $definition = $collection->definitions()[0];

    expect($definition['lazy'])->toBeTrue()
        ->and($definition['data'])->toBeNull()
        ->and($collection->deferred())->not->toBeNull();
});

it('advertises no deferred prop when nothing is lazy', function (): void {
    expect(WidgetCollection::for([CountingStatsWidget::class])->deferred())->toBeNull();
});

it('orders widgets by sort then id', function (): void {
    $ids = array_column(
        WidgetCollection::for([
            LazyStatsWidget::class,
            CountingStatsWidget::class,
        ])->definitions(),
        'id',
    );

    expect($ids)->toBe([CountingStatsWidget::id(), LazyStatsWidget::id()]);
});

it('still serializes a custom widget whose component is not in the build', function (): void {
    // The frontend falls back; the backend must not decide the component
    // exists, because it cannot see the bundle.
    $definition = WidgetCollection::for([UnregisteredCustomWidget::class])->definitions()[0];

    expect($definition['component'])->toBe('Panels/Admin/Widgets/DoesNotExist')
        ->and($definition['type'])->toBe('custom');
});

it('derives a stable widget id from the class name', function (): void {
    expect(CountingStatsWidget::id())->toBe('counting-stats-widget')
        ->and(LazyStatsWidget::id())->toBe('lazy-stats-widget');
});
