<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
 * The panel's toasts are broadcast on the signed-in user's own private
 * channel. This is the rule that makes that safe: a socket may only subscribe
 * to the channel whose id is its own, whatever the frontend asks for.
 *
 * Laravel's default channel name, unchanged, so a notification broadcast by
 * anything else in the application arrives on the same one.
 */
Broadcast::channel('App.Models.User.{id}', static fn ($user, int|string $id): bool => (int) $user->id === (int) $id);
