<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use Illuminate\Foundation\Application;
use PandaPanel\Widgets\CustomWidget;

/**
 * Runtime facts, rendered by an application-supplied Vue component.
 *
 * Exists to prove the custom widget path end to end: the component name
 * comes from this class and is resolved through a build-time glob.
 */
final class SystemInfo extends CustomWidget
{
    protected static int $sort = 40;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 1, 'xl' => 2];

    protected static string $component = 'Panels/Admin/Widgets/SystemInfo';

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'laravel' => Application::VERSION,
            'php' => PHP_VERSION,
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
        ];
    }
}
