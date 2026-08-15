<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Widgets\CustomWidget;

/**
 * Names a component that is not in the build. The dashboard must still
 * render; the frontend falls back rather than failing.
 */
final class UnregisteredCustomWidget extends CustomWidget
{
    protected static int $sort = 60;

    protected static string $component = 'Panels/Admin/Widgets/DoesNotExist';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return ['ok' => true];
    }
}
