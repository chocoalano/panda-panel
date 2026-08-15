# Related Record Policies

A relation asks two different authorization questions, and keeps them apart because they have different subjects. Reading and writing the related *record* are abilities on that record's own policy; joining and unjoining are abilities on the *owner's* policy — whether a tag may be pinned to a post is the post's business, not the tag's. This page is the full map of which ability is asked of whom.

## The two policies

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * The owner's policy: reading the project, and membership of its relations.
 */
final class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.read');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.read');
    }

    // Membership abilities. Nothing but a relation asks for these.

    public function attachAny(User $user, Project $project): bool
    {
        return $user->can('projects.update');
    }

    public function detach(User $user, Project $project, Label $label): bool
    {
        return $user->can('projects.update');
    }

    public function associateAny(User $user, Project $project): bool
    {
        return $user->can('projects.update');
    }

    public function dissociate(User $user, Project $project, Task $task): bool
    {
        return $user->can('projects.update');
    }
}
```

```php
/**
 * The related record's own policy: reading and writing labels.
 */
final class LabelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('labels.read');
    }

    public function view(User $user, Label $label): bool
    {
        return $user->can('labels.read');
    }

    public function create(User $user): bool
    {
        return $user->can('labels.create');
    }

    public function update(User $user, Label $label): bool
    {
        return $user->can('labels.update');
    }

    public function delete(User $user, Label $label): bool
    {
        return $user->can('labels.delete');
    }
}
```

Register them the usual way — Laravel's convention-based discovery, or explicitly:

```php
use Illuminate\Support\Facades\Gate;

Gate::policy(Project::class, ProjectPolicy::class);
Gate::policy(Label::class, LabelPolicy::class);
```

## The full map

| Manager method | Ability | Asked of | Extra argument |
| --- | --- | --- | --- |
| `canViewAny(Model $owner)` | `viewAny` | related model class | — |
| `canView(Model $owner, Model $record)` | `view` | related record | — |
| `canCreate(Model $owner)` | `create` | related model class | — |
| `canEdit(Model $owner, Model $record)` | `update` | related record | — |
| `canDelete(Model $owner, Model $record)` | `delete` | related record | — |
| `canRestore(Model $owner, Model $record)` | `restore` | related record | — |
| `canForceDelete(Model $owner, Model $record)` | `forceDelete` | related record | — |
| `canAttach(Model $owner)` | `attachAny` | **owner** | — |
| `canDetach(Model $owner, Model $record)` | `detach` | **owner** | the related record |
| `canAssociate(Model $owner)` | `associateAny` | **owner** | — |
| `canDissociate(Model $owner, Model $record)` | `dissociate` | **owner** | the related record |

The two-argument abilities receive both models, in that order:

```php
public function detach(User $user, Project $project, Label $label): bool;
public function dissociate(User $user, Project $project, Task $task): bool;
```

The single-argument membership abilities are named `attachAny` and `associateAny` because they are asked before a record has been chosen — the button exists or it does not, and the specific record is only known once the dialog has been filled in.

## Reaching a relation requires seeing the owner

Every relation endpoint asks two things before it asks anything about the operation:

```php
abort_unless($resource::canView($owner), 403);      // the owner's `view`
abort_unless($manager::canViewAny($owner), 403);    // the related model's `viewAny`
```

Without the first, the relation endpoint would be a way around a refused view: a user who cannot open project 7 could still read its labels by naming it in a relation request. Neither substitutes for the other.

The owner is loaded through `Resource::query()` as well, so a record the owning resource's scope excludes is a 404 before any policy is asked.

## `canViewAny()` runs before the query

```php
$relations = RelationTable::forRecord(ProjectResource::class, $project, $request);
```

A manager whose `canViewAny()` says no is absent from the payload entirely, and never runs its query. A manager that queried and then hid its rows would still have read them:

```php
// With LabelPolicy::viewAny() returning false:
collect($relations)->pluck('key');   // ['tasks'] — 'labels' is not there at all
```

The same check gates the record sub-navigation, so a relation page the user may not read has no link *and* answers 403 at its URL.

## Per-action authorization

Every relation action carries its own `->authorize()` closure, asked again at the endpoint with the resolved record:

| Action | Asks |
| --- | --- |
| `CreateRelatedAction` | `canCreate($owner)` |
| `EditRelatedAction` | `canEdit($owner, $record)` |
| `DeleteRelatedAction` | `canDelete($owner, $record)` |
| `AttachAction` | `isManyToMany($owner)` and `canAttach($owner)` |
| `DetachAction` | `canDetach($owner, $record)` |
| `DetachBulkAction` | `canAttach($owner)` for the set, then `canDetach($owner, $record)` per record |
| `AssociateAction` | `isOneToMany($owner)` and `canAssociate($owner)` |
| `DissociateAction` | `canDissociate($owner, $record)` |
| `RestoreAction` | `canRestore($owner, $record)` |
| `RestoreBulkAction` | `canRestore($owner, $record)` per record |
| `ForceDeleteAction` | `canForceDelete($owner, $record)` |
| `ForceDeleteBulkAction` | `canForceDelete($owner, $record)` per record |

An action that is not authorized is `null` in the serialized payload — it is never rendered — and the endpoint asks the same question again before running it. Hiding a button is never what protects an operation:

```text
POST /{panel}/relations/action   { "action": "detach", ... }
→ 403 even though no detach button was ever sent to that user
```

A bulk action authorizes every record before writing any of them and runs the set in one transaction, so a selection containing one forbidden record changes nothing rather than half of it.

## Strict authorization

Every relation ability goes through `RelationManager::authorize()`, which delegates to `PandaPanel\Support\PolicyGate` — the same path `Resource::authorize()` takes. That is what makes the strict mode cover the relation abilities, which have no `can*` method on a resource to be noticed by:

```php
use PandaPanel\Core\Panel;

