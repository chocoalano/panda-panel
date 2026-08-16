# Channel Authorization

Panel notifications go out on a **private** channel named after the recipient. Private means Laravel asks the application whether this socket may listen, once, when the subscription is opened. That callback is the whole of the security model for realtime notifications, and it lives in the application's `routes/channels.php` rather than in the package — because it is a statement about your user model, not about the panel.

## A minimal working example

```php
<?php

// routes/channels.php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
 * The panel's toasts are broadcast on the signed-in user's own private
 * channel. This is the rule that makes that safe: a socket may only subscribe
 * to the channel whose id is its own, whatever the frontend asks for.
 */
Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, int|string $id): bool => hash_equals((string) $user->getAuthIdentifier(), (string) $id),
);
```

That is the file the package's own test suite loads, verbatim. Nothing else is required for panel notifications.

## The channel name

Built on the server, in one place:

```php
namespace PandaPanel\Broadcasting;

public static function channelFor(Authenticatable $user): string
{
    return 'App.Models.User.'.$user->getAuthIdentifier();
}
```

```php
use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::channelFor($user);   // 'App.Models.User.7'
```

Both broadcast events wrap it in `Illuminate\Broadcasting\PrivateChannel`, so the name on the wire carries the `private-` prefix:

```php
$event = new PanelNotification($user, 'Export finished', 'success');

$event->broadcastOn()[0]->name;   // 'private-App.Models.User.7'
```

The `Broadcast::channel()` pattern is written without that prefix — Laravel strips it before matching. It is also Laravel's own default name, unchanged on purpose: a notification broadcast by anything else in the application arrives on the same channel the panel is already listening to.

`Panel::getBroadcastChannel()` is what decides whether a channel name is sent to the browser at all:

```php
public function getBroadcastChannel(?Authenticatable $user): ?string
{
    return $this->broadcasting && $user !== null
        ? PanelNotification::channelFor($user)
        : null;
}
```

A guest gets `null`, so there is nothing to subscribe to rather than a channel that would be refused.

## How the check actually runs

1. Echo opens the subscription and posts to `/broadcasting/auth` with the session cookie.
2. Laravel matches `private-App.Models.User.7` against the registered patterns and binds `{id}` to `7`.
3. The callback runs with the authenticated user and the bound parameter.
4. `true` signs the subscription; `false` — or no authenticated user — is a 403 and the socket never joins.

The channel name the client asks for is untrusted, which is the point: the server sends the user their own name, and the callback would refuse any other one regardless of what the frontend sends.

## Non-integer keys and custom user models

The default callback compares strings because `channelFor()` also uses the auth identifier as a string. Compare the way your model actually identifies:

```php
// UUID primary keys — never cast a UUID to int.
Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, string $id): bool => hash_equals((string) $user->getAuthIdentifier(), $id),
);
```

Whatever you compare, compare it against the same thing `channelFor()` produced: `getAuthIdentifier()`, which is the *value* of the model's auth identifier column — usually the primary key.

If your user model is not `App\Models\User`, the channel name still says `App.Models.User`. It is a string, not a class reference, and the panel builds it from the authenticated user regardless of that user's class. Keep the pattern as it is unless you have a reason to rename both halves, which you cannot do for the panel — `channelFor()` is not configurable.

## Guards

`Broadcast::channel()` takes an options array, and the panel does nothing to interfere with it. A panel served to a non-default guard needs that guard named:

```php
Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, int|string $id): bool => hash_equals((string) $user->getAuthIdentifier(), (string) $id),
    ['guards' => ['web', 'admin']],
);
```

Without it, Laravel checks the default guards, and a user authenticated on `admin` alone is refused.

## Registering the file

`routes/channels.php` is loaded by the application, not the package:

```php
// bootstrap/app.php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    // …
```

`php artisan install:broadcasting` writes both the file and that line. The package ships neither: an application that already authorizes this channel — every Laravel application that has ever broadcast a notification — would end up with two conflicting definitions of the same rule.

## Testing it

Read the callback back from the broadcaster rather than restating it, which is what the package's own suite does:

```php
use Illuminate\Support\Facades\Broadcast;

it('lets a user onto their own channel only', function (): void {
    $other = User::factory()->create();

    $callback = Broadcast::connection()->getChannels()->get('App.Models.User.{id}');

    expect($callback)->not->toBeNull()
        ->and($callback($this->admin, $this->admin->getKey()))->toBeTrue()
        ->and($callback($this->admin, $other->getKey()))->toBeFalse();
});
```

A test that asserted its own copy of the rule would pass while the application's copy was wrong.

## What this does *not* authorize

The channel protects the realtime path only. The notification centre is a separate path with a separate model, and it does not consult `routes/channels.php` at all:

| Path | Authorized by |
| --- | --- |
| Websocket subscription | the callback in `routes/channels.php` |
| `GET/POST /{panel}/notifications*` | the query scope — every one starts from `$request->user()` |
| An action's URL on a stored notification | whatever that URL points at |

The endpoints need no policy because there is no id a request could send that would reach somebody else's row: an id belonging to another user matches nothing rather than 403s. See [Notification center](notification-center.md).

## Gotchas

- **A missing `channels.php` is a silent feature loss.** The subscription 403s in the network tab, the composable does not surface it, and the panel keeps working without toasts.
- **The callback runs on the HTTP request, not the socket.** It has the session, so `auth()->user()` and route model binding behave normally; it does not have the panel context, because `/broadcasting/auth` is not a panel route.
- **Do not cast channel ids to integers unless your auth identifier is guaranteed integer-only.** A UUID or string key can collapse to `0` and leak a channel. Compare the string form of `getAuthIdentifier()` instead.
- **Channel authorization is cached per subscription, not per event.** A user whose permissions change mid-session keeps the socket they already joined until the page is reloaded. Do not use this channel to carry anything a policy would gate; carry a link, and let the destination authorize.

## See also

- [Broadcasting](broadcasting.md) — the events that use this channel
- [Reverb and Echo setup](reverb-echo.md) — the rest of the wiring
- [Notification center](notification-center.md) — the other authorization model
- [Authorization](../concepts/authorization.md)
- [Negative security tests](../testing/negative-security-tests.md)
- [Broadcast failures](../troubleshooting/broadcasting.md)
