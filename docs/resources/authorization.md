# Resource Authorization

Every resource ability resolves to an ordinary Laravel policy through the Gate. There is no panel-specific permission system to learn: you write the policy you would have written anyway, and the panel asks it — on the route, before the write, and again before drawing a button. This page covers the abilities a resource asks, where each is checked, and what strict mode changes.

## The minimal setup

A policy for the model, registered the way Laravel registers policies:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

final class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_editor;
    }

    public function view(User $user, Post $post): bool
    {
        return $user->is_editor || $user->is($post->author);
    }

    public function create(User $user): bool
    {
        return $user->is_editor;
    }

    public function update(User $user, Post $post): bool
    {
        return $user->is_editor || $user->is($post->author);
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->is_editor;
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_editor;
    }
}
```

Nothing is declared on the resource. With that policy in place `/admin/posts` answers 403 for a non-editor, the sidebar entry disappears, the create button is absent, and a guessed record URL is refused by the same rule that hid the link.

## The abilities

| Resource method | Signature | Gate ability |
| --- | --- | --- |
| `canViewAny()` | `public static function canViewAny(): bool` | `viewAny` |
| `canView()` | `public static function canView(Model $record): bool` | `view` |
| `canCreate()` | `public static function canCreate(): bool` | `create` |
| `canEdit()` | `public static function canEdit(Model $record): bool` | `update` |
| `canDelete()` | `public static function canDelete(Model $record): bool` | `delete` |
| `canDeleteAny()` | `public static function canDeleteAny(): bool` | `deleteAny` |
| `canRestore()` | `public static function canRestore(Model $record): bool` | `restore` |
| `canRestoreAny()` | `public static function canRestoreAny(): bool` | `restoreAny` |
| `canForceDelete()` | `public static function canForceDelete(Model $record): bool` | `forceDelete` |
| `canForceDeleteAny()` | `public static function canForceDeleteAny(): bool` | `forceDeleteAny` |

`canEdit()` asks `update`, not `edit` — the panel speaks Laravel's vocabulary, so the same policy governs a console command, an API controller, and the panel alike.

Call them anywhere you need the answer:

```php
use App\Panels\Admin\Resources\Posts\PostResource;

if (PostResource::canEdit($post)) {
    // ...
}
```

## Where each one is checked

| Surface | Ability |
| --- | --- |
| `ListRecords::render()` | `canViewAny()`, 403 |
| `CreateRecord::render()` and `handle()` | `canCreate()`, 403 |
| `ViewRecord::render()` | `canView()` on the resolved record, 403 |
| `EditRecord::render()`, `handle()`, `validateStep()` | `canEdit()` on the resolved record, 403 |
| A custom page using `InteractsWithRecord` | `canView()` unless the page overrides `authorizeRecord()` |
| The sidebar entry | `canViewAny()` |
| The create button on a list page | `canCreate()` |
| The edit button on a view page | `canEdit()` |
| Record sub-navigation links | `canView()` / `canEdit()` per link |
| Global search | `canViewAny()` per resource, before it is queried |
| Actions | the action's own check, asked by the endpoint |

Authorization is asked at each of these independently. A button being rendered is never taken as proof that the operation is allowed: the action endpoint asks again before it runs anything, and the route asks again before a page is drawn.

## The `*Any` abilities

`canDeleteAny()`, `canRestoreAny()`, and `canForceDeleteAny()` are what a bulk action asks before it has a record to ask about. Each record in the selection is then authorized individually before any of them is written, so a selection containing one forbidden record changes nothing.

```php
public function deleteAny(User $user): bool
{
    return $user->is_editor;
}
```

A policy that defines `delete` but not `deleteAny` will not offer bulk deletion — and under strict mode it will say so rather than reading as a considered refusal.

## Bypassing the ability check the framework asks

Every ability goes through one protected method, so overriding one `can*` keeps the same behaviour by calling it:

```php
use Illuminate\Database\Eloquent\Model;

