<?php

declare(strict_types=1);

/*
 * The application-owned label file, as an application would write it.
 *
 * Read through `FileLoader::addPath()` in `DerivedLabelTest`, which is how a
 * test gets a second lang root without writing into the package's own — the
 * suite's application base path *is* this repository, so a `lang/en/panel.php`
 * beside the package's translations would ship inside the Composer archive.
 *
 * English is deliberately sparse. Its job here is to prove that a locale with
 * no entry for a name still headlines it, while the same name in `id` does not.
 */

return [
    'fields' => [
        'name' => 'Full name',
    ],
];
