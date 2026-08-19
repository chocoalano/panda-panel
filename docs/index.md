---
layout: home
titleTemplate: false

hero:
  name: Panda Panel
  text: Admin panels for Laravel, driven from PHP
  tagline: >-
    Resources, forms, tables, actions and dashboards defined in PHP and rendered by Vue.
    Built on Laravel 12 and 13, Inertia 3, Vue 3 and Tailwind 4 — with no REST layer to keep in sync.
  actions:
    - theme: brand
      text: Start the tutorial
      link: /tutorial/
    - theme: alt
      text: Installation
      link: /getting-started/installation
    - theme: alt
      text: Baca dalam Bahasa Indonesia
      link: /id/

features:
  - icon: '<svg class="lucide lucide-layout-grid" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><rect width="7" height="7" x="3" y="3" rx="1" /><rect width="7" height="7" x="14" y="3" rx="1" /><rect width="7" height="7" x="14" y="14" rx="1" /><rect width="7" height="7" x="3" y="14" rx="1" /></svg>'
    title: Panels, not just pages
    details: >-
      A panel is a path, a navigation tree, a middleware stack and an access rule. Run one, or run
      an admin panel and a customer panel side by side, each with its own shell.
    link: /concepts/panels
    linkText: Core concepts
  - icon: '<svg class="lucide lucide-server" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><rect width="20" height="8" x="2" y="2" rx="2" ry="2" /><rect width="20" height="8" x="2" y="14" rx="2" ry="2" /><line x1="6" x2="6.01" y1="6" y2="6" /><line x1="6" x2="6.01" y1="18" y2="18" /></svg>'
    title: Schemas on the server
    details: >-
      FormSchema, TableSchema and InfolistSchema live in PHP. Validation, queries, sorting,
      filtering and authorization run where the data is — Vue only renders what it is handed.
    link: /forms/overview
    linkText: Forms and schemas
  - icon: '<svg class="lucide lucide-puzzle" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><path d="M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z" /></svg>'
    title: Vue you can actually open
    details: >-
      The frontend is published into your app, not hidden in vendor. Swap a column, a field, a page
      or the whole shell, and keep upgrading the PHP side.
    link: /frontend/component-tree
    linkText: Frontend customization
  - icon: '<svg class="lucide lucide-shield-check" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /><path d="m9 12 2 2 4-4" /></svg>'
    title: Authorization at every layer
    details: >-
      Panels, resources, pages, actions and widgets each ask a policy before they render or run.
      Negative security tests ship with the framework.
    link: /concepts/authorization
    linkText: Authorization
  - icon: '<svg class="lucide lucide-building-2" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><path d="M10 12h4" /><path d="M10 8h4" /><path d="M14 21v-3a2 2 0 0 0-4 0v3" /><path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2" /><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16" /></svg>'
    title: Tenancy that scopes queries
    details: >-
      Single database or database per tenant, a tenant switcher in the shell, scoped resources,
      tenant-aware queues, and a checklist for the leaks that matter.
    link: /tenancy/concepts
    linkText: Tenancy
  - icon: '<svg class="lucide lucide-terminal" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ><path d="M12 19h8" /><path d="m4 17 6-6-6-6" /></svg>'
    title: Artisan does the typing
    details: >-
      panel:install scaffolds the first panel; make:panel-resource, make:panel-page and
      make:panel-widget write the rest. Caches for production, clears for development.
    link: /cli/panel-install
    linkText: CLI reference
---