public static function canDelete(Model $record): bool
{
    return $record->getAttribute('published_at') === null
        && static::authorize('delete', $record);
}
```

```php
protected static function authorize(string $ability, Model|string $argument): bool
```

`authorize()` delegates to `PandaPanel\Support\PolicyGate::allows()`, which is where strict mode lives. Calling `Gate::allows()` directly instead would skip it.

## Strict authorization

```php
$panel->strictAuthorization();
```

Off by default. On, a missing policy or a missing policy method throws `PandaPanel\Exceptions\PanelAuthorizationException` instead of quietly denying:

```text
No policy is registered for [App\Models\Post], so the ability [viewAny] can only ever be denied.
Register one, or turn off strictAuthorization() for this panel.
```

A missing policy and a policy that refuses look identical from the outside — both are a 403. That is correct in production and unhelpful while building, where a forgotten policy reads as a working authorization rule. Turn it on locally, leave it off in production, or gate it on the environment:

```php
$panel->strictAuthorization(! app()->isProduction());
```

A policy defining `before()` is exempt from the method check: `before` may answer for every ability, in which case a missing method is not a mistake.

The rule lives in `PolicyGate` rather than on the resource because relation managers ask abilities a resource has no method for, and two copies of it would be two places to keep true.

Outside strict mode there is still a smaller version of this. When a resource is missing from the sidebar because its model has no policy at all, a debug log line says so — once per model, and never in production, where a resource hidden from every user is a legitimate arrangement rather than a mistake.

## The scope is the other half

Authorization answers "may this user do this to this record". The resource query answers "is this record reachable at all". They are different refusals and they produce different statuses:

- A record outside `Resource::query()` is a **404**. As far as the resource is concerned it does not exist.
- A record inside the query that the policy refuses is a **403**.

A tenant, a team, or a per-panel narrowing belongs in the query, not in the policy — see [Resource queries](queries.md). A policy that has to re-check the tenant is a second place the rule can be forgotten.

## Panel access

A resource policy cannot rescue a panel the user may not enter. The panel's own predicate runs first:

```php
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

$panel->canAccess(
    static fn (?Authenticatable $user): bool => $user instanceof User && $user->is_admin,
);
```

An authenticated user who fails it gets a 403, not a redirect. See [Panel access](../panels/access.md).

## Nested resources

A nested resource's parent is resolved through the *parent* resource's `query()` and authorized with its `canView()` before the child's routes run. So `/users/9/posts` cannot be a way to read user 9's children while `/users/9` itself is refused. See [Nested resources](nested-resources.md).

## Relation managers

A relation manager asks two different questions and keeps them apart: reading and writing the related record are abilities on that record's own policy, while attaching and detaching are abilities on the **owner's** policy — whether a tag may be pinned to a post is the post's business, not the tag's. See [Relation policies](../relations/policies.md).

## Notes

- **Hiding is not authorizing.** Navigation visibility is a convenience layer; the route and the action endpoint each ask again, and a test should assert the 403 rather than the missing link.
- **`canEdit()` maps to `update`.** A policy method named `edit` is never called.
- **A missing `deleteAny` silently removes bulk deletion** unless strict mode is on. This is the most common cause of "the bulk action disappeared".
- **The `can*` methods are static and take no user.** They ask the Gate about the currently authenticated user; there is no way to ask them about somebody else, because a sidebar rendered as another user is not something the framework can honour.
- **Strict mode turns a 403 into a 500.** It is a development aid, not a production setting.
- **Actions authorize independently of the page that drew them.** A view page's actions are resolved against `Resource::infolist()` and a table's against `Resource::table()` — a different whitelist each, so an action offered on one page cannot be run from the other.

## See also

- [Resource queries](queries.md)
- [Model binding](model-binding.md)
- [Nested resources](nested-resources.md)
- [Soft deletes](soft-deletes.md)
- [Authorization concepts](../concepts/authorization.md)
- [Panel access](../panels/access.md)
- [Action authorization](../actions/authorization.md)
- [Relation policies](../relations/policies.md)
- [Testing authorization](../testing/authorization.md)
- [403 troubleshooting](../troubleshooting/authorization-403.md)
