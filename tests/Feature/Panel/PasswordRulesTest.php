<?php

declare(strict_types=1);

use Illuminate\Validation\Rules\Password;
use PandaPanel\Support\PasswordRules;

/*
|--------------------------------------------------------------------------
| The password policy, as Safari reads it
|--------------------------------------------------------------------------
|
| `passwordrules` is what Safari and iOS read when generating a suggested
| password. A policy expressed only in server-side validation produces a
| suggestion the form then rejects, which is why it crosses to the frontend at
| all.
|
| Laravel 13 builds this string; Laravel 12 has no such method, and calling it
| there was a 500 on the security settings page — under a constraint that
| claimed to support both. These tests assert the *output*, not which branch
| produced it, so they pass on either version and fail if the fallback ever
| stops agreeing with the framework.
|
*/

it('describes the default policy', function (): void {
    Password::defaults(fn () => Password::min(8));

    expect(PasswordRules::attribute())->toBe('minlength: 8;');
});

it('describes every requirement a policy can carry', function (): void {
    expect(PasswordRules::attribute(
        Password::min(12)->max(64)->mixedCase()->numbers()->symbols(),
    ))->toBe('minlength: 12; maxlength: 64; required: lower; required: upper; required: digit; required: special;');
});

it('says lower once for a letters-only policy', function (): void {
    // `mixedCase` implies letters, so the two are one branch. A policy asking
    // for mixed case that also emitted a bare `required: lower` would be
    // saying the same thing twice.
    expect(PasswordRules::attribute(Password::min(10)->letters()))
        ->toBe('minlength: 10; required: lower;');
});

it('omits a maximum the policy never set', function (): void {
    expect(PasswordRules::attribute(Password::min(8)->numbers()))
        ->toBe('minlength: 8; required: digit;');
});

it('agrees with the framework wherever the framework has an opinion', function (): void {
    $password = Password::min(12)->max(64)->mixedCase()->numbers()->symbols();

    // On Laravel 13 this proves the two implementations agree. On Laravel 12
    // there is nothing to compare against, and the assertions above are what
    // hold the fallback to the same output.
    if (! method_exists($password, 'toPasswordRulesString')) {
        expect(true)->toBeTrue();

        return;
    }

    expect(PasswordRules::attribute($password))->toBe($password->toPasswordRulesString());
});
