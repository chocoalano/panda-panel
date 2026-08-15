<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with no policy registered anywhere.
 *
 * It never reaches the database: the abilities it takes part in are checked
 * against the class, which is all `Gate::getPolicyFor()` needs.
 */
final class UnpolicedModel extends Model
{
    protected $table = 'unpoliced';
}
