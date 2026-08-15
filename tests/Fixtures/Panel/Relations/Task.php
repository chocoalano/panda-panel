<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A child that soft deletes, so the trashed filter and the restore and
 * force-delete actions have something real to act on.
 */
final class Task extends Model
{
    use SoftDeletes;

    protected $table = 'fixture_tasks';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
