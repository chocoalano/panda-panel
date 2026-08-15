<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
| The installer is not run end-to-end here on purpose: the suite uses this
| package as the application, so `vendor:publish` would copy `resources/js`
| onto itself. What is checked is everything a wiring mistake would break —
| that both commands are registered, and that their signatures parse.
*/

it('registers the install and user commands with the signatures the docs promise', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKeys(['panel:install', 'panel:user']);

    $install = $commands['panel:install']->getDefinition();

    expect(array_keys($install->getOptions()))
        ->toContain('panel', 'no-panel', 'no-user', 'force');

    $user = $commands['panel:user']->getDefinition();

    expect(array_keys($user->getOptions()))
        ->toContain('name', 'email', 'password', 'guard', 'panel');
});
