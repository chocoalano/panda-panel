<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * A `@vite` that resolves without a build.
 *
 * The suite asserts on what the panel sends to the frontend, never on the
 * script tags around it. Requiring a real manifest would make every page test
 * depend on `npm run build` having been run, which is a build step no PHP test
 * should need — and one CI would have to repeat for a result that never
 * changes.
 *
 * `RecordingVite` is the same idea with an assertion attached: tests that care
 * *which* entrypoints were asked for bind that instead.
 */
class FakeVite extends Vite
{
    /**
     * @param  string|array<int, string>  $entrypoints
     */
    public function __invoke($entrypoints, $buildDirectory = null): HtmlString
    {
        return new HtmlString('');
    }
}
