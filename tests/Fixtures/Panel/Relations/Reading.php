<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;

/**
 * A record that was never in a database.
 *
 * `ArrayTableData` still needs `Model` instances, because a column reads its
 * value with `data_get()` and every renderer downstream expects that shape. A
 * non-persisted model is the cheapest way to get it: no table, no migration,
 * and `Model::make()` on a `$guarded = []` class is the whole ceremony.
 */
final class Reading extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
