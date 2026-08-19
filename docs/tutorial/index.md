---
title: Tutorial
---

# Build your first panel

Eight steps, from an empty directory to an admin panel you could hand to somebody: a product
catalogue with a searchable table, a validated form, a publish action, and a dashboard that counts
things. Every command here is one you run yourself, and every file it writes is shown.

Allow about 45 minutes. Nothing in it is thrown away afterwards — the panel you finish with is the
one you keep building on.

::: tip Already have a Laravel application?
Skip to [step 3](install) and install into it. Step 2 exists because a Laravel Vue starter kit is
the shortest path to a working frontend, not because the package requires one.
:::

## What you will have built

| | |
| --- | --- |
| A panel at `/admin` | Its own path, navigation, middleware and access rule |
| A `Product` resource | List, create, view and edit pages, routed and in the sidebar |
| A table | Search, sort, a status filter, badge and currency columns |
| A form | Two-column layout, validation, a select and a date picker |
| An action | A one-click **Publish** button with a confirmation, on the rows it applies to |
| A widget | Three figures on the dashboard, with colours and an icon |
| A policy | Because a resource with no policy answers 403, on purpose |

## The eight steps

| Step | What happens | Time |
| --- | --- | --- |
| [1 · Prepare your environment](prepare) | Check PHP, Node, the extensions, the database | 5 min |
| [2 · Create the project](project) | A Laravel Vue starter kit application that runs | 5 min |
| [3 · Install Panda Panel](install) | `composer require`, `panel:install`, and reading its report | 10 min |
| [4 · First account, first login](first-login) | `panel:user`, and what the two access rules mean | 5 min |
| [5 · Your first resource](resource) | A model, a migration, a resource, and the policy it needs | 10 min |
| [6 · Shape the form and the table](form-and-table) | Real fields, real columns, filters and search | 10 min |
| [7 · Actions and dashboard widgets](actions-and-widgets) | A publish action and a stats widget | 10 min |
| [8 · Go to production](production) | Build, cache, and the checklist before you deploy | 5 min |

## How this tutorial is written

Every step follows the same shape, so you can skim one you already know:

- **Goal** — one sentence on what changes.
- **Do this** — the commands and the files, in order.
- **Check it worked** — something concrete to look at before moving on.
- **If it did not work** — the two or three things that actually go wrong at that step.

Commands assume you are in the project root. Code blocks that replace a file show the whole file;
blocks that change one part say so above the block.

## The mental model, in four sentences

You will meet these words on every page, so they are worth reading once now.

- A **panel** is a URL prefix with its own navigation, middleware and access rule. An application
  can have several — an admin panel and a customer panel, side by side.
- A **resource** is one Eloquent model presented inside one panel: its table, its form, its pages,
  and the single query all of them read through.
- A **schema** — `FormSchema`, `TableSchema`, `InfolistSchema` — is a PHP description of what
  renders. Validation, sorting, filtering and authorization run on the server; Vue renders what it
  is handed.
- An **action** is a backend operation the frontend can request by name. The button crosses the
  wire; the handler never does.

The full treatment of each is in [Core concepts](/concepts/panels) — but you do not need it to
finish this tutorial.

<div class="tutorial-next">

**Start here → [1 · Prepare your environment](prepare)**

</div>
