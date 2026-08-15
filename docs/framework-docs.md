# Panda Panel — Framework Documentation Structure

> Blueprint struktur dokumentasi untuk Panda Panel, sebuah Laravel panel framework berbasis Inertia + Vue.
>
> Tujuan dokumen ini adalah memecah dokumentasi yang sebelumnya terlalu monolitik menjadi struktur yang lebih mudah dipelajari, dipelihara, dicari, dan dikembangkan.

---

## Documentation Audit Summary

Dokumentasi Panda Panel saat ini sudah memiliki fondasi yang kuat, tetapi beberapa dokumen utama masih menampung terlalu banyak konsep sekaligus.

Dokumen seperti:

- `README.md`
- `docs/panel-framework.md`

sebaiknya tidak menjadi tempat seluruh dokumentasi teknis framework.

Untuk framework sebesar Panda Panel, dokumentasi perlu dipisahkan menjadi beberapa kategori utama:

- onboarding;
- core concepts;
- feature guides;
- API reference;
- configuration;
- deployment dan operations;
- troubleshooting;
- examples/cookbook;
- contributing.

Struktur dokumentasi di bawah ini menjadi acuan utama untuk pengembangan dokumentasi selanjutnya.

---

## Important Pre-Publish Audit

Sebelum dokumentasi installation dipublikasikan secara resmi, audit kembali distribusi Composer package.

Installer Panda Panel membaca `package.json` untuk menentukan dependency frontend. Jika `.gitattributes` masih memiliki:

```text
/package.json export-ignore
```

atau rule lain yang membuat `package.json` tidak masuk ke Composer distribution archive, maka:

- dokumentasi installation dapat terlihat benar;
- package dapat terpasang melalui Composer;
- tetapi `panel:install` berpotensi kehilangan sumber informasi dependency frontend.

Pastikan file yang dibutuhkan installer tersedia pada Composer `dist`.

Audit minimal:

```bash
git archive HEAD | tar -t | grep package.json
```

dan periksa `.gitattributes` sebelum release.

---

# Documentation Menu

## 1. Introduction

- Overview
- Why Panda Panel
- Feature Overview
- Architecture at a Glance
- Inertia + Vue Approach
- Comparison With Filament Concepts
- Package Limits and Tradeoffs

### Recommended Files

```text
docs/
└── introduction/
    ├── overview.md
    ├── why-panda-panel.md
    ├── features.md
    ├── architecture.md
    ├── inertia-vue.md
    ├── filament-comparison.md
    └── tradeoffs.md
```

---

## 2. Getting Started

- Requirements
- Compatibility Matrix
- Installation
- Laravel Vue Starter Kit Setup
- Frontend Requirements
- Running `panel:install`
- Creating First User
- Opening Your First Panel
- Directory Structure
- Upgrade From Old Package Names
- Common Install Problems

### Recommended Files

```text
docs/
└── getting-started/
    ├── requirements.md
    ├── compatibility.md
    ├── installation.md
    ├── vue-starter-kit.md
    ├── frontend-requirements.md
    ├── installer.md
    ├── first-user.md
    ├── first-panel.md
    ├── directory-structure.md
    ├── package-name-migration.md
    └── common-install-problems.md
```

---

## 3. Core Concepts

- Panels
- Panel Providers
- Request Lifecycle
- Panel Context
- Server Metadata to Vue
- Discovery vs Explicit Registration
- Routing Model
- Authorization Model
- Published Frontend Assets
- Build-Time Component Registries
- Cache Philosophy

### Recommended Files

```text
docs/
└── concepts/
    ├── panels.md
    ├── panel-providers.md
    ├── request-lifecycle.md
    ├── panel-context.md
    ├── metadata-to-vue.md
    ├── discovery.md
    ├── routing.md
    ├── authorization.md
    ├── frontend-assets.md
    ├── component-registries.md
    └── caching.md
```

---

## 4. Panels

- Defining a Panel
- Panel IDs, Paths, and Domains
- Multi-Panel Applications
- Middleware and Guards
- Panel Access Rules
- Branding, Logo, Icon, Favicon
- Sidebar and Header Layouts
- Navigation Groups
- Panel Switcher
- Dashboards
- Settings Pages
- Render Hooks
- Panel Assets
- Panel Cache
- Panel API Reference

