<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Pages\Page;
use PandaPanel\Widgets\Widget;

/**
 * A page that hosts widgets, so widget behaviour can be asserted somewhere
 * other than a dashboard.
 */
final class WidgetHostPage extends Page
{
    protected static ?string $title = 'Widget Host';

    protected static ?string $slug = 'widget-host';

    /** @var list<class-string<Widget>> */
    public static array $widgetClasses = [];

    /**
     * @return list<class-string<Widget>>
     */
    public function widgets(): array
    {
        return self::$widgetClasses;
    }
}
