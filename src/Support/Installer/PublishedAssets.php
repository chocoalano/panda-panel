<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;
use PandaPanel\Support\FrontendPaths;

/**
 * Every file this package publishes into an application, in one place.
 *
 * The service provider's `publishes()` call reads this, and so does
 * `panel:assets`. That is the whole reason it exists as a class: a publish map
 * written out in the provider and a second copy in the command that has to
 * diff it would drift the first time a directory was added, and the symptom
 * would be a file that publishes but is never reported as out of date — which
 * is worse than not reporting at all.
 *
 * The frontend is published rather than imported from the package because
 * every component registry is an `import.meta.glob` allowlist over the
 * application's own tree: a component the build never saw is a component that
 * cannot resolve. Published files are the application's — in its repository,
 * in its build, and editable.
 *
 * The cost of that decision is the one `panel:assets` exists to pay: once a
 * file is the application's, a package update cannot silently improve it.
 */
final class PublishedAssets
{
    /**
     * The map `vendor:publish` is given: absolute source => absolute
     * destination.
     *
     * Built per call rather than held in a constant because two destinations
     * are configurable, and reading config at class-definition time would
     * freeze whatever it happened to be during package discovery.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            self::packagePath('resources/js/panel') => FrontendPaths::panel(),
            self::packagePath('resources/js/components') => resource_path('js/components'),
            self::packagePath('resources/js/composables') => resource_path('js/composables'),
            self::packagePath('resources/js/lib') => resource_path('js/lib'),
            self::packagePath('resources/js/pages') => resource_path('js/pages'),
            self::packagePath('resources/js/types') => resource_path('js/types'),
            self::packagePath('resources/css/panda-panel.css') => resource_path('css/panda-panel.css'),
        ];
    }

    /**
     * Every individual file, keyed by where it lands in the application.
     *
     * Files rather than directories, because "is this up to date" is a
     * question about a file: a directory that gained one component and had
     * another edited is neither changed nor unchanged.
     *
     * @return array<string, string> absolute destination => absolute source
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::map() as $source => $destination) {
            if (! File::isDirectory($source)) {
                $files[$destination] = $source;

                continue;
            }

            foreach (File::allFiles($source) as $file) {
                $files[$destination.'/'.$file->getRelativePathname()] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * A destination path as it reads in a report — relative to the
     * application, because an absolute path in a list of forty is noise.
     */
    public static function relative(string $path): string
    {
        $base = base_path().'/';

        return str_starts_with($path, $base) ? mb_substr($path, mb_strlen($base)) : $path;
    }

    private static function packagePath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }
}