### Recommended Files

```text
docs/
└── panels/
    ├── defining-panels.md
    ├── ids-paths-domains.md
    ├── multi-panel.md
    ├── middleware.md
    ├── access.md
    ├── branding.md
    ├── layouts.md
    ├── navigation-groups.md
    ├── panel-switcher.md
    ├── dashboards.md
    ├── settings-pages.md
    ├── render-hooks.md
    ├── assets.md
    ├── cache.md
    └── api.md
```

---

## 5. Resources

- Creating Resources
- Resource Directory Convention
- Model Binding
- List/Create/View/Edit Pages
- Resource Pages
- Resource Queries
- Labels and Navigation
- URLs and Route Names
- Soft Deletes
- Singular Resources
- Nested Resources
- Resource Configuration Per Panel
- Lifecycle Hooks
- Resource Authorization
- Global Search Integration
- Resource API Reference

### Recommended Files

```text
docs/
└── resources/
    ├── creating-resources.md
    ├── directory-convention.md
    ├── model-binding.md
    ├── crud-pages.md
    ├── resource-pages.md
    ├── queries.md
    ├── labels-navigation.md
    ├── urls-routes.md
    ├── soft-deletes.md
    ├── singular-resources.md
    ├── nested-resources.md
    ├── per-panel-configuration.md
    ├── lifecycle-hooks.md
    ├── authorization.md
    ├── global-search.md
    └── api.md
```

---

## 6. Forms and Schemas

- FormSchema Basics
- Field State Lifecycle
- Hydration and Dehydration
- Live Fields
- Validation
- Conditional Visibility
- Disabled and Hidden Fields
- Relationship Forms
- File Uploads
- Options Endpoints
- Layouts: Section, Grid, Tabs, Wizard
- Prime Components
- Custom Fields
- Field Reference:
  - Text
  - Number
  - Select
  - Checkbox
  - Toggle
  - Radio
  - Date
  - File Upload
  - Repeater
  - Builder
  - Rich Editor
  - Markdown
  - Tags
  - Key Value
  - Color
  - Slider
  - Code Editor

### Recommended Files

```text
docs/
└── forms/
    ├── overview.md
    ├── state-lifecycle.md
    ├── hydration.md
    ├── live-fields.md
    ├── validation.md
    ├── visibility.md
    ├── disabled-hidden.md
    ├── relationships.md
    ├── file-uploads.md
    ├── options-endpoints.md
    ├── layouts.md
    ├── prime-components.md
    ├── custom-fields.md
    └── fields/
        ├── text.md
        ├── number.md
        ├── select.md
        ├── checkbox.md
        ├── toggle.md
        ├── radio.md
        ├── date.md
        ├── file-upload.md
        ├── repeater.md
        ├── builder.md
        ├── rich-editor.md
        ├── markdown.md
        ├── tags.md
        ├── key-value.md
        ├── color.md
        ├── slider.md
        └── code-editor.md
```

---

## 7. Tables

- TableSchema Basics
- Columns
- Search
- Sort
- Filters
- Query Builder Filters
- Tabs
- Grouping
- Summaries
- Pagination
- Persisted Table State
- Column Manager
- Frozen/Pinned Columns
- Editable Columns
- Record Actions
- Bulk Actions
- Header and Toolbar Actions
- Reordering
- Relationship Columns
- Array Data Tables
- Table API Reference

### Recommended Files

```text
docs/
└── tables/
    ├── overview.md
    ├── columns.md
    ├── search.md
    ├── sorting.md
    ├── filters.md
    ├── query-builder.md
    ├── tabs.md
    ├── grouping.md
    ├── summaries.md
    ├── pagination.md
    ├── persisted-state.md
    ├── column-manager.md
    ├── pinned-columns.md
    ├── editable-columns.md
    ├── record-actions.md
    ├── bulk-actions.md
    ├── toolbar-actions.md
    ├── reordering.md
    ├── relationships.md
    ├── array-data.md
    └── api.md
```

---

## 8. Actions

- Action Basics
- Action Scopes
- Row Actions
- Table Actions
- Bulk Actions
- Infolist Actions
- Action Authorization
- Action Modals
- Action Forms
- Transactions
- Built-In Actions
- Create/Edit/View/Delete
- Replicate
- Restore and Force Delete
- Import and Export Actions
- Relation Actions
- Custom Actions

