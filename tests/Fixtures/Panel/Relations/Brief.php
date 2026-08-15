<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;

/**
 * The one related record a `hasOne` relation form writes to.
 */
final class Brief extends Model
{
    protected $table = 'fixture_briefs';

    protected $guarded = [];

    public $timestamps = false;
}
