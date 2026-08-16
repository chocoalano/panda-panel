# Plugin Contract

`PandaPanel\Contracts\PanelPlugin` is the whole plugin API: five methods, no
container bindings, no attributes, no registration file. Reach for this page
when you are implementing the interface directly — which is what a plugin
shipped as its own package should do — or when you need to know exactly what
the framework calls, and when.

## The interface

```php
<?php

namespace PandaPanel\Contracts;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

interface PanelPlugin
{
    public function id(): string;

    public function register(Panel $panel): void;

    public function boot(Panel $panel): void;

    public function metadata(): PluginMetadata;

    /** @return array<string, string> absolute source path => absolute destination path */
    public function publishes(): array;
}
```

A complete implementation, with nothing inherited:

```php
<?php

declare(strict_types=1);

namespace Acme\Reporting;

use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\PluginMetadata;

final class ReportingPlugin implements PanelPlugin
{
    public function id(): string
    {
        return 'acme-reporting';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\ReportResource::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Acme Reporting',
            package: 'acme/panda-reporting',
        );
    }

    public function publishes(): array
    {
        return [];
    }
}
```

## Who calls what, and from where

| Method | Called by | When |
| --- | --- | --- |
| `id()` | `Panel::plugins()` | once, to key the plugin and detect a duplicate |
| `id()` | `panel:publish` | to match the optional `plugin` argument |
| `register()` | `Panel::plugins()` | immediately, in array order |
| `metadata()` | `PluginCompatibility::assert()` | during `plugins()`, before `register()` |
| `metadata()` | `panel:plugins` | once per row of the report |
| `boot()` | `Panel::boot()` | per request into that panel, after the access check |
| `publishes()` | `panel:publish` | only when that command runs |

Nothing else in the framework touches a plugin. There is no discovery, no
container resolution, and no serialization: `panel:cache` stores class names
for resources, pages and widgets and never the plugin objects that registered
them.

## `id(): string`

A stable name. Used to key the plugin on the panel, so two plugins cannot share
one, and so a panel can be asked whether it has a given plugin without knowing
the class.

```php
public function id(): string
{
    return 'acme-reporting';
}
```

Stable across versions: an application asking `hasPlugin('acme-reporting')` is
asking about the plugin, not about the release of it. Changing the id in a
minor release breaks every application that branched on it.

