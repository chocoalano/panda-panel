<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\EditRecord;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;

/**
 * A page that answers for itself, from the record it is showing.
 */
final class TouredEditUser extends EditRecord
{
    protected static string $resource = TouredUserResource::class;

    public function tour(?Model $record = null): ?Tour
    {
        return Tour::make()->steps([
            TourStep::make('Editing '.$record?->getAttribute('name')),
        ]);
    }
}
