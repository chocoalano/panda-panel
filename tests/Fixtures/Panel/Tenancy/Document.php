<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Document extends Model
{
    protected $table = 'fixture_documents';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }
}
