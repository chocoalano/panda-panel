<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Support\CssHooks;

/*
 * Theme
 */

it('sends a panel\'s colours as custom properties', function (): void {
    $panel = Panel::make('themed')->path('themed')->colors(
        ['primary' => '#4f46e5', 'sidebar' => 'oklch(0.98 0 0)'],
        ['primary' => '#818cf8'],
    );

    expect($panel->getTheme())->toBe([
        'light' => ['primary' => '#4f46e5', 'sidebar' => 'oklch(0.98 0 0)'],
        'dark' => ['primary' => '#818cf8'],
    ]);
});

it('drops a value that is a stylesheet rather than a colour', function (): void {
    $panel = Panel::make('themed-bad')->path('themed-bad')->colors([
        'primary' => '#4f46e5',
        // These end up inside a `style` attribute.
        'background' => 'red; content: url(https://evil.test)',
        'accent' => 'expression(alert(1))',
    ]);

    // Dropped rather than refused: a panel with one bad colour should render
    // with the rest of its theme.
    expect($panel->getTheme()['light'])->toBe(['primary' => '#4f46e5']);
});

it('drops a property the stylesheet does not read', function (): void {
    $panel = Panel::make('themed-unknown')->path('themed-unknown')->colors([
        'primary' => '#000000',
        // A typo would otherwise be a custom property nothing reads, which is
        // a theme that silently does not apply.
        'primry' => '#ffffff',
    ]);

    expect($panel->getTheme()['light'])->toBe(['primary' => '#000000']);
});

it('accepts the colour syntaxes the stylesheet is written in', function (): void {
    $panel = Panel::make('themed-syntax')->path('themed-syntax')->colors([
        'primary' => '#fff',
        'secondary' => 'rgb(10, 20, 30)',
        'accent' => 'hsl(210 40% 96%)',
        'muted' => 'oklch(0.97 0 0)',
    ]);

    expect($panel->getTheme()['light'])->toHaveCount(4);
});

/*
 * CSS hooks
 */

it('adds classes to the parts the shell actually renders', function (): void {
    $panel = Panel::make('hooked')->path('hooked')->cssHooks([
        'topbar' => 'border-amber-500',
        'table' => 'text-sm',
    ]);

    expect($panel->getCssHooks())->toBe([
        'topbar' => 'border-amber-500',
        'table' => 'text-sm',
    ]);
});

it('appends rather than replaces, because two calls both meant it', function (): void {
    $panel = Panel::make('hooked-twice')->path('hooked-twice')
        ->cssHooks(['topbar' => 'a'])
        ->cssHooks(['topbar' => 'b']);

    expect($panel->getCssHooks()['topbar'])->toBe('a b');
});

it('drops a hook the shell does not render', function (): void {
    $panel = Panel::make('hooked-unknown')->path('hooked-unknown')->cssHooks([
        'topbar' => 'kept',
        // Silently doing nothing is exactly the failure this framework
        // refuses elsewhere.
        'nonsense' => 'dropped',
    ]);

    expect($panel->getCssHooks())->toBe(['topbar' => 'kept']);
});

it('emits every declared hook class somewhere in the shell', function (): void {
    $components = [
        'shell' => 'resources/js/panel/layouts/SidebarPanelLayout.vue',
        'sidebar' => 'resources/js/panel/components/PanelSidebar.vue',
        'topbar' => 'resources/js/panel/components/PanelHeader.vue',
        'page' => 'resources/js/panel/layouts/SidebarPanelLayout.vue',
        'page-header' => 'resources/js/panel/components/PageHeader.vue',
        'table' => 'resources/js/panel/tables/DataTable.vue',
        'form' => 'resources/js/panel/forms/FormRenderer.vue',
        'infolist' => 'resources/js/panel/infolists/InfolistRenderer.vue',
        'widget' => 'resources/js/panel/widgets/WidgetShell.vue',
        'modal' => 'resources/js/panel/actions/ActionModal.vue',
        'table-row' => 'resources/js/panel/tables/DataTable.vue',
    ];

    foreach ($components as $hook => $path) {
        expect(file_get_contents(base_path($path)))
            ->toContain("hook('{$hook}')");
    }

    // A hook nobody renders is a class a panel can set and never see. Every
    // name in the allowlist has to be somewhere, and this is what says so
    // when a name is added without the component that draws it.
    expect(array_values(array_diff(CssHooks::HOOKS, array_keys($components))))
        ->toBe([]);
});

/*
 * What crosses
 */

it('ships the theme and the hooks with every panel page', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $panel = $page->toArray()['props']['panel'];

            expect($panel)->toHaveKey('theme')
                ->and($panel)->toHaveKey('cssHooks')
                // The admin panel declares neither, so both are empty rather
                // than absent — the frontend reads them unconditionally.
                ->and($panel['theme'])->toBe(['light' => [], 'dark' => []])
                ->and($panel['cssHooks'])->toBe([]);
        });
});
