<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use PandaPanel\Pages\Settings\AppearanceSettings;
use PandaPanel\Pages\Settings\ProfileSettings;
use PandaPanel\Pages\Settings\SecuritySettings;
use Tests\TestCase;

// Before the first application is built, because Laravel writes its package
// manifest while one is still being constructed.
TestCase::prepareWritableDirectories();

/**
 * The account pages every panel gets unless `settings(false)` says otherwise.
 *
 * Named here so the discovery tests can subtract them and assert on what a
 * panel actually declared. Restating the list is the point: a test that read
 * `Panel::SETTINGS_PAGES` back would pass whatever that constant said.
 */
define('SETTINGS_PAGES', [
    ProfileSettings::class,
    SecuritySettings::class,
    AppearanceSettings::class,
]);

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