`Plugin`'s default derives it from the class name — see
[the base class](#the-base-class) below.

Two plugins with one id throws from `plugins()`:

```php
use PandaPanel\Exceptions\PanelRegistrationException;

// Two plugins claim the id [reporting] in panel [admin]. A plugin id is how a
// panel is asked whether it has one, so it has to be unique.
```

## `register(Panel $panel): void`

Configures the panel. Runs while the panel is being built, inside
`Panel::plugins()`, before the plugin is usable and before any request exists.

```php
use PandaPanel\Core\Panel;

public function register(Panel $panel): void
{
    $panel
        ->navigationGroups(['Insights'])
        ->discoverResources(__DIR__.'/Resources')
        ->widgets([Widgets\RevenueChart::class]);
}
```

Nothing here may query the database, resolve a route, or read the current
user — there is no request yet, and this runs during service provider boot for
every request the application serves, panel or not.

The return value is ignored. The method mutates the panel it is handed; it does
not build a new one.

## `boot(Panel $panel): void`

Runs once the panel has been resolved for a request, from `Panel::boot()`.

```php
use PandaPanel\Core\Panel;
use PandaPanel\Enums\RenderHook;

public function boot(Panel $panel): void
{
    if (auth()->user()?->is_admin) {
        $panel->renderHook(RenderHook::HeaderEnd, 'Panels/Admin/Hooks/AuditLink');
    }
}
```

Most plugins are configuration and have nothing to do here, which is why
`Plugin` defaults it to a no-op. Implementing the contract directly still means
writing the empty method.

The ordering guarantee: every plugin's `boot()` runs before any of the panel's
own `bootUsing()` callbacks, so an application always gets the last word over a
plugin it installed. [Register and Boot](lifecycle.md) has the details.

## `metadata(): PluginMetadata`

What the plugin is, for a report a person reads, and what framework version it
needs.

```php
use PandaPanel\Plugins\PluginMetadata;

public function metadata(): PluginMetadata
{
    return new PluginMetadata(
        name: 'Acme Reporting',
        package: 'acme/panda-reporting',
        requiresPanel: '^1.2',
        url: 'https://github.com/acme/panda-reporting',
    );
}
```

It is on the contract rather than only on the base class because the two
questions asked of a misbehaving plugin are always "which one" and "which
version", and neither is answerable from a class name. See
[Plugin Metadata](metadata.md) for the value object and
[Version Compatibility](compatibility.md) for what `requiresPanel` does.

The method is called during registration, so it must not query or resolve
anything either.

## `publishes(): array`

Files this plugin copies into the application, keyed source to destination.

```php
/**
 * @return array<string, string>
 */
public function publishes(): array
{
    return [
        __DIR__.'/../resources/js' => resource_path('js/pages/Panels/AcmeReporting'),
        __DIR__.'/../stubs/report.stub' => base_path('stubs/report.stub'),
    ];
}
```

A key may be a file or a directory; a directory is copied recursively,
preserving relative paths. Both sides are real absolute paths, which is why
this is a method rather than configuration — only the plugin knows where its
own files are.

It is on the contract rather than only on the base class because
`panel:publish` has to be able to ask *any* plugin what it publishes, not just
the ones that happened to inherit. Empty for a plugin that ships no files,
which is most of them.

## The base class

`PandaPanel\Plugins\Plugin` implements the contract with defaults for
everything except `register()`:

| Method | Default |
| --- | --- |
| `id()` | `Str::kebab(Str::beforeLast(class_basename(static::class), 'Plugin'))` — `ReportingPlugin` → `reporting` |
| `register()` | abstract, from the interface — you write it |
| `boot()` | no-op |
| `metadata()` | `new PluginMetadata(name: Str::headline($this->id()))` — no package, so no version, and no constraint |
| `publishes()` | `[]` |

```php
use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;

final class ReportingPlugin extends Plugin
{
    public function register(Panel $panel): void
    {
        $panel->resources([ReportResource::class]);
    }
}
```

It also adds one static method the interface does not have.

### `Plugin::in(?Panel $panel): ?static`

This plugin, as it is configured on a given panel — the reverse of
`Panel::plugin()`, and the shape a resource supplied by a plugin uses to read
the settings it was installed with:

```php
use App\Panels\Plugins\ReportingPlugin;

$currency = ReportingPlugin::in(panel())?->getCurrency() ?? 'usd';
```

Null rather than a throw for a panel that does not have it: a resource shared
between two panels and installed in one of them is a normal arrangement, not a
mistake. A `null` panel — which is what `panel()` answers outside a panel
request — also gives `null`.

It finds the plugin by class rather than by looking up `id()`, because reading
`id()` would mean constructing a plugin to ask it, and a plugin whose
constructor takes its configuration cannot be constructed without it.

A plugin implementing the contract directly does not get `in()`. Either copy
the three lines, or use the panel's own lookup and narrow the type:

```php
use Acme\Reporting\ReportingPlugin;

$plugin = panel()?->plugin('acme-reporting');

$currency = $plugin instanceof ReportingPlugin ? $plugin->getCurrency() : 'usd';
```

## Which to use

| | `extends Plugin` | `implements PanelPlugin` |
| --- | --- | --- |
| Lives in the application | yes | works, but you write four more methods |
| Shipped as a composer package | couples the package to this base class | yes |
| Methods to write | `register()` | all five |
| Gets `in()` | yes | no |

Both are equally supported by the framework. Nothing in it asks for `Plugin`:
every lookup, every hook and `panel:publish` all go through the contract.

## The panel side

```php
use PandaPanel\Contracts\PanelPlugin;
use PandaPanel\Core\Panel;

/** @param array<array-key, PanelPlugin> $plugins */
public function plugins(array $plugins): self;

/** @return array<string, PanelPlugin> keyed by id */
public function getPlugins(): array;

public function hasPlugin(string $id): bool;

public function plugin(string $id): ?PanelPlugin;
```

`plugins()` may be called more than once on a panel; each call appends. The
duplicate-id check spans every call, not just the current one.

## Notes

- The interface has no `make()`. That is a convention on implementations, not
  a contract method, and the framework never calls it.
- Type against `PanelPlugin`, not `Plugin`, anywhere you accept a plugin from
  somebody else. `Panel::plugin()` already returns the contract.
- `register()` and `metadata()` are both called during panel construction, and
  `metadata()` is called first — the compatibility check runs before the plugin
  is allowed to change anything.

## See also

- [Plugin Concepts](concepts.md)
- [Creating a Plugin](creating-plugins.md)
- [Register and Boot](lifecycle.md)
- [Plugin Metadata](metadata.md)
- [Version Compatibility](compatibility.md)
- [Plugin Assets](assets.md)
- [Testing Plugins](testing.md)
- [Panel API Reference](../panels/api.md)
- [Contracts Reference](../api/contracts.md)
- [Exceptions Reference](../api/exceptions.md)