### Recommended Files

```text
docs/
└── actions/
    ├── overview.md
    ├── scopes.md
    ├── row-actions.md
    ├── table-actions.md
    ├── bulk-actions.md
    ├── infolist-actions.md
    ├── authorization.md
    ├── modals.md
    ├── forms.md
    ├── transactions.md
    ├── built-in-actions.md
    ├── crud-actions.md
    ├── replicate.md
    ├── restore-force-delete.md
    ├── import-export.md
    ├── relation-actions.md
    └── custom-actions.md
```

---

## 9. Relations

- Relation Managers
- Relation Pages
- Relation Tables
- Relation Forms
- Attach and Detach
- Associate and Dissociate
- Pivot Fields
- Related Record Policies
- Soft Deleted Relations
- Nested Resource vs Relation Manager

### Recommended Files

```text
docs/
└── relations/
    ├── relation-managers.md
    ├── relation-pages.md
    ├── relation-tables.md
    ├── relation-forms.md
    ├── attach-detach.md
    ├── associate-dissociate.md
    ├── pivot-fields.md
    ├── policies.md
    ├── soft-deletes.md
    └── nested-vs-relation-manager.md
```

---

## 10. Infolists

- InfolistSchema Basics
- Entries
- Layouts
- Repeatable Entries
- Custom Entries
- Actions in Infolists
- Entry Reference

### Recommended Files

```text
docs/
└── infolists/
    ├── overview.md
    ├── entries.md
    ├── layouts.md
    ├── repeatable-entries.md
    ├── custom-entries.md
    ├── actions.md
    └── entry-reference.md
```

---

## 11. Pages and Navigation

- Custom Pages
- Page Discovery
- Page Authorization
- Page Headings
- Breadcrumbs
- Sub Navigation
- Clusters
- Full Page URLs
- Prefetching
- Error Notifications

### Recommended Files

```text
docs/
└── pages-navigation/
    ├── custom-pages.md
    ├── discovery.md
    ├── authorization.md
    ├── headings.md
    ├── breadcrumbs.md
    ├── sub-navigation.md
    ├── clusters.md
    ├── urls.md
    ├── prefetching.md
    └── error-notifications.md
```

---

## 12. Widgets

- Widget Basics
- Stats Widgets
- Chart Widgets
- Table Widgets
- Custom Vue Widgets
- Widget Filters
- Lazy Widgets
- Polling
- Authorization
- Column Span and Layout

### Recommended Files

```text
docs/
└── widgets/
    ├── overview.md
    ├── stats.md
    ├── charts.md
    ├── tables.md
    ├── custom-vue.md
    ├── filters.md
    ├── lazy-loading.md
    ├── polling.md
    ├── authorization.md
    └── layout.md
```

---

## 13. Authentication and Users

- Fortify Integration
- Login
- Registration
- Password Reset
- Email Verification
- `panel:user`
- User Model Requirements
- `PanelUser` Contract
- Profile Settings
- Security Settings
- Appearance Settings
- Two-Factor Authentication
- Email Code Challenge
- Passkeys

### Recommended Files

```text
docs/
└── authentication/
    ├── fortify.md
    ├── login.md
    ├── registration.md
    ├── password-reset.md
    ├── email-verification.md
    ├── panel-user-command.md
    ├── user-model.md
    ├── panel-user-contract.md
    ├── profile.md
    ├── security.md
    ├── appearance.md
    ├── two-factor.md
    ├── email-code-challenge.md
    └── passkeys.md
```

---

## 14. Tenancy

- Tenancy Concepts
- Tenant Resolver
- `HasPanelTenants`
- `PanelTenant`
- Tenant Switcher
- Tenant URLs
- Resource Tenant Scoping
- Single Database Tenancy
- Database Per Tenant
- Using With `stancl/tenancy`
- Queues and Tenant Context
- Tenancy Security Checklist

### Recommended Files

```text
docs/
└── tenancy/
    ├── concepts.md
    ├── resolver.md
    ├── has-panel-tenants.md
    ├── panel-tenant.md
    ├── switcher.md
    ├── urls.md
    ├── resource-scoping.md
    ├── single-database.md
    ├── database-per-tenant.md
    ├── stancl-tenancy.md
    ├── queues.md
    └── security-checklist.md
```

