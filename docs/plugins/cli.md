# Plugin CLI

Two artisan commands deal with plugins: `panel:plugins` reports what is
installed, and `panel:publish` copies plugin files into the application. Reach
for the first when writing a bug report or checking a deploy, and the second
after installing or upgrading a plugin that ships frontend files.

## A minimal working example

```bash
php artisan panel:plugins
```

```text
+-------+----------------+----------------+----------------------+---------+----------+
| Panel | ID             | Name           | Package              | Version | Requires |
+-------+----------------+----------------+----------------------+---------+----------+
| admin | acme-reporting | Acme Reporting | acme/panda-reporting | 1.4.1   | ^1.2     |
| admin | reporting      | Reporting      | in this application  | unknown | any      |
| app   | acme-reporting | Acme Reporting | acme/panda-reporting | 1.4.1   | ^1.2     |
+-------+----------------+----------------+----------------------+---------+----------+
```

## `panel:plugins`

```bash
php artisan panel:plugins [--panel=]
```

Lists what is installed, on which panel, at which version — the report a bug
report should contain. A panel with four plugins has four sources of resources,
pages, widgets and routes, and when one misbehaves the first two questions are
always "which plugin" and "which version", neither of which is answerable from
a stack trace naming this framework.

| Option | Type | Default | Effect |
| --- | --- | --- | --- |
| `--panel=` | string | every panel | Report only the panel with this id |

```bash
php artisan panel:plugins --panel=admin
```

### The columns

| Column | Source | Notes |
| --- | --- | --- |
| Panel | `Panel::getId()` | A plugin installed on two panels gets two rows |
| ID | the key in `Panel::getPlugins()` | Which is `PanelPlugin::id()` |
| Name | `metadata()->name` | Defaults to the title-cased id |
| Package | `metadata()->package` | `in this application` when null |
| Version | `metadata()->version()` | `unknown` when null |
| Requires | `metadata()->requiresPanel` | `any` when null |

Versions come from composer's own installed-packages data rather than from
anything a plugin author remembered to bump, so a plugin reporting 1.4.1 is on
1.4.1.

The two grey placeholders in the Package and Version columns mean different
things and are deliberately not the same word:

| Package | Version | Means |
| --- | --- | --- |
| `in this application` | `unknown` | The plugin declared no package. Normal for a plugin living in `app/`. |
| a real package name | `unknown` | The plugin named a package composer has never heard of. A typo in `metadata()`. |
| a real package name | a version | Installed as a package, and this is what is installed. |

### When there is nothing to report

```text
  INFO  No plugins are registered.
```

The command exits successfully. It prints the same line when `--panel=` names a
panel that does not exist, so an empty report is worth reading twice: check the
id against the provider's `panelId()` before concluding that nothing is
installed.

### Exit code

Always `0`. `panel:plugins` reports; it does not judge. A plugin that would fail
its `requiresPanel` constraint never reaches this command — it throws during
application boot, which is what the command itself does not survive either.

## `panel:publish`

```bash
php artisan panel:publish [plugin] [--force]
```

Copies plugin assets into the application, as declared by each plugin's
`publishes()`.

| Argument / option | Type | Default | Effect |
| --- | --- | --- | --- |
| `plugin` | optional argument | every plugin | Publish only the plugin with this id |
| `--force` | flag | off | Overwrite destination files that already exist |

```bash
php artisan panel:publish                     # every plugin on every panel
php artisan panel:publish acme-reporting      # one plugin, by id
php artisan panel:publish acme-reporting --force
npm run build                                 # required: the registries are build-time globs
```

Output is one line per file plus a count:

```text
  [acme-reporting] …/pages/Panels/AcmeReporting/Widgets/RevenueChart.vue  published
  [acme-reporting] …/pages/Panels/AcmeReporting/Widgets/Sparkline.vue     exists, skipped

  INFO  Published 1 file(s).
```

| Line | Meaning |
| --- | --- |
| `published` | Written. The destination did not exist, or `--force` was given. |
| `exists, skipped` | The destination exists and `--force` was not given. |
| `… does not exist.` | A source path in `publishes()` is wrong. Warned, then skipped. |
| `Nothing to publish.` | No file was **written** — which includes a run where everything was skipped. |

Without `--force` an existing file is never overwritten: a published file the
application has since edited is work, and silently replacing it is losing it.

Always `0`, including for an unknown plugin id and for a plugin whose sources
are missing. [Plugin Assets](assets.md) covers the behaviour in full.

## Where these fit in a deploy

```bash
composer install --no-dev
php artisan panel:plugins          # confirm what the deploy actually installed
php artisan panel:publish          # nothing to do unless a plugin ships new files
npm run build
php artisan optimize               # includes panel:cache
```

`panel:publish` writes into `resources/js/`, which is source rather than build
output, so it belongs in development and in the commit — not in a production
deploy that has already built its assets. Run it locally, review the diff,
commit the files, then deploy.

`panel:cache` is the command that matters for a plugin's *server* side: it
writes the manifest of resource, page and widget classes each panel owns,
including the ones a plugin discovered. Changing what a plugin registers means
rebuilding it.

## Related commands

| Command | Relationship to plugins |
| --- | --- |
| `panel:cache` | Caches the classes each panel owns, including a plugin's discovered ones |
| `panel:clear` | Drops that manifest |
| `panel:assets` | The framework's *own* published frontend. Does not see plugin files |
| `panel:icons` | Scans `app/` and the package source for icon names. Does not scan a plugin package's `src/` |

The last row is worth knowing before shipping a plugin that uses an icon: the
icon registry is generated from a scan that does not include vendor packages, so
a plugin's icons have to be declared in the application before `panel:icons`
will include them. See [Component Registries](../concepts/component-registries.md).

## Gotchas

- **Both commands read panels, not composer.** A plugin package that is
  installed but not listed in any panel's `plugins([...])` appears in neither
  report. That is correct — it is installed, not registered — and it is the
  first thing to check when a plugin seems to do nothing.
- **`--panel=` with an empty value is ignored.** `panel:plugins --panel=` is
  treated as no filter rather than as a panel with an empty id.
- **A plugin on two panels is two rows and two publish passes.** The rows are
  separate objects with separate configuration; the second publish pass reports
  `exists, skipped`.
- **There is no `make:panel-plugin`.** A plugin is one class with one required
  method; see [Creating a Plugin](creating-plugins.md).
- **Neither command can run if a plugin refuses to register.** An incompatible
  `requiresPanel` throws during application boot, which every artisan command
  goes through. The exception names the plugin, so the report you wanted is in
  the error you got.

## See also

- [Plugin Concepts](concepts.md)
- [Plugin Assets](assets.md)
- [Plugin Metadata](metadata.md)
- [Version Compatibility](compatibility.md)
- [Testing Plugins](testing.md)
- [panel:plugins](../cli/panel-plugins.md)
- [panel:cache](../cli/panel-cache.md)
- [panel:clear](../cli/panel-clear.md)
- [panel:assets](../cli/panel-assets.md)
- [Publish Tags](../cli/publish-tags.md)
