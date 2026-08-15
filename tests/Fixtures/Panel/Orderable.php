<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with a real order column, so reordering can be proved without
 * writing positions into somebody else's primary key.
 *
 * Its table is created by the test that needs it.
 */
final class Orderable extends Model
{
    protected $table = 'orderables';

    protected $guarded = [];

    public $timestamps = false;
}
