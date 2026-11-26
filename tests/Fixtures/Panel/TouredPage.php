<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Pages\Page;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;

/**
 * A standalone page with a guided tour.
 */
final class TouredPage extends Page
{
    protected static ?string $title = 'Toured';

    protected static ?string $slug = 'toured';

    public function tour(): ?Tour
    {
        return Tour::make()->steps([
            TourStep::make('Welcome'),
        ]);
    }
}
