---
title: Glossary
---

# Glossary

Every term this documentation uses as if you already knew it, with the page that defines it
properly. Useful on the way in, and useful when a page three sections deep assumes a word from
somewhere else.

## Core terms

| Term | What it means | Page |
| --- | --- | --- |
| **Panel** | One URL prefix with its own navigation, middleware and access rule. An application may have several | [Panels](/concepts/panels) |
| **Panel provider** | The class that configures one panel, like a Laravel service provider. `AdminPanelProvider` → id `admin` | [Panel providers](/concepts/panel-providers) |
| **Resource** | One Eloquent model presented inside one panel: its table, its form, its pages, and its query | [Creating resources](/resources/creating-resources) |
| **Schema** | A PHP description of what renders. `FormSchema`, `TableSchema`, `InfolistSchema` | [FormSchema basics](/forms/overview) |
| **Field** | One input in a form. Its name is the request key, the rule key, and — unless told otherwise — the column | [FormSchema basics](/forms/overview) |
| **Column** | One column of a table. It decides whether that column can be searched, sorted or hidden | [Columns](/tables/columns) |
| **Action** | A backend-owned operation the frontend can request by name. The button crosses the wire; the handler never does | [Action basics](/actions/overview) |
| **Widget** | A box on a dashboard: figures, a chart, a table, or your own Vue | [Widgets](/widgets/overview) |
| **Discovery** | Scanning declared directories for resources, pages and widgets so none has to be listed by hand | [Discovery](/concepts/discovery) |
| **Policy** | An ordinary Laravel policy. The panel delegates every permission decision to one | [Authorization](/concepts/authorization) |
| **Tenancy** | Scoping a panel to one tenant — one shared database, or a database per tenant | [Tenancy concepts](/tenancy/concepts) |
| **Relation manager** | A child table on a record's page, for managing what hangs off it | [Relation managers](/relations/relation-managers) |
| **Infolist** | The read-only detail view, the counterpart to a form | [Infolists](/infolists/overview) |
| **Plugin** | Panel configuration packaged for reuse across projects | [Plugin concepts](/plugins/concepts) |
| **Cluster** | Resources and pages grouped under one path prefix, without changing any route name | [Clusters](/pages-navigation/clusters) |
| **Render hook** | A named injection point in the shell that renders a Vue component you registered | [Render hooks](/panels/render-hooks) |

## Words that carry weight here

| Word | What it means in this documentation |
| --- | --- |
| *scaffold* | Generate the skeleton files — what `make:panel` and `make:panel-resource` do |
| *publish* | Copy files out of the package into your application, through `vendor:publish` |
| *discovered* | Found automatically by discovery; nothing had to register it |
| *registered* | Listed explicitly, usually in `config/panda-panel.php` |
| *hydrate* / *dehydrate* | Fill a form from a record, and the reverse: turn validated input into attributes to write |
| *dehydrated* | A field whose value is persisted. A field that does not dehydrate renders and validates but is never written |
| *whitelist* / *allowlist* | The set of accepted values. A table schema is a whitelist: a column it never declared cannot be sorted or searched |
| *guard* | Laravel's auth guard — it decides which user model is in play |
| *stale* | Out of date. A stale manifest means `panel:cache` needs to run again |
| *outstanding* | Work left to do. The installer lists it at the end, and it is not an error |
| *inert action* | An action that can do nothing — no handler, URL, form or modal. Refused at declaration |
| *record* | One row, one model instance |
| *payload* | What is sent to the browser as Inertia props |
| *scoped* (binding) | A container binding reset once per request, so one request's state cannot leak into the next |

## Errors you are likely to meet

| Message | What it means | Fix |
| --- | --- | --- |
| `PanelSchemaException: model not set` | `$model` was never assigned on the resource | Add `protected static string $model = Product::class;` |
| `PanelSchemaException: duplicate column` | Two columns share a name | A column name keys its cell, visibility, search and sort at once |
| `PanelSchemaException: unknown default sort` | `defaultSort()` names a column the table does not have | The check runs when the page renders, not when the line is written |
| `PanelSchemaException: inert action` | An action with no handler, URL, form or modal | Give it `action()` or `url()` |
| `PanelSchemaException: duplicate actions` | Two actions share a name in one set | Rename one |
| `PanelRegistrationException` | Two panels share an id, or a path/domain pair | Change one, or separate them with `domain()` |
| `PanelAuthorizationException` | Under `strictAuthorization()`: no policy, or no method for the ability asked | Write the policy method |
| **403** on a resource page | The gate was asked and answered no | `php artisan make:policy ProductPolicy --model=Product` |
| **404** on a panel URL | The provider is not in `config/panda-panel.php` | Add the line — nothing scans for it |

## Commands

| Command | What it does |
| --- | --- |
| `panel:install` | Publish, scaffold the first panel, register it, and check the frontend |
| `panel:user` | Create an account through the guard's own model, then report whether it can reach the panel |
| `make:panel` | A new panel provider |
| `make:panel-resource` | A resource with its pages, table and form |
| `make:panel-page` | A standalone page |
| `make:panel-widget` | A dashboard widget |
| `make:panel-relation-manager` | A relation manager, optionally with its page |
| `panel:cache` | Freeze the discovery result for production |
| `panel:clear` | Drop the manifest, so discovery runs per request again |
| `panel:icons` | Rebuild the icon registry after using a new key |
| `panel:assets` | Update published frontend files without overwriting your edits |
| `panel:plugins` | What is installed, on which panel, at which version |
| `panel:publish` | Copy a plugin's assets into the application |

Every command in full: [CLI reference](/cli/panel-install).

## See also

- [Tutorial](/tutorial/) — the eight-step path from nothing to a working panel
- [Overview](/introduction/overview) — what the package is
- [All 358 pages](/pages)