---

## 15. Notifications and Broadcasting

- Toast Notifications
- Flash Toast Bridge
- Database Notifications
- Notification Center
- Notification Actions
- Broadcasting
- Reverb and Echo Setup
- Channel Authorization
- Queued Notifications
- Testing Notifications

### Recommended Files

```text
docs/
└── notifications/
    ├── toast.md
    ├── flash-bridge.md
    ├── database.md
    ├── notification-center.md
    ├── actions.md
    ├── broadcasting.md
    ├── reverb-echo.md
    ├── channel-authorization.md
    ├── queues.md
    └── testing.md
```

---

## 16. Search

- Global Search Overview
- Searchable Resources
- Search Attributes
- Relationship Search
- Search Result Details
- Search Result URLs
- Panel Search Configuration
- Search Security

### Recommended Files

```text
docs/
└── search/
    ├── overview.md
    ├── searchable-resources.md
    ├── attributes.md
    ├── relationships.md
    ├── result-details.md
    ├── result-urls.md
    ├── panel-configuration.md
    └── security.md
```

---

## 17. Import and Export

- ExportAction
- ImportAction
- Exporter Classes
- Importer Classes
- Columns and Mapping
- CSV and XLSX
- Queued Imports
- Queued Exports
- Failure Reports
- Storage and Cleanup
- Notifications

### Recommended Files

```text
docs/
└── import-export/
    ├── export-action.md
    ├── import-action.md
    ├── exporters.md
    ├── importers.md
    ├── columns-mapping.md
    ├── csv-xlsx.md
    ├── queued-imports.md
    ├── queued-exports.md
    ├── failure-reports.md
    ├── storage-cleanup.md
    └── notifications.md
```

---

## 18. Frontend Customization

- Published Asset Structure
- Vue Component Tree
- Inertia Pages
- Host Modules
- Wayfinder Routes
- Custom Page Components
- Custom Columns
- Custom Fields
- Custom Widgets
- Custom Shell Components
- Icons
- Tailwind Theme
- CSS Hooks
- Updating Published Assets

### Recommended Files

```text
docs/
└── frontend/
    ├── assets.md
    ├── component-tree.md
    ├── inertia-pages.md
    ├── host-modules.md
    ├── wayfinder.md
    ├── custom-pages.md
    ├── custom-columns.md
    ├── custom-fields.md
    ├── custom-widgets.md
    ├── custom-shell.md
    ├── icons.md
    ├── tailwind-theme.md
    ├── css-hooks.md
    └── updating-assets.md
```

---

## 19. Plugins

- Plugin Concepts
- Creating a Plugin
- Plugin Contract
- Register and Boot
- Plugin Metadata
- Version Compatibility
- Publishing Plugin Assets
- Plugin CLI
- Testing Plugins

### Recommended Files

```text
docs/
└── plugins/
    ├── concepts.md
    ├── creating-plugins.md
    ├── contract.md
    ├── lifecycle.md
    ├── metadata.md
    ├── compatibility.md
    ├── assets.md
    ├── cli.md
    └── testing.md
```

---

## 20. CLI Reference

- `panel:install`
- `panel:user`
- `make:panel`
- `make:panel-resource`
- `make:panel-page`
- `make:panel-widget`
- `make:panel-relation-manager`
- `panel:cache`
- `panel:clear`
- `panel:icons`
- `panel:assets`
- `panel:plugins`
- Publish Tags

### Recommended Files

```text
docs/
└── cli/
    ├── panel-install.md
    ├── panel-user.md
    ├── make-panel.md
    ├── make-panel-resource.md
    ├── make-panel-page.md
    ├── make-panel-widget.md
    ├── make-panel-relation-manager.md
    ├── panel-cache.md
    ├── panel-clear.md
    ├── panel-icons.md
    ├── panel-assets.md
    ├── panel-plugins.md
    └── publish-tags.md
```

---

## 21. Configuration Reference

- `config/panda-panel.php`
- Panel Config
- Frontend Paths
- Middleware Registration
- Route Registration
- Guest Redirect
- Home Redirect
- Migration Loading
- Environment Variables
- Service Provider Behavior

