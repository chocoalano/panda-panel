<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use PandaPanel\Support\PanelTheme;

/*
|--------------------------------------------------------------------------
| A panel's theme reaches the stylesheet it is written against
|--------------------------------------------------------------------------
|
| `PanelTheme` is an allowlist of custom properties, and the reason given for
| the allowlist is that "every name here is one the stylesheet actually
| consumes". That claim was true for all but one of them: an application
| setting `sidebar` set `--sidebar`, while the stylesheet resolved the sidebar
| surface from `--sidebar-background`. The property was accepted, serialised,
| written to the DOM, and read by nothing.
|
| So the allowlist is checked here against the shipped stylesheet rather than
| against itself. A property added to one and not the other is the failure
| this file exists to catch.
*/

/**
 * The custom properties the shipped stylesheet defines, per scheme.
 *
 * @return array<string, list<string>>
 */
function stylesheetTokens(): array
{
    $css = (string) file_get_contents(__DIR__.'/../../../resources/css/panda-panel.css');

    $tokens = [];

    foreach ([':root', '.dark'] as $selector) {
        $start = strpos($css, $selector.' {');
        $block = $start === false ? '' : substr($css, $start, (int) strpos($css, "\n}", (int) $start) - $start);

        preg_match_all('/--([a-z0-9-]+):/i', $block, $matches);

        $tokens[$selector] = array_values(array_unique($matches[1]));
    }

    return $tokens;
}

describe('the theme allowlist', function (): void {
    it('names only properties the stylesheet defines', function (): void {
        $tokens = stylesheetTokens();

        // Reached through the sanitiser rather than the constant: the private
        // allowlist is an implementation detail, but what it accepts is the
        // public contract.
        //
        // Gathered rather than asserted one at a time, so a failure names
        // every orphaned property instead of only the first.
        $orphans = array_values(array_diff(acceptedProperties(), $tokens[':root']));

        expect($orphans)->toBe([]);
    });

    it('names them in the dark scheme too', function (): void {
        $tokens = stylesheetTokens();

        $orphans = array_values(array_diff(acceptedProperties(), $tokens['.dark']));

        expect($orphans)->toBe([]);
    });

    it('accepts the sidebar surface under both of its names', function (): void {
        // `sidebar` is what applications already write and stays accepted;
        // `sidebar-background` is what the stylesheet resolves. Dropping
        // either one silently loses a sidebar colour.
        $theme = (new PanelTheme)->light([
            'sidebar' => '#101010',
            'sidebar-background' => '#202020',
        ]);

        expect($theme->toArray()['light'])
            ->toHaveKey('sidebar', '#101010')
            ->toHaveKey('sidebar-background', '#202020');
    });

    it('still drops a property no stylesheet reads', function (): void {
        $theme = (new PanelTheme)->light(['sidebar-ring' => '#101010']);

        expect($theme->toArray()['light'])->toBe([]);
    });

    it('still drops a value that is not a colour', function (): void {
        $theme = (new PanelTheme)->light([
            'primary' => 'red; background-image: url(https://example.test/x.png)',
        ]);

        expect($theme->toArray()['light'])->toBe([]);
    });
});

/**
 * Every property the sanitiser lets through, found by offering it one.
 *
 * @return list<string>
 */
function acceptedProperties(): array
{
    $candidates = stylesheetTokens()[':root'];
    $offered = [];

    foreach ($candidates as $property) {
        $offered[$property] = '#123456';
    }

    // Plus the aliases and anything else the allowlist might carry: a
    // property accepted here that the stylesheet does not define is exactly
    // what the first test is looking for, so the offer has to be wider than
    // the stylesheet.
    foreach (['sidebar', 'sidebar-background', 'sidebar-ring', 'brand'] as $extra) {
        $offered[$extra] = '#123456';
    }

    return array_keys((new PanelTheme)->light($offered)->toArray()['light']);
}

/**
 * A themed panel with a front door of its own, registered once for this file.
 */
function themedPanel(): Panel
{
    $manager = app(PanelManager::class);

    if (! $manager->has('themed')) {
        $panel = $manager->register(
            Panel::make('themed')
                ->path('themed')
                ->settings(false)
                ->auth(verified: false)
                ->login()
                ->colors(
                    ['primary' => '#4f46e5', 'sidebar' => '#f8fafc'],
                    ['primary' => '#a5b4fc'],
                ),
        );

        app(PanelRouteRegistrar::class)->register($panel);

        Route::getRoutes()->refreshNameLookups();
    }

    return $manager->get('themed');
}

describe('the theme on the way to the browser', function (): void {
    it('reaches the panel\'s own login page', function (): void {
        themedPanel();

        // The auth layout draws the brand mark with `bg-primary`, and it is
        // the one screen whose entire purpose is the panel's identity. It was
        // rendering the package default because nothing applied the palette
        // there — the shared props were always present, so this asserts the
        // half the server owes.
        $this->get('/themed/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('panel/auth/Login')
                ->where('panel.theme.light.primary', '#4f46e5')
                ->where('panel.theme.dark.primary', '#a5b4fc')
            );
    });

    it('carries both schemes, so the browser can pick one', function (): void {
        themedPanel();

        // A panel that customised its colours used to keep its light ones in
        // dark mode. The dark half has to survive serialisation for the
        // frontend to have anything to switch to.
        $this->get('/themed/login')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('panel.theme.light')
                ->has('panel.theme.dark')
            );
    });
});
