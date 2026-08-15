<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * Records the entrypoints `@vite` was given, and emits nothing.
 *
 * The tags themselves differ between the dev server and a build — a source
 * path in one, a hashed filename in the other — so asserting on the rendered
 * HTML would pass or fail depending on whether `npm run dev` happened to be
 * running. What the panel is responsible for is the list, so that is what
 * this captures.
 */
final class RecordingVite extends Vite
{
    /** @var list<string> */
    public array $entrypoints = [];

    /**
     * @param  string|array<int, string>  $entrypoints
     */
    public function __invoke($entrypoints, $buildDirectory = null): HtmlString
    {
        $this->entrypoints = array_merge($this->entrypoints, (array) $entrypoints);

        return new HtmlString('');
    }
}
