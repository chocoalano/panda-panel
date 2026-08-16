# `panel:plugins`

Lists what is installed, on which panel, at which version. Reach for it when a
panel misbehaves and you need to know which plugin supplied the screen — and
put its output in the bug report.

```bash
php artisan panel:plugins
```

```text
+-------+---------+---------+---------------------+---------+----------+
| Panel | ID      | Name    | Package             | Version | Requires |
+-------+---------+---------+---------------------+---------+----------+
| admin | billing | Billing | acme/panda-billing  | 1.4.1   | ^1.2     |
| admin | audit   | Audit   | in this application | unknown | any      |
| app   | billing | Billing | acme/panda-billing  | 1.4.1   | ^1.2     |
+-------+---------+---------+---------------------+---------+----------+
```

A panel with four plugins has four sources of resources, pages, widgets and
routes, and when one of them misbehaves the first two questions are always
"which plugin" and "which version" — neither of which is answerable from a stack
trace naming this framework.

## Signature

```text
panel:plugins
    {--panel= : Only this panel}
```

| Option | Default | Effect |
| --- | --- | --- |
| `--panel=` | every panel | Filters by panel **id** — an exact match, not a prefix. |

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

The id is what the provider class name produces: `AdminPanelProvider` is
`admin`. A value that matches no panel produces no rows rather than failing; an
empty string is treated as no filter at all.

When there is nothing to show:

```text
INFO  No plugins are registered.
```

## The columns

| Column | Source | When it has nothing to say |
| --- | --- | --- |
| Panel | `Panel::getId()` | — |
| ID | the key in `Panel::getPlugins()`, which is `PanelPlugin::id()` | — |
| Name | `PluginMetadata::$name` | the base `Plugin` class supplies a headline of the id |
| Package | `PluginMetadata::$package` | `in this application` |
| Version | `PluginMetadata::version()` | `unknown` |
| Requires | `PluginMetadata::$requiresPanel` | `any` |

`in this application` and `unknown` are different answers on purpose. The first
means the plugin names no composer package, which is normal — a project's own
plugin is versioned by the project. The second means it named a package composer
has never heard of, which is a bug in the plugin's metadata.

## Where the version comes from

Composer's own installed-packages data, through
`Composer\InstalledVersions::getPrettyVersion()`, rather than from anything a
plugin author remembered to bump. A plugin reporting `1.4.1` is on `1.4.1`.

```php
use PandaPanel\Plugins\PluginMetadata;

final readonly class PluginMetadata
{
    public function __construct(
        public string $name,
        public ?string $package = null,
        public ?string $requiresPanel = null,
        public ?string $url = null,
    ) {}

    public function version(): ?string;

    /** @return array{name: string, package: string|null, version: string|null, requiresPanel: string|null, url: string|null} */
    public function toArray(): array;
}
```

A plugin gets into this report by naming its package:

```php
use PandaPanel\Plugins\Plugin;
use PandaPanel\Plugins\PluginMetadata;

final class BillingPlugin extends Plugin
{
    public function metadata(): PluginMetadata
    {
        return new PluginMetadata(
            name: 'Billing',
            package: 'acme/panda-billing',
            requiresPanel: '^1.2',
            url: 'https://example.com/panda-billing',
        );
    }
}
```

`url` is part of the metadata but is not a column — the table is already six
wide. Read it with `metadata()->toArray()`.

## Reading the same data in code

```php
use PandaPanel\Core\PanelManager;

$manager = app(PanelManager::class);

foreach ($manager->all() as $panel) {
    foreach ($panel->getPlugins() as $id => $plugin) {
        $metadata = $plugin->metadata();

        printf(
            "%s / %s %s\n",
            $panel->getId(),
            $id,
            $metadata->version() ?? 'unknown',
        );
    }
}
```

| Method | Signature |
| --- | --- |
| `PanelManager::all()` | `all(): list<Panel>` — sorted by panel id |
| `Panel::getPlugins()` | `getPlugins(): array<string, PanelPlugin>` |
| `Panel::hasPlugin()` | `hasPlugin(string $id): bool` |
| `Panel::plugin()` | `plugin(string $id): ?PanelPlugin` |
| `PanelPlugin::metadata()` | `metadata(): PluginMetadata` |

`hasPlugin()` asks about the plugin, not the release of it, which is why ids are
stable across versions.

## How a plugin gets onto a panel

Plugins are registered on the panel, in its provider — not discovered, and not
in config:

```php
use Acme\PandaBilling\BillingPlugin;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->plugins([new BillingPlugin]);
    }
}
```

Two plugins claiming one id on the same panel throw
`PandaPanel\Exceptions\PanelRegistrationException::duplicatePlugin()` at boot,
and `requiresPanel` is checked as the plugin registers — so a plugin built
against an older framework says so by name instead of failing deep inside a
request.

## The other plugin command

A plugin that ships Vue components publishes them into the application's own
tree, because every component registry in this framework is an
`import.meta.glob` over `resources/js/pages/Panels/**` — a build-time allowlist
by design.

```text
panel:publish
    {plugin? : Only this plugin}
    {--force : Overwrite files that already exist}
```

```bash
php artisan panel:publish
php artisan panel:publish billing
php artisan panel:publish billing --force
```

```text
  [billing] /app/resources/js/pages/Panels/Admin/Widgets/Invoices.vue ..... published
  [billing] /app/resources/js/pages/Panels/Admin/Widgets/Plan.vue ... exists, skipped
INFO  Published 2 file(s).
```

It walks every panel's plugins and copies whatever each one's
`publishes(): array<string, string>` returns — source path to destination path,
directories included. A file that already exists is skipped and reported unless
`--force`, because a published file the plugin author changed is a file the
application may have changed too. A source that does not exist is a warning, not
a failure:

```text
WARN  [billing] /app/vendor/acme/panda-billing/resources/js does not exist.
```

Nothing to publish is `Nothing to publish.` and exit `0`.

## Exit codes

Both commands always return `0`. Neither can fail: one lists what is registered,
and the other copies what it was told to copy.

## Gotchas

- **A plugin appears once per panel it is registered on.** The same plugin on
  two panels is two rows, which is the point — configuration can differ.
- **`--panel` matches the id exactly.** Not the path, not the display name.
- **Plugins are not discovered.** A plugin that is installed with composer but
  never added to a panel's `plugins([...])` does not appear here, because it is
  not registered anywhere.
- **`unknown` is a metadata bug.** It means the plugin named a package composer
  cannot find. It is reported rather than raised: a wrong package name is a
  documentation problem, not a reason to refuse to boot.
- **`panel:publish` overwrites nothing by default**, and with `--force` it
  overwrites without a backup. Commit first.
- **Published plugin components need a frontend build.** They are new source
  files under `resources/js/pages/Panels/`, and the glob that resolves them is
  evaluated at build time.

## See also

- [Plugin concepts](../plugins/concepts.md), [The plugin contract](../plugins/contract.md)
- [Creating plugins](../plugins/creating-plugins.md), [Plugin lifecycle](../plugins/lifecycle.md)
- [Plugin metadata](../plugins/metadata.md), [Compatibility](../plugins/compatibility.md)
- [Plugin assets](../plugins/assets.md), [Plugin CLI](../plugins/cli.md)
- [Testing plugins](../plugins/testing.md)
- [Component registries](../concepts/component-registries.md)
- [panel:assets](panel-assets.md), [Publish tags](publish-tags.md)
- [A plugin, end to end](../recipes/plugin.md)
