<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The owner side of every relation shape the panel supports.
 *
 * One model rather than four keeps the fixtures honest about the thing that
 * actually matters: the same manager machinery has to answer for a
 * `hasMany`, a `belongsToMany` with pivot columns, and a `hasOne`.
 *
 * Its table is created by `RelationSchema`.
 */
final class Project extends Model
{
    protected $table = 'fixture_projects';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(
            Label::class,
            'fixture_label_project',
            'project_id',
            'label_id',
        )->withPivot('role');
    }

    /**
     * @return HasOne<Brief, $this>
     */
    public function brief(): HasOne
    {
        return $this->hasOne(Brief::class, 'project_id');
    }
}
