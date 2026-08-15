<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Applies the system preference before first paint, so a dark-mode
             user never sees a white flash on the way in. --}}
        <script>
            (function () {
                const appearance = '{{ $appearance ?? 'system' }}';

                if (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <style>
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        {{--
            The panel's own entrypoints are appended to the application's, so
            they load on that panel's pages and nowhere else. Empty outside a
            panel, and for a panel that declared none.
        --}}
        @vite([
            'resources/css/app.css',
            'resources/js/app.ts',
            "resources/js/pages/{$page['component']}.vue",
            ...(panel()?->getAssets() ?? []),
        ])

        {{--
            Inertia's Blade directives rather than its class components.
            Both are inertia-laravel's and both render the same thing, but a
            directive is compiled without resolving a class-component
            namespace — and that resolution falls back to
            Application::getNamespace(), which reads autoload.psr-4 from
            composer.json. This application's PHP lives in examples/app,
            declared under autoload-dev, so that lookup has nothing to find
            and view:cache fails on Laravel 12.

            The directives are also what a Laravel Vue starter kit ships,
            which is what this file is here to look like.
        --}}
        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
