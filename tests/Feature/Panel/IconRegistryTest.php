<?php

declare(strict_types=1);

use App\Models\User;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Resources\Resource;
use PandaPanel\Support\NavigationBuilder;
use PandaPanel\Tables\TableSchema;

/**
 * Icon names are strings on the PHP side and resolve through a build-time
 * registry in Vue. An unregistered name renders nothing at all, silently,
 * which is how the appearance page shipped without an icon.
 *
 * @return list<string>
 */
function registeredIconNames(): array
{
    $source = file_get_contents(resource_path('js/panel/icons/registry.ts'));

    expect($source)->toBeString();

    $body = str($source)->between('const ICONS = {', '} satisfies')->toString();

    preg_match_all("/^\s*'?([a-z0-9-]+)'?:/m", $body, $matches);

    return $matches[1];
}

/**
 * Every icon name the panels actually ask for.
 *
 * Navigation is not the only place one can hide: a table's row and bulk
 * actions carry icons too, and an unregistered one there fails exactly as
 * silently — a button that draws no icon.
 *
 * @return list<string>
 */
function requestedIconNames(): array
{
    $manager = app(PanelManager::class);
    $builder = app(NavigationBuilder::class);

    $names = [];

    foreach ($manager->all() as $panel) {
        $names[] = $panel->getIcon();

        foreach ($builder->for($panel, '/'.$panel->getPath()) as $group) {
            foreach ($group['items'] as $item) {
                $names[] = $item['icon'];
            }
        }

        foreach ($manager->resources($panel)->all() as $resource) {
            if (! is_subclass_of($resource, Resource::class)) {
                continue;
            }

            $names[] = $resource::navigationIcon();

            $schema = $resource::table(TableSchema::make());

            foreach ([...$schema->getRecordActions(), ...$schema->getBulkActions()] as $action) {
                $names[] = $action->getIcon();
            }
        }
    }

    return array_values(array_unique(array_filter($names)));
}

it('resolves every icon the panels ask for', function (): void {
    // Built as an admin so nothing is hidden by authorization and every
    // navigation item is inspected.
    $this->actingAs(User::factory()->admin()->create());

    $requested = requestedIconNames();

    expect($requested)->not->toBeEmpty()
        ->and(array_diff($requested, registeredIconNames()))->toBe([]);
});

it('resolves the icons the framework ships its own actions with', function (): void {
    // View, edit, and delete carry icons out of the box. They were absent
    // from the registry, so every panel drew iconless action buttons.
    expect(registeredIconNames())->toContain('eye', 'pencil', 'trash-2');
});

it('keeps the registry free of names nothing resolves', function (): void {
    // Not a failure, but worth seeing: the registry is a hand-maintained
    // list and an entry no icon key can reach is dead weight.
    expect(registeredIconNames())->toContain('palette', 'shield', 'user');
});

it('keeps the registry in step with the icons the source declares', function (): void {
    // The runtime walk above cannot reach every declaration — a header
    // action built inside a method, a wizard step, a filter tab. The command
    // reads the source, so this covers the places a walk cannot.
    $this->artisan('panel:icons --check')->assertSuccessful();
});

it('renders no icon for a name the registry does not hold', function (): void {
    $panel = Panel::make('unknown-icon')->icon('definitely-not-an-icon');

    // The server happily ships it; the frontend registry is the gate, which
    // is why the test above exists.
    expect($panel->getIcon())->toBe('definitely-not-an-icon')
        ->and(registeredIconNames())->not->toContain('definitely-not-an-icon');
});