Panel::make('admin')
    ->path('admin')
    ->strictAuthorization();
```

| Situation | Without strict mode | With strict mode |
| --- | --- | --- |
| No policy registered for the model | `Gate::allows()` answers false | `PanelAuthorizationException::missingPolicy()` |
| Policy exists but has no such method | answers false | `PanelAuthorizationException::missingPolicyMethod()` |
| Policy defines `before()` | that answer stands | that answer stands — a `before` may answer for every ability |

```text
PanelAuthorizationException: The policy [App\Policies\ProjectPolicy] for [App\Models\Project]
does not define [attachAny], so that ability can only ever be denied. Add the method, or turn
off strictAuthorization() for this panel.
```

Deny is the safe direction, but a missing `attachAny` and a deliberate "no" look identical from the outside — the attach button simply is not there. Strict mode is what tells the two apart, and it is worth having in development.

## Overriding an ability on the manager

The `can*` methods are ordinary static methods. Override one where the answer is not the policy's alone:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\RelationManager;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    /**
     * A closed project's tasks are read-only, whatever the policy says about
     * the task itself.
     */
    public static function canEdit(Model $owner, Model $record): bool
    {
        return $owner->getAttribute('status') !== 'closed'
            && parent::canEdit($owner, $record);
    }
}
```

Call `parent::` rather than reimplementing it: the parent is what routes through `PolicyGate`, and an override that calls `Gate::allows()` directly opts that ability out of strict mode.

## Gotchas

- **`attachAny`, `detach`, `associateAny`, and `dissociate` go on the owner's policy.** Putting them on the related record's policy produces buttons that never appear, with no error to explain it.
- **`detach` and `dissociate` take three arguments.** A two-argument method never matches, and Laravel treats the mismatch as a denial.
- **`->authorize()` on a relation action replaces the built-in check.** Chain it only when you mean to take over the decision, and call the manager's method inside your closure to narrow rather than replace it.
- **`canViewAny()` asks the *related* model's `viewAny`, not the owner's.** Refusing `viewAny` on the related model hides the relation everywhere it appears: inline, on its relation page, and at every endpoint.
- **The related record's policy governs the record; the owner's governs the pairing.** Deleting a label is `LabelPolicy::delete`; unpinning it from a project is `ProjectPolicy::detach`.
- **A missing policy logs once in development.** `PandaPanel\Support\MissingPolicyNotice` explains a resource missing from the navigation for that reason; strict mode is the version that stops the request.

## See also

- [Relation managers](relation-managers.md)
- [Attach and detach](attach-detach.md)
- [Associate and dissociate](associate-dissociate.md)
- [Soft deleted relations](soft-deletes.md)
- [Relation pages](relation-pages.md)
- [Authorization concepts](../concepts/authorization.md)
- [Resource authorization](../resources/authorization.md)
- [Action authorization](../actions/authorization.md)
- [Testing authorization](../testing/authorization.md)