### Recommended Files

```text
docs/
└── configuration/
    ├── panda-panel.md
    ├── panel-config.md
    ├── frontend-paths.md
    ├── middleware.md
    ├── routes.md
    ├── guest-redirect.md
    ├── home-redirect.md
    ├── migrations.md
    ├── environment.md
    └── service-provider.md
```

---

## 22. Testing

- Test Setup
- Testing Helpers
- Testing Tables
- Testing Forms
- Testing Actions
- Testing Notifications
- Testing Tenancy
- Testing Authorization
- Negative Security Tests
- Frontend Contract Tests
- CI Matrix

### Recommended Files

```text
docs/
└── testing/
    ├── setup.md
    ├── helpers.md
    ├── tables.md
    ├── forms.md
    ├── actions.md
    ├── notifications.md
    ├── tenancy.md
    ├── authorization.md
    ├── negative-security-tests.md
    ├── frontend-contract-tests.md
    └── ci-matrix.md
```

---

## 23. Deployment and Operations

- Production Checklist
- Composer Install
- NPM Build
- Route Cache
- Config Cache
- Panel Cache
- Icon Registry
- Queue Workers
- Broadcasting Server
- Storage Setup
- Octane
- Rollbacks
- Monitoring

### Recommended Files

```text
docs/
└── deployment/
    ├── production-checklist.md
    ├── composer.md
    ├── frontend-build.md
    ├── route-cache.md
    ├── config-cache.md
    ├── panel-cache.md
    ├── icon-registry.md
    ├── queues.md
    ├── broadcasting.md
    ├── storage.md
    ├── octane.md
    ├── rollbacks.md
    └── monitoring.md
```

---

## 24. Upgrading

- Versioning Policy
- Upgrade Guide
- Breaking Changes
- Asset Manifest
- Resolving Asset Conflicts
- Migrating Package Names
- Changelog
- Release Checklist

### Recommended Files

```text
docs/
└── upgrading/
    ├── versioning.md
    ├── upgrade-guide.md
    ├── breaking-changes.md
    ├── asset-manifest.md
    ├── asset-conflicts.md
    ├── package-name-migration.md
    ├── changelog.md
    └── release-checklist.md
```

---

## 25. Troubleshooting

- Packagist Install Errors
- Missing Inertia Root View
- Missing Host Modules
- Vite Build Errors
- Tailwind 4 Issues
- Login Redirect Loops
- 403 Authorization
- 404 Panel Routes
- Missing Icons
- Asset Conflicts
- Upload Failures
- Broadcast Failures
- Tenancy Scope Leaks
- Import/Export Failures

### Recommended Files

```text
docs/
└── troubleshooting/
    ├── packagist.md
    ├── inertia-root-view.md
    ├── host-modules.md
    ├── vite.md
    ├── tailwind.md
    ├── login-redirects.md
    ├── authorization-403.md
    ├── panel-routes-404.md
    ├── icons.md
    ├── asset-conflicts.md
    ├── uploads.md
    ├── broadcasting.md
    ├── tenancy-scope-leaks.md
    └── import-export.md
```

---

## 26. Examples and Recipes

- Admin Panel Example
- App Panel Example
- User Resource
- Product Resource
- Relation Manager Example
- Tenant Panel Example
- Import/Export Example
- Custom Widget Example
- Custom Field Example
- Plugin Example
- Security Recipes

### Recommended Files

```text
docs/
└── recipes/
    ├── admin-panel.md
    ├── app-panel.md
    ├── user-resource.md
    ├── product-resource.md
    ├── relation-manager.md
    ├── tenant-panel.md
    ├── import-export.md
    ├── custom-widget.md
    ├── custom-field.md
    ├── plugin.md
    └── security.md
```

---

## 27. API Reference

- Core Classes
- Contracts
- Resources
- Pages
- Forms
- Tables
- Actions
- Widgets
- Infolists
- Notifications
- Tenancy
- Plugins
- Events, Jobs, Controllers
- Exceptions

### Recommended Files

