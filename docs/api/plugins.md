# Plugins Reference

A plugin is a reusable bundle of panel configuration. Everything it does, it does through the panel's own public API — resources, pages, widgets, assets, render hooks, navigation groups, exactly as a panel provider would. There is no second configuration surface, which is what stops a plugin doing something a panel cannot.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Contracts\PanelPlugin` | The contract everything resolves through |
| `PandaPanel\Plugins\Plugin` | A convenient base class |
| `PandaPanel\Plugins\PluginMetadata` | What a plugin says about itself |
| `PandaPanel\Plugins\PluginCompatibility` | The `requiresPanel` check |

## A plugin, end to end

```php
<?php

namespace App\Panels\Plugins;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    private ?string $group = 'Reporting';

    public static function make(): self
    {
        return new self;
    }

    public function group(?string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function register(Panel $panel): void
    {
        if ($this->group !== null) {
            $panel->navigationGroups([$this->group]);
        }

        $panel->resources([RevenueResource::class]);
    }

    public function boot(Panel $panel): void
    {
        $panel->cssHooks(['page' => 'reporting-page']);
    }
}
```

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->path('admin')
        ->plugins([
            ReportingPlugin::make()->group('Analytics'),
        ]);
}
```

```bash
php artisan panel:plugins
```

A configurable plugin is the point: one plugin, two panels, different shapes — without either panel shipping a class of its own.

## The contract

```php
namespace PandaPanel\Contracts;

interface PanelPlugin
{
    public function id(): string;
    public function register(Panel $panel): void;
    public function boot(Panel $panel): void;
    public function metadata(): PluginMetadata;

    /** @return array<string, string> absolute source => absolute destination */
    public function publishes(): array;
}
```

Nothing in the framework asks for a `Plugin`. Every lookup, every hook, and `panel:publish` all go through the contract, so a plugin shipped as its own package should implement it directly — a package that extends an application's class is a package coupled to it.

## The three phases

| Phase | When | What belongs there |
| --- | --- | --- |
| `register()` | while the panel is being configured | resources, pages, widgets, navigation groups, settings |
| `boot()` | after the panel is resolved, per request | anything needing the container, the user, or a URL |
| `publishes()` | never automatically — only `panel:publish` | files this plugin copies into the application |

`register()` runs during the application's boot, for **every** request, including the ones that never touch a panel. Work there that queries, reads the authenticated user, or resolves a route is work every request pays for and most requests waste.

`boot()` runs once the panel has been resolved for a request, and only for requests that reached that panel. Plugins boot *before* the panel's own `bootUsing()` callbacks, so an application always gets the last word over a plugin it installed.

Getting the two backwards is the usual plugin bug, and it is a quiet one: a `register()` that queries works perfectly in development and shows up as a database hit on every asset request in production.

```php
// Proven by the test suite: register() has run, boot() has not.
$panel = Panel::make('plug')->path('plug')->plugins([ReportingPlugin::make()]);

$panel->getResources();        // contains RevenueResource
$panel->getCssHooks();         // no 'page' key yet

$panel->boot();

