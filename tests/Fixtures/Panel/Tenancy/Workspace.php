<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Contracts\PanelTenant;

/**
 * A tenant that implements the contract, so the suite exercises the answers
 * a project gives rather than the fallbacks.
 */
final class Workspace extends Model implements PanelTenant
{
    protected $table = 'fixture_workspaces';

    protected $guarded = [];

    public $timestamps = false;

    public function getTenantKey(): int|string
    {
        return (int) $this->getKey();
    }

    public function getTenantName(): string
    {
        return (string) $this->getAttribute('name');
    }
}
