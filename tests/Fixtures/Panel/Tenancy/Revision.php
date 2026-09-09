<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child of a tenant-scoped record.
 *
 * It carries no tenant key of its own, which is the point: its isolation is
 * entirely inherited from the document that owns it. That is the arrangement
 * a leak actually hides in — the parent is scoped, the child is reached
 * through the parent, and the only thing standing between one tenant and
 * another's rows is that the owner lookup was scoped too.
 */
final class Revision extends Model
{
    protected $table = 'fixture_revisions';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