```text
docs/
└── api/
    ├── core.md
    ├── contracts.md
    ├── resources.md
    ├── pages.md
    ├── forms.md
    ├── tables.md
    ├── actions.md
    ├── widgets.md
    ├── infolists.md
    ├── notifications.md
    ├── tenancy.md
    ├── plugins.md
    ├── events-jobs-controllers.md
    └── exceptions.md
```

---

## 28. Contributing

- Local Development
- Running Tests
- Frontend Toolchain
- Coding Standards
- Architecture Decisions
- Pull Request Checklist
- Release Process
- Security Policy

### Recommended Files

```text
docs/
└── contributing/
    ├── local-development.md
    ├── testing.md
    ├── frontend-toolchain.md
    ├── coding-standards.md
    ├── architecture-decisions.md
    ├── pull-requests.md
    ├── releases.md
    └── security.md
```

---

# Recommended Sidebar Hierarchy

Untuk dokumentasi berbasis VitePress, Docusaurus, Mintlify, atau platform sejenis, sidebar utama sebaiknya mengikuti struktur berikut:

```text
Introduction
Getting Started

Core Concepts

Panels
Resources
Forms and Schemas
Tables
Actions
Relations
Infolists
Pages and Navigation
Widgets

Authentication and Users
Tenancy
Notifications and Broadcasting
Search
Import and Export

Frontend Customization
Plugins

CLI Reference
Configuration Reference
Testing

Deployment and Operations
Upgrading
Troubleshooting

Examples and Recipes
API Reference
Contributing
```

Struktur ini lebih baik daripada menampilkan 28 kategori dengan bobot visual yang sama, karena developer baru akan lebih cepat memahami alur belajar.

---

# Documentation Learning Path

## Beginner

Urutan belajar untuk pengguna baru:

```text
Introduction
    ↓
Getting Started
    ↓
Core Concepts
    ↓
Panels
    ↓
Resources
    ↓
Forms
    ↓
Tables
    ↓
Actions
```

Target setelah menyelesaikan jalur ini:

> Developer mampu meng-install Panda Panel dan membuat CRUD production-ready pertama.

---

## Intermediate

```text
Relations
    ↓
Infolists
    ↓
Pages
    ↓
Widgets
    ↓
Authentication
    ↓
Search
    ↓
Notifications
```

Target:

> Developer mampu membangun aplikasi back-office lengkap.

---

## Advanced

```text
Tenancy
    ↓
Import / Export
    ↓
Frontend Customization
    ↓
Plugins
    ↓
Testing
    ↓
Deployment
```

Target:

> Developer mampu membangun aplikasi SaaS, ERP, plugin, dan extension Panda Panel.

---

# Documentation Writing Priority

Prioritas penulisan dokumentasi sebaiknya tidak mengikuti nomor menu secara kaku.

Urutan yang paling memberi dampak untuk developer baru:

## Priority 0 — Release Safety

Selesaikan sebelum dokumentasi installation dianggap final:

1. audit Composer `dist`;
2. audit `.gitattributes`;
3. pastikan `package.json` tersedia untuk installer;
4. test `composer require` dari archive/distribution;
5. test `panel:install` pada fresh Laravel application.

---

## Priority 1 — Critical Onboarding

1. Getting Started
2. Installation
3. Requirements
4. Compatibility Matrix
5. Running `panel:install`
6. Creating First User
7. Opening First Panel
8. Common Install Problems

---

## Priority 2 — Core Developer Experience

1. Core Concepts
2. Panels
3. Resources
4. Forms and Schemas
5. Tables
6. Actions

Setelah bagian ini selesai, developer harus bisa membangun CRUD tanpa membaca source code framework.

---

## Priority 3 — Customization

1. Frontend Customization
2. Widgets
3. Pages and Navigation
4. Relations
5. Infolists
6. Notifications
7. Search

---

## Priority 4 — Production Features

1. Authentication
2. Tenancy
3. Import and Export
4. Testing
5. Deployment and Operations
6. Upgrading

---

## Priority 5 — Ecosystem

1. Plugins
2. API Reference
3. Examples and Recipes
4. Contributing

---

# Documentation Quality Rules

Setiap halaman dokumentasi feature sebaiknya mengikuti pola:

```text
Title

Short explanation

When to use it

Basic example

Common configuration

Advanced example

Authorization / security considerations

Frontend behavior, if relevant

Testing

Related documentation
```

---

## Code Examples