$panel->getCssHooks()['page']; // 'reporting-page'
```

## `Plugin`

The base class supplies four of the five methods, so the common case — a bundle of resources and pages — is one method.

```php
abstract class Plugin implements PanelPlugin
{
    public function id(): string;                     // kebab of the class name, minus 'Plugin'
    public function boot(Panel $panel): void;         // no-op
    public function metadata(): PluginMetadata;       // name from the id, no package
    public function publishes(): array;               // []
    public static function in(?Panel $panel): ?static;
}
```

`register()` stays abstract, because a plugin that registers nothing is not a plugin.

### `id()`

`ReportingPlugin` becomes `reporting`. Stable across versions: an application asking `hasPlugin('reporting')` is asking about the plugin, not about the release of it.

### `in()`

The reverse of `Panel::plugin()`, and the shape a resource supplied by a plugin uses to read the settings it was installed with:

```php
$currency = BillingPlugin::in(panel())?->currency() ?? 'usd';
```

`null` rather than a throw for a panel that does not have it: a resource shared between two panels and installed in one of them is a normal arrangement, not a mistake.

Found by class rather than by looking up `id()`, because reading `id()` would mean constructing a plugin to ask it — and a plugin whose constructor takes its configuration cannot be constructed without it.

## `PluginMetadata`

```php
final readonly class PluginMetadata
{
    public function __construct(
        public string $name,
        public ?string $package = null,
        public ?string $requiresPanel = null,
        public ?string $url = null,
    );

    public function version(): ?string;
    public function toArray(): array;   // {name, package, version, requiresPanel, url}
}
```

```php
public function metadata(): PluginMetadata
{
    return new PluginMetadata(
        name: 'Billing',
        package: 'acme/panda-billing',
        requiresPanel: '^1.2',
        url: 'https://example.com/docs/billing',
    );
}
```

The version is read from composer's own installed-packages data rather than declared by hand. A hand-written version string is a string somebody forgets to change, and a plugin reporting 1.2.0 while 1.4.1 is installed is worse than a plugin reporting nothing.

`version()` returns `null` for a plugin with no package — which is correct for a plugin living in the application, versioned by the project — and also for a package name composer has never heard of. A wrong package name in metadata is a documentation bug, not a reason to refuse to boot.

## `PluginCompatibility`

```php
public static function assert(PanelPlugin $plugin, string $panelId, ?string $installed = null): void;
```

Called by `Panel::plugins()` before `register()` — the earliest moment the answer is knowable and the last moment before the plugin starts changing the panel.

A plugin whose `requiresPanel` constraint is not satisfied throws `PanelRegistrationException::incompatiblePlugin()`:

```text
The [Billing] plugin ([billing], registered on the [admin] panel) requires
panda-panel ^2.0, and 1.4.1 is installed. Upgrade the plugin, or pin this
framework to a version it supports.
```

The failure it replaces is why it exists: a plugin calling a panel method that was removed fails with `Call to undefined method Panel::whatever()`, somewhere inside a request, naming the framework rather than the plugin — and the person reading it has no way to know which of their four plugins asked for it.

The check is skipped in three cases, all of which mean there is no question to answer:

- the plugin declared no constraint;
- this framework is not installed as a composer package (a path repository, a git checkout, or its own test suite);
- the installed version is a branch alias like `dev-main`, or composer's `no-version-set` placeholder.

Pass `$installed` explicitly to test the constraint without depending on what is installed:

```php
PluginCompatibility::assert($plugin, 'admin', installed: '1.4.1');
```

## Registration

```php
public function plugins(array $plugins): self;         // array<array-key, PanelPlugin>
public function getPlugins(): array;                   // array<string, PanelPlugin>
public function hasPlugin(string $id): bool;
public function plugin(string $id): ?PanelPlugin;
```

`plugins()` asserts compatibility, stores the plugin by id, and calls `register()` — in that order, per plugin, as the array is walked.

Two plugins claiming one id throw `PanelRegistrationException::duplicatePlugin()`. A panel is asked `hasPlugin('billing')` to decide what to show, so two answers to one question is caught at registration rather than discovered as a resource that appears twice.

```php
$panel->hasPlugin('reporting');             // true
$panel->plugin('reporting');                // the configured instance
ReportingPlugin::in($panel)?->getGroup();   // whatever it was installed with
```

## Publishing assets

A plugin that ships a Vue component cannot have it resolved from its own package: every component registry in this framework is an `import.meta.glob` over the application's tree, which is a build-time allowlist by design.

So a plugin publishes its components into that tree, and from then on they are the application's files — in the repository, in the build, and editable. That is a feature rather than a workaround; a component you cannot see the source of is a component you cannot debug.

```php
public function publishes(): array
{
    return [
        __DIR__.'/../resources/js/Widgets' => resource_path('js/pages/Panels/Admin/Widgets'),
        __DIR__.'/../resources/js/Fields/Signature.vue' => resource_path('js/pages/Panels/Admin/Fields/Signature.vue'),
    ];
}
```

Both a directory and a single file work; the destination is an absolute path.

```bash
php artisan panel:publish                # every plugin on every panel
php artisan panel:publish reporting      # one plugin, by id
php artisan panel:publish --force        # overwrite files that already exist
```

A source path that does not exist is reported as a warning rather than failing the command.

## `panel:plugins`

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

```text
 Panel  ID         Name       Package               Version   Requires
 admin  reporting  Reporting  in this application   unknown   any
 admin  billing    Billing    acme/panda-billing    1.4.1     ^1.2
```

The report a bug report should contain. A plugin with no package reads `in this application`; a plugin naming a package composer has never heard of reads `unknown` — two different problems that should not look the same.

## Testing a plugin

```php
use PandaPanel\Core\Panel;

it('registers its resource', function (): void {
    $panel = Panel::make('plug')->path('plug')->plugins([
        ReportingPlugin::make(),
    ]);

    expect($panel->getResources())->toContain(RevenueResource::class)
        ->and($panel->getNavigationGroups())->toContain('Reporting');
});

it('is configurable', function (): void {
    $panel = Panel::make('plug-bare')->path('plug-bare')->plugins([
        ReportingPlugin::make()->group(null),
    ]);

    expect($panel->getNavigationGroups())->not->toContain('Reporting');
});
```

A panel built with `Panel::make()` needs no HTTP and no registration, so a plugin's `register()` is testable in isolation. Call `$panel->boot()` to reach the second phase.

## Notes

- **A plugin cannot do anything a panel cannot.** That is the design, not a limitation: the panel's public API is the whole surface, so a plugin is always reviewable against something already documented.
- **`register()` runs on every request, panel or not.** It is part of the application's boot. Anything expensive belongs in `boot()`.
- **Plugins boot before the panel's own callbacks.** An application can undo what a plugin did.
- **Compatibility is asserted before `register()`.** An incompatible plugin never gets to change the panel.
- **A plugin id is a stable name, not a version.** Keep it the same across releases; that is what `hasPlugin()` depends on.
- **`publishes()` is never run automatically.** No install step copies files behind your back; `panel:publish` is a deliberate command.

## See also

- [Plugin concepts](../plugins/concepts.md)
- [Creating plugins](../plugins/creating-plugins.md)
- [The plugin contract](../plugins/contract.md)
- [Plugin lifecycle](../plugins/lifecycle.md)
- [Plugin metadata](../plugins/metadata.md)
- [Compatibility](../plugins/compatibility.md)
- [Plugin assets](../plugins/assets.md)
- [Plugin CLI](../plugins/cli.md)
- [Testing plugins](../plugins/testing.md)
- [Contracts reference](contracts.md)
- [Core API reference](core.md)
- [Exceptions reference](exceptions.md)
