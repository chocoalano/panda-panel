<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * A tenant model that does *not* implement `PanelTenant`, which is the case
 * the fallbacks in `Tenancy` exist for — the key and a `name` column.
 */
final class UnnamedTenant extends Model
{
    protected $table = 'fixture_workspaces';

    protected $guarded = [];

    public $timestamps = false;
}