Contoh harus:

- copy-pasteable;
- menggunakan API Panda Panel aktual;
- menggunakan namespace aktual;
- menyebut requirement bila feature optional;
- menghindari pseudo-code jika dokumentasi mengklaim contoh runnable;
- menunjukkan import yang dibutuhkan;
- tidak menggunakan API yang belum dirilis tanpa label experimental.

---

## Version Awareness

Setiap feature baru sebaiknya dapat diberi label:

```text
Added in v0.2
Experimental
Deprecated
Breaking in v1.0
```

Jangan membuat pengguna menebak apakah contoh berlaku pada versi package mereka.

---

## Security Documentation

Feature berikut wajib mempunyai bagian security jika relevan:

- authorization;
- tenancy;
- file uploads;
- import/export;
- custom component rendering;
- broadcasting;
- authentication;
- passkeys;
- plugin execution;
- frontend metadata;
- resource scoping.

---

# README Responsibility

`README.md` sebaiknya tetap pendek.

Isi ideal:

```text
Panda Panel
Short pitch

Feature highlights

Requirements

Quick installation

30-second example

Screenshot

Documentation link

Contributing

License
```

README bukan full framework manual.

---

# `docs/panel-framework.md` Responsibility

Dokumen lama `docs/panel-framework.md` sebaiknya secara bertahap diubah menjadi salah satu dari dua pilihan:

## Option A — Architecture Overview

Jadikan:

```text
docs/architecture.md
```

yang menjelaskan:

- core architecture;
- backend/frontend boundary;
- Inertia metadata pipeline;
- registries;
- routing;
- discovery;
- cache;
- plugin architecture.

## Option B — Documentation Index

Jadikan:

```text
docs/index.md
```

yang menjadi entry point menuju dokumentasi modular.

Jangan terus menambahkan semua feature baru ke satu file tersebut.

---

# Suggested Final Documentation Tree

```text
docs/
├── index.md
│
├── introduction/
├── getting-started/
├── concepts/
│
├── panels/
├── resources/
├── forms/
├── tables/
├── actions/
├── relations/
├── infolists/
├── pages-navigation/
├── widgets/
│
├── authentication/
├── tenancy/
├── notifications/
├── search/
├── import-export/
│
├── frontend/
├── plugins/
│
├── cli/
├── configuration/
├── testing/
│
├── deployment/
├── upgrading/
├── troubleshooting/
│
├── recipes/
├── api/
└── contributing/
```

---

# Documentation Definition of Done

Dokumentasi Panda Panel dapat dianggap matang untuk public usage ketika:

- pengguna baru dapat install package tanpa membaca source code;
- fresh install guide benar-benar diuji;
- compatibility matrix tersedia;
- first panel dapat dibuat dari dokumentasi;
- first resource dapat dibuat dari dokumentasi;
- form dan table mempunyai reference yang jelas;
- authorization dijelaskan;
- frontend customization dijelaskan;
- troubleshooting mencakup error installation paling umum;
- package upgrade path terdokumentasi;
- API yang dianggap public dapat dibedakan dari internal API;
- example code diuji terhadap versi package yang dirilis;
- links antar halaman tidak rusak;
- version-specific behavior tidak ambigu;
- documentation navigation mengikuti struktur modular ini.

---

# Immediate Writing Backlog

Mulai dokumentasi dengan urutan berikut:

1. `getting-started/requirements.md`
2. `getting-started/compatibility.md`
3. `getting-started/installation.md`
4. `getting-started/installer.md`
5. `getting-started/first-user.md`
6. `getting-started/first-panel.md`
7. `concepts/panels.md`
8. `concepts/metadata-to-vue.md`
9. `panels/defining-panels.md`
10. `resources/creating-resources.md`
11. `forms/overview.md`
12. `tables/overview.md`
13. `actions/overview.md`
14. `frontend/assets.md`
15. `frontend/custom-pages.md`
16. `troubleshooting/packagist.md`
17. `troubleshooting/vite.md`
18. `troubleshooting/authorization-403.md`
19. `troubleshooting/panel-routes-404.md`

Setelah backlog tersebut selesai, Panda Panel sudah mempunyai dokumentasi minimum yang jauh lebih siap digunakan oleh developer eksternal.
