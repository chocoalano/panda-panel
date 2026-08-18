# Panda Panel documentation

Every page in this documentation, in the order it is meant to be read. Each section below is
self-contained: start at its first page and the cross-links at the foot of each page carry you
through the rest.

**New here?** [Overview](introduction/overview.md) → [Requirements](getting-started/requirements.md) →
[Installation](getting-started/installation.md) → [Opening your first panel](getting-started/first-panel.md).

**Something is broken?** [Troubleshooting](#running-it-in-production) is symptom-first — find the
page named after what you are seeing.

## Start here

### Introduction

What this framework is, what it decides for you, and where it stops.

- [Overview](introduction/overview.md)
- [Why Panda Panel](introduction/why-panda-panel.md)
- [Feature Overview](introduction/features.md)
- [Architecture At A Glance](introduction/architecture.md)
- [Inertia And Vue Approach](introduction/inertia-vue.md)
- [Comparison With Filament Concepts](introduction/filament-comparison.md)
- [Package Limits And Tradeoffs](introduction/tradeoffs.md)

### Getting started

From `composer require` to a panel you can sign into.

- [Requirements](getting-started/requirements.md)
- [Compatibility matrix](getting-started/compatibility.md)
- [Installation](getting-started/installation.md)
- [Laravel Vue starter kit setup](getting-started/vue-starter-kit.md)
- [Frontend requirements](getting-started/frontend-requirements.md)
- [Running `panel:install`](getting-started/installer.md)
- [Creating the first user](getting-started/first-user.md)
- [Opening your first panel](getting-started/first-panel.md)
- [Directory structure](getting-started/directory-structure.md)
- [Package name migration](getting-started/package-name-migration.md)
- [Common install problems](getting-started/common-install-problems.md)

## How it works

### Core concepts

The machinery every other page assumes: panels, discovery, routing, the request lifecycle.

- [Panels](concepts/panels.md)
- [Panel Providers](concepts/panel-providers.md)
- [Request Lifecycle](concepts/request-lifecycle.md)
- [Panel Context](concepts/panel-context.md)
- [Server Metadata to Vue](concepts/metadata-to-vue.md)
- [Discovery](concepts/discovery.md)
- [Routing](concepts/routing.md)
- [Authorization](concepts/authorization.md)
- [Frontend Assets](concepts/frontend-assets.md)
- [Component Registries](concepts/component-registries.md)
- [Caching](concepts/caching.md)

## Building a panel

### Panels

Defining, branding, mounting and gating a panel — and running more than one.

- [Defining a Panel](panels/defining-panels.md)
- [Panel IDs, Paths, and Domains](panels/ids-paths-domains.md)
- [Multi-Panel Applications](panels/multi-panel.md)
- [Middleware and Guards](panels/middleware.md)
- [Panel Access Rules](panels/access.md)
- [Branding, Logo, Icon, Favicon](panels/branding.md)
- [Sidebar and Header Layouts](panels/layouts.md)
- [Navigation Groups](panels/navigation-groups.md)
- [Panel Switcher](panels/panel-switcher.md)
- [Dashboards](panels/dashboards.md)
- [Settings Pages](panels/settings-pages.md)
- [Render Hooks](panels/render-hooks.md)
- [Panel Assets](panels/assets.md)
- [Panel Cache](panels/cache.md)
- [Panel API Reference](panels/api.md)

### Resources

The CRUD unit: a model, its pages, its queries and its abilities.

- [Creating Resources](resources/creating-resources.md)
- [Resource Directory Convention](resources/directory-convention.md)
- [Model Binding](resources/model-binding.md)
- [List, Create, View and Edit Pages](resources/crud-pages.md)
- [Resource Pages](resources/resource-pages.md)
- [Resource Queries](resources/queries.md)
- [Query Performance](resources/performance.md)
- [Labels and Navigation](resources/labels-navigation.md)
- [URLs And Route Names](resources/urls-routes.md)
- [Soft Deletes](resources/soft-deletes.md)
- [Singular Resources](resources/singular-resources.md)
- [Nested Resources](resources/nested-resources.md)
- [Resource Configuration Per Panel](resources/per-panel-configuration.md)
- [Lifecycle Hooks](resources/lifecycle-hooks.md)
- [Resource Authorization](resources/authorization.md)
- [Global Search Integration](resources/global-search.md)
- [Resource API Reference](resources/api.md)

### Forms and schemas

Building a form, and how state moves between Vue and the server.

- [FormSchema Basics](forms/overview.md)
- [Field State Lifecycle](forms/state-lifecycle.md)
- [Hydration And Dehydration](forms/hydration.md)
- [Live Fields](forms/live-fields.md)
- [Validation](forms/validation.md)
- [Field Visibility](forms/visibility.md)
- [Disabled And Hidden Fields](forms/disabled-hidden.md)
- [Relationship Forms](forms/relationships.md)
- [File Uploads](forms/file-uploads.md)
- [Options Endpoints](forms/options-endpoints.md)
- [Form Layouts](forms/layouts.md)
- [Prime Components](forms/prime-components.md)
- [Custom Fields](forms/custom-fields.md)

### Form fields

Every field type, one page each.

- [Builder](forms/fields/builder.md)
- [Checkbox](forms/fields/checkbox.md)
- [Code Editor](forms/fields/code-editor.md)
- [Color Picker](forms/fields/color.md)
- [Date and Time](forms/fields/date.md)
- [File Upload](forms/fields/file-upload.md)
- [Key Value](forms/fields/key-value.md)
- [Markdown Editor](forms/fields/markdown.md)
- [Number](forms/fields/number.md)
- [Radio](forms/fields/radio.md)
- [Repeater](forms/fields/repeater.md)
- [Rich Editor Field](forms/fields/rich-editor.md)
- [Select Field](forms/fields/select.md)
- [Slider Field](forms/fields/slider.md)
- [Tags Field](forms/fields/tags.md)
- [Text Field](forms/fields/text.md)
- [Toggle Field](forms/fields/toggle.md)

### Tables

Columns, filters, search, sorting, grouping and everything in the toolbar.

- [TableSchema Basics](tables/overview.md)
- [Columns](tables/columns.md)
- [Search](tables/search.md)
- [Sorting](tables/sorting.md)
- [Filters](tables/filters.md)
- [Query Builder Filter](tables/query-builder.md)
- [Filter Tabs](tables/tabs.md)
- [Grouping](tables/grouping.md)
- [Summaries](tables/summaries.md)
- [Pagination](tables/pagination.md)
- [Persisted Table State](tables/persisted-state.md)
- [Column Manager](tables/column-manager.md)
- [Card Layout](tables/card-layout.md)
- [Frozen And Pinned Columns](tables/pinned-columns.md)
- [Editable Columns](tables/editable-columns.md)
- [Record Actions](tables/record-actions.md)
- [Bulk Actions](tables/bulk-actions.md)
- [Toolbar Actions](tables/toolbar-actions.md)
- [Reordering Records](tables/reordering.md)
- [Relationship Columns](tables/relationships.md)
- [Array Data Tables](tables/array-data.md)
- [Table API Reference](tables/api.md)

### Actions

Buttons that do something: modals, confirmation, bulk, authorization, transactions.

- [Action Basics](actions/overview.md)
- [Action Scopes](actions/scopes.md)
- [Row Actions](actions/row-actions.md)
- [Table Actions](actions/table-actions.md)
- [Bulk Actions](actions/bulk-actions.md)
- [Infolist Actions](actions/infolist-actions.md)
- [Action Authorization](actions/authorization.md)
- [Action Modals](actions/modals.md)
- [Action Forms](actions/forms.md)
- [Transactions](actions/transactions.md)
- [Built-In Actions](actions/built-in-actions.md)
- [Create, Edit, View, And Delete Actions](actions/crud-actions.md)
- [Replicate](actions/replicate.md)
- [Restore And Force Delete](actions/restore-force-delete.md)
- [Import And Export Actions](actions/import-export.md)
- [Relation Actions](actions/relation-actions.md)
- [Custom Actions](actions/custom-actions.md)

### Relations

Editing what hangs off a record — managers, pivots, nested resources.

- [Relation Managers](relations/relation-managers.md)
- [Relation Pages](relations/relation-pages.md)
- [Relation Tables](relations/relation-tables.md)
- [Relation Forms](relations/relation-forms.md)
- [Attach And Detach](relations/attach-detach.md)
- [Associate And Dissociate](relations/associate-dissociate.md)
- [Pivot Fields](relations/pivot-fields.md)
- [Related Record Policies](relations/policies.md)
- [Soft Deleted Relations](relations/soft-deletes.md)
- [Nested Resource Vs Relation Manager](relations/nested-vs-relation-manager.md)

### Infolists

Read-only detail views.

- [InfolistSchema Basics](infolists/overview.md)
- [Entries](infolists/entries.md)
- [Infolist Layouts](infolists/layouts.md)
- [Repeatable Entries](infolists/repeatable-entries.md)
- [Custom Entries](infolists/custom-entries.md)
- [Actions in Infolists](infolists/actions.md)
- [Entry Reference](infolists/entry-reference.md)

### Pages and navigation

Custom pages, clusters, breadcrumbs, sub-navigation, URLs.

- [Custom Pages](pages-navigation/custom-pages.md)
- [Page Discovery](pages-navigation/discovery.md)
- [Page Authorization](pages-navigation/authorization.md)
- [Page Headings](pages-navigation/headings.md)
- [Breadcrumbs](pages-navigation/breadcrumbs.md)
- [Sub Navigation](pages-navigation/sub-navigation.md)
- [Clusters](pages-navigation/clusters.md)
- [Full Page URLs](pages-navigation/urls.md)
- [Prefetching](pages-navigation/prefetching.md)
- [Error Notifications](pages-navigation/error-notifications.md)

### Widgets

Dashboard stats, charts, tables and your own Vue.

- [Widgets](widgets/overview.md)
- [Stats Widgets](widgets/stats.md)
- [Chart Widgets](widgets/charts.md)
- [Table Widgets](widgets/tables.md)
- [Custom Vue Widgets](widgets/custom-vue.md)
- [Widget Filters](widgets/filters.md)
- [Lazy Widgets](widgets/lazy-loading.md)
- [Polling](widgets/polling.md)
- [Widget Authorization](widgets/authorization.md)
- [Column Span and Layout](widgets/layout.md)

## Application features

### Authentication and users

Login, registration, two-factor, passkeys, profile, and what the panel asks of your user model.

- [Fortify Integration](authentication/fortify.md)
- [Login](authentication/login.md)
- [Registration](authentication/registration.md)
- [Password Reset](authentication/password-reset.md)
- [Email Verification](authentication/email-verification.md)
- [The `panel:user` Command](authentication/panel-user-command.md)
- [User Model Requirements](authentication/user-model.md)
- [The `PanelUser` Contract](authentication/panel-user-contract.md)
- [Profile Settings](authentication/profile.md)
- [Security Settings](authentication/security.md)
- [Appearance Settings](authentication/appearance.md)
- [Two-Factor Authentication](authentication/two-factor.md)
- [Email Code Challenge](authentication/email-code-challenge.md)
- [Passkeys](authentication/passkeys.md)

### Tenancy

Scoping a panel to a tenant — single-database, database-per-tenant, and the security rules.

- [Tenancy Concepts](tenancy/concepts.md)
- [Tenant Resolver](tenancy/resolver.md)
- [`HasPanelTenants`](tenancy/has-panel-tenants.md)
- [`PanelTenant`](tenancy/panel-tenant.md)
- [Tenant Switcher](tenancy/switcher.md)
- [Tenant URLs](tenancy/urls.md)
- [Resource Tenant Scoping](tenancy/resource-scoping.md)
- [Single Database Tenancy](tenancy/single-database.md)
- [Database Per Tenant](tenancy/database-per-tenant.md)
- [Using with `stancl/tenancy`](tenancy/stancl-tenancy.md)
- [Queues and Tenant Context](tenancy/queues.md)
- [Tenancy Security Checklist](tenancy/security-checklist.md)

### Notifications and broadcasting

Toasts, the notification centre, database notifications, Reverb and Echo.

- [Toast Notifications](notifications/toast.md)
- [Flash Toast Bridge](notifications/flash-bridge.md)
- [Database Notifications](notifications/database.md)
- [Notification Center](notifications/notification-center.md)
- [Notification Actions](notifications/actions.md)
- [Broadcasting](notifications/broadcasting.md)
- [Reverb and Echo Setup](notifications/reverb-echo.md)
- [Channel Authorization](notifications/channel-authorization.md)
- [Queued Notifications](notifications/queues.md)
- [Testing Notifications](notifications/testing.md)

### Global search

Making resources findable from the header.

- [Global Search Overview](search/overview.md)
- [Searchable Resources](search/searchable-resources.md)
- [Search Attributes](search/attributes.md)
- [Relationship Search](search/relationships.md)
- [Search Result Details](search/result-details.md)
- [Search Result URLs](search/result-urls.md)
- [Panel Search Configuration](search/panel-configuration.md)
- [Search Security](search/security.md)

### Import and export

CSV and XLSX in both directions, queued, with failure reports.

- [ExportAction](import-export/export-action.md)
- [ImportAction](import-export/import-action.md)
- [Exporter Classes](import-export/exporters.md)
- [Importer Classes](import-export/importers.md)
- [Columns And Mapping](import-export/columns-mapping.md)
- [CSV And XLSX](import-export/csv-xlsx.md)
- [Queued Imports](import-export/queued-imports.md)
- [Queued Exports](import-export/queued-exports.md)
- [Failure Reports](import-export/failure-reports.md)
- [Storage And Cleanup](import-export/storage-cleanup.md)
- [Import And Export Notifications](import-export/notifications.md)

## Extending it

### Frontend customization

The published Vue: your own columns, fields, pages, widgets, shell and theme.

- [Published Asset Structure](frontend/assets.md)
- [Vue Component Tree](frontend/component-tree.md)
- [Inertia Pages](frontend/inertia-pages.md)
- [Host Modules](frontend/host-modules.md)
- [Wayfinder routes](frontend/wayfinder.md)
- [Custom Page Components](frontend/custom-pages.md)
- [Custom Columns](frontend/custom-columns.md)
- [Custom Fields](frontend/custom-fields.md)
- [Custom Widgets](frontend/custom-widgets.md)
- [Custom Shell Components](frontend/custom-shell.md)
- [Icons](frontend/icons.md)
- [Tailwind Theme](frontend/tailwind-theme.md)
- [CSS Hooks](frontend/css-hooks.md)
- [Updating published assets](frontend/updating-assets.md)

### Plugins

Packaging panel configuration for reuse.

- [Plugin Concepts](plugins/concepts.md)
- [Creating a Plugin](plugins/creating-plugins.md)
- [Plugin Contract](plugins/contract.md)
- [Register and Boot](plugins/lifecycle.md)
- [Plugin Metadata](plugins/metadata.md)
- [Version Compatibility](plugins/compatibility.md)
- [Plugin Assets](plugins/assets.md)
- [Plugin CLI](plugins/cli.md)
- [Testing Plugins](plugins/testing.md)

## Reference

### CLI reference

Every artisan command, flag by flag.

- [`panel:install`](cli/panel-install.md)
- [`panel:user`](cli/panel-user.md)
- [`make:panel`](cli/make-panel.md)
- [`make:panel-resource`](cli/make-panel-resource.md)
- [`make:panel-page`](cli/make-panel-page.md)
- [`make:panel-widget`](cli/make-panel-widget.md)
- [`make:panel-relation-manager`](cli/make-panel-relation-manager.md)
- [`panel:cache`](cli/panel-cache.md)
- [`panel:clear`](cli/panel-clear.md)
- [`panel:icons`](cli/panel-icons.md)
- [`panel:assets`](cli/panel-assets.md)
- [`panel:plugins`](cli/panel-plugins.md)
- [Publish Tags](cli/publish-tags.md)

### Configuration reference

`config/panda-panel.php`, middleware, routes, redirects, environment.

- [config/panda-panel.php](configuration/panda-panel.md)
- [Panel Config](configuration/panel-config.md)
- [Frontend Paths](configuration/frontend-paths.md)
- [Middleware Registration](configuration/middleware.md)
- [Route Registration](configuration/routes.md)
- [Guest Redirect](configuration/guest-redirect.md)
- [Home Redirect](configuration/home-redirect.md)
- [Migration Loading](configuration/migrations.md)
- [Environment Variables](configuration/environment.md)
- [Service Provider Behavior](configuration/service-provider.md)

### Testing

The helpers, and what is worth asserting about a panel.

- [Test Setup](testing/setup.md)
- [Testing Helpers](testing/helpers.md)
- [Testing Tables](testing/tables.md)
- [Testing Forms](testing/forms.md)
- [Testing Actions](testing/actions.md)
- [Testing Notifications](testing/notifications.md)
- [Testing Tenancy](testing/tenancy.md)
- [Testing Authorization](testing/authorization.md)
- [Negative Security Tests](testing/negative-security-tests.md)
- [Frontend Contract Tests](testing/frontend-contract-tests.md)
- [CI Matrix](testing/ci-matrix.md)

### API reference

The public classes, grouped by subsystem.

- [Core API Reference](api/core.md)
- [Contracts Reference](api/contracts.md)
- [Resources Reference](api/resources.md)
- [Pages Reference](api/pages.md)
- [Forms Reference](api/forms.md)
- [Tables Reference](api/tables.md)
- [Actions Reference](api/actions.md)
- [Widgets](api/widgets.md)
- [Infolists Reference](api/infolists.md)
- [Notifications Reference](api/notifications.md)
- [Tenancy](api/tenancy.md)
- [Plugins Reference](api/plugins.md)
- [Events, Jobs and Controllers Reference](api/events-jobs-controllers.md)
- [Exceptions Reference](api/exceptions.md)

## Running it in production

### Deployment and operations

Caching, queues, Octane, storage, monitoring, rollbacks.

- [Production Checklist](deployment/production-checklist.md)
- [Composer and Autoloading](deployment/composer.md)
- [Frontend Build](deployment/frontend-build.md)
- [Route Cache](deployment/route-cache.md)
- [Config Cache](deployment/config-cache.md)
- [Panel Cache in Production](deployment/panel-cache.md)
- [Icon Registry](deployment/icon-registry.md)
- [Queues](deployment/queues.md)
- [Broadcasting Server](deployment/broadcasting.md)
- [Storage Setup](deployment/storage.md)
- [Octane](deployment/octane.md)
- [Rollbacks](deployment/rollbacks.md)
- [Monitoring](deployment/monitoring.md)

### Upgrading

What the version number promises, what breaks, and the edit that fixes it.

- [Versioning Policy](upgrading/versioning.md)
- [Upgrade Guide](upgrading/upgrade-guide.md)
- [Breaking Changes](upgrading/breaking-changes.md)
- [Asset Manifest](upgrading/asset-manifest.md)
- [Resolving Asset Conflicts](upgrading/asset-conflicts.md)
- [Migrating Package Names](upgrading/package-name-migration.md)
- [Changelog](upgrading/changelog.md)
- [Release Checklist](upgrading/release-checklist.md)

### Troubleshooting

Symptom-first pages for the failures that actually happen.

- [Installing from Packagist](troubleshooting/packagist.md)
- [Inertia root view and middleware](troubleshooting/inertia-root-view.md)
- [Missing host modules](troubleshooting/host-modules.md)
- [Vite Build Errors](troubleshooting/vite.md)
- [Tailwind 4](troubleshooting/tailwind.md)
- [Login redirects](troubleshooting/login-redirects.md)
- [403 responses](troubleshooting/authorization-403.md)
- [Panel routes that 404](troubleshooting/panel-routes-404.md)
- [Icons that render nothing](troubleshooting/icons.md)
- [Asset conflicts](troubleshooting/asset-conflicts.md)
- [Upload Failures](troubleshooting/uploads.md)
- [Broadcast failures](troubleshooting/broadcasting.md)
- [Tenant scope leaks](troubleshooting/tenancy-scope-leaks.md)
- [Import and export failures](troubleshooting/import-export.md)

## Worked examples

### Recipes

Whole features built end to end.

- [Admin Panel Example](recipes/admin-panel.md)
- [App Panel Example](recipes/app-panel.md)
- [User Resource](recipes/user-resource.md)
- [Product Resource](recipes/product-resource.md)
- [Relation Manager](recipes/relation-manager.md)
- [Tenant Panel](recipes/tenant-panel.md)
- [Import and Export](recipes/import-export.md)
- [Custom Widget](recipes/custom-widget.md)
- [Custom Field](recipes/custom-field.md)
- [Plugin](recipes/plugin.md)
- [Locking a Panel Down](recipes/security.md)

### Contributing

Working on the package itself.

- [Local Development](contributing/local-development.md)
- [Running the Tests](contributing/testing.md)
- [Frontend Toolchain](contributing/frontend-toolchain.md)
- [Coding Standards](contributing/coding-standards.md)
- [Architecture Decisions](contributing/architecture-decisions.md)
- [ADR 001 — A panel framework on Laravel, Inertia, and Vue](contributing/adr/001-panel-framework.md)
- [Pull Requests](contributing/pull-requests.md)
- [Releases](contributing/releases.md)
- [Security](contributing/security.md)

---

348 pages. [`sidebar.md`](sidebar.md) is the same tree collapsed to one entry per section, for
a navigation bar. [`framework-docs.md`](framework-docs.md) is the internal blueprint this structure
was built from — it is a planning document, not a reference.
