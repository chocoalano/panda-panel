<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PandaPanel\Contracts\HasPanelTenants;
use PandaPanel\Core\Panel;

/**
 * A user that belongs to workspaces.
 *
 * The two contract methods answer from the same pivot here, which is the
 * ordinary case — but they are still two methods, and the suite proves why:
 * `canAccessPanelTenant()` is asked on every request and must not be a
 * function of the list built for the switcher.
 */
final class TenantUser extends User implements HasPanelTenants
{
    protected $table = 'users';

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(
            Workspace::class,
            'fixture_workspace_user',
            'user_id',
            'workspace_id',
        );
    }

    /**
     * @return Collection<int, Model>
     */
    public function getPanelTenants(Panel $panel): Collection
    {
        /** @var Collection<int, Model> $workspaces */
        $workspaces = $this->workspaces()->orderBy('id')->get();

        return $workspaces;
    }

    public function canAccessPanelTenant(Model $tenant, Panel $panel): bool
    {
        return $this->workspaces()->whereKey($tenant->getKey())->exists();
    }
}
