# 8 · Go to production

**Goal:** a deploy that produces the same panel on a server that it produces on your machine — and a
security posture that is not `return true`.

## First, close the door you left open

Step 5 gave `ProductPolicy` six methods that all return `true`. That was fine for a tutorial and is
not fine for anything else. Every panel screen delegates to Laravel's Gate, so the policy **is** the
rule:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->is_admin && $product->status !== 'archived';
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->is_admin && $product->status === 'draft';
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_admin;
    }
}
```

And give the panel its own access rule, which is the *other* half of the pair from step 4:

```php
// app/Panels/Admin/AdminPanelProvider.php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

return $panel
    ->path('admin')
    ->name('Admin')
    ->auth()
    ->canAccess(static fn (?Authenticatable $user): bool          // [!code ++]
        => $user instanceof User && $user->is_admin)              // [!code ++]
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

A signed-in user who is refused gets **403**, never a redirect. Hiding navigation is not access
control, and the panel does not pretend otherwise.

Prove it, rather than believing it:

```php
$this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
$this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
```

## The deploy

Five steps and one restart:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader

php artisan migrate --force

npm ci
npm run build

php artisan optimize          # config, routes, events, views — and panel:cache

php artisan queue:restart
```

### Why that is the order

| Step | Depends on the previous because |
| --- | --- |
| `composer install` | nothing — it is first |
| `migrate --force` | the migrations are the new code's |
| `npm ci && npm run build` | the components published into `resources/js` are the new code's too |
| `optimize` | `panel:cache` resolves class names through Composer's PSR-4 map, so a manifest written *before* `composer install` names classes that have moved |
| `queue:restart` | a worker started before the deploy is running the old code, including the old panel manifest |

Two of those are load-bearing enough to say twice: **cache after Composer, never before**, and
**restart the workers last** — a worker that restarts mid-deploy comes back holding half of it.

::: warning `panel:cache` freezes the class list
After it runs, discovery does not. A resource added afterwards has no route, no navigation entry and
no error. That is exactly what you want in production and exactly what you do not want locally, so:
`php artisan panel:clear` in development, `php artisan optimize` on deploy.
:::

## The checklist

| | Why |
| --- | --- |
| `.panel-assets.json` is committed | It records which version of the panel frontend this application published. Without it, an upgrade cannot tell your edits from a stale copy |
| `npm run build` runs on the server, or the built assets are shipped | Installing does not build. A panel that renders unstyled HTML is a build that never ran |
| Every model behind a resource has a policy | The gate is asked on every page. No policy means 403 |
| The panel has a `canAccess()` rule | Otherwise every authenticated user can enter |
| `php artisan optimize` runs after `composer install` | See above |
| `php artisan queue:restart` runs last | Queued exports, imports and notifications otherwise run old code |
| `--no-dev` is safe | Nothing in the package's `src/` reaches for a `require-dev` package |
| `ext-zip` exists on the server | XLSX import and export are ZIP containers. Composer fails at install rather than at the first export |

## What to verify after a deploy

```bash
php artisan route:list --name=panel.
php artisan panel:cache        # prints what it discovered, by count
```

The counts are the check. One panel, one resource, one widget for this tutorial — a zero where you
expected a number is a discovery path that no longer matches where the classes are.

## If it did not work

| Symptom | Cause | Fix |
| --- | --- | --- |
| A resource vanished from the sidebar after deploy | `panel:cache` ran before `composer install` | Re-run `php artisan optimize` |
| Icons render nothing | A key that is not in the build-time registry | `php artisan panel:icons`, then rebuild |
| Toasts never arrive | The queue workers were not restarted | `php artisan queue:restart` |
| Everything 403s | The new `canAccess()` reads a column nobody has set | Set `is_admin` on the operator account |
| Unstyled panel screens | `npm run build` did not run, or ran before the publish | Build after `composer install` |

## You are done

You have a panel with a resource, a table people can search, a form that validates, an action with a
confirmation, a dashboard that counts things, and a policy that means it. That is the whole loop —
everything else in this documentation is more of the same shapes.

### Where to go next

| If you want to… | Read |
| --- | --- |
| Understand what you just built | [Core concepts](/concepts/panels) — panels, discovery, the request lifecycle |
| Add related records to a product | [Relation managers](/relations/relation-managers) |
| Add a second panel for customers | [Multi-panel applications](/panels/multi-panel) |
| Scope everything to a tenant | [Tenancy concepts](/tenancy/concepts) |
| Write your own Vue column or field | [Frontend customization](/frontend/component-tree) |
| Export the table to CSV or XLSX | [Import and export](/import-export/export-action) |
| Test all of it | [Test setup](/testing/setup) and [helpers](/testing/helpers) |
| Read every page | [All 358 pages](/pages) |

## See also

- [Production checklist](/deployment/production-checklist) — the same deploy, with every reason
- [Panel cache in production](/deployment/panel-cache), [Route cache](/deployment/route-cache)
- [Authorization](/concepts/authorization) — the two checks, in full
- [Negative security tests](/testing/negative-security-tests) — asserting that the door is shut
