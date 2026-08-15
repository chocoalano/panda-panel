<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of a many-to-many, joined by a pivot that carries its own
 * column.
 */
final class Label extends Model
{
    protected $table = 'fixture_labels';

    protected $guarded = [];

    public $timestamps = false;
}
