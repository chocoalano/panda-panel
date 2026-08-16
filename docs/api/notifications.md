# Notifications Reference

Three ways a panel tells a user something, and the classes behind each: a flash toast that lives for one redirect, a broadcast toast that arrives while the page is open, and a persisted notification the bell counts. `PandaPanel\Notifications\Notification` is the one API that reaches the last two at once.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Notifications\Notification` | Build and send one notification |
| `PandaPanel\Notifications\NotificationAction` | A button on it |
| `PandaPanel\Notifications\Enums\NotificationColor` | `Info`, `Success`, `Warning`, `Danger` |
| `PandaPanel\Notifications\PanelNotificationSent` | The broadcast event both channels share |
| `PandaPanel\Notifications\PanelDatabaseNotification` | The database half |
| `PandaPanel\Notifications\TwoFactorCode` | The emailed second-factor code |
| `PandaPanel\Broadcasting\PanelNotification` | A bare toast, and the channel name both sides agree on |
| `PandaPanel\Http\Middleware\ShareFlashToast` | Laravel's flash keys, mapped onto the toast channel |
| `PandaPanel\Http\Controllers\PanelNotificationController` | The bell's three endpoints |
| `PandaPanel\Contracts\PanelNotifiable` | What the bell needs a user model to be |
| `PandaPanel\Testing\TestsNotifications` | Assertions |

## Sending one

```php
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;

Notification::make('export-ready')
    ->title('Your export is ready')
    ->body('12,431 records, 2.1 MB.')
    ->success()
    ->persistent()
    ->actions([
        NotificationAction::make('download')
            ->label('Download')
            ->url($url, newTab: true),
    ])
    ->send($user);
```

That is one call and two deliveries: a row is written first, then the event is broadcast. The order matters — a user who clicks the toast's action and lands on the notification centre must find the row already there.

For the simplest case, a flash message is still the right tool:

```php
return redirect()->to($url)->with('success', 'Order approved.');
```

`ShareFlashToast` turns `success`, `error`, `warning`, and `info` into the single `toast` channel the frontend listens on, so there is one toast mechanism rather than two competing ones.

## `Notification`

```php
public static function make(?string $name = null): self;   // a UUID when no name is given
public function title(string $title): self;                // Str::headline($name)
public function body(string $body): self;                  // null
public function icon(string $icon): self;                  // the colour's own icon
public function color(NotificationColor $color): self;     // Info
public function success(): self;
public function info(): self;
public function warning(): self;
public function danger(): self;
public function actions(array $actions): self;             // array<array-key, NotificationAction>
public function persistent(bool $persistent = true): self; // false
public function broadcast(bool $broadcast = true): self;   // true
public function getName(): string;
public function isPersistent(): bool;
public function isBroadcast(): bool;
public function send(Authenticatable $user): self;
public function toArray(): array;
```

`persistent()` is off by default: most notifications are the answer to something the user just did, and a bell that fills up with `Saved.` is a bell nobody reads.

`broadcast()` is on by default. Turn it off for something that only makes sense once opened — a long report — where a toast would be an interruption rather than an answer.

`toArray()` is the payload both channels carry *and* the shape stored in the database, so a notification looks the same whether it arrived over a websocket or was read out of a table an hour later:

```php
[
    'name' => string,
    'title' => string,
    'body' => ?string,
    'color' => 'info'|'success'|'warning'|'danger',
    'icon' => string,
    'actions' => list<array>,
    'type' => 'info'|'success'|'warning'|'error',   // the toast channel
    'persistent' => bool,
]
```

### `send()`

```php
public function send(Authenticatable $user): self
```

Writes the row when `persistent()` and the user can be notified, then fires `PanelNotificationSent`. It checks `method_exists($user, 'notify')` rather than an interface: what matters is whether this notifiable can be notified, and a user model without the trait has nowhere to store one.

## `NotificationAction`

```php
public static function make(string $name): self;
public function label(string $label): self;                   // Str::headline($name)
public function url(string $url, bool $newTab = false): self;
public function variant(ActionVariant $variant): self;        // Outline
public function markAsRead(bool $mark = true): self;          // true
public function getName(): string;
public function toArray(): array;
public static function fromArray(array $data): ?array;
```

A URL and nothing else. This is deliberately **not** an `Action`: an action is resolved against the schema that declared it, and a notification has no schema — it is a row that may still be there next week, long after the page that sent it stopped existing.

So what crosses is a link the server produced. Relative URLs and the `http`, `https`, `mailto`,
and `tel` schemes are kept; unsafe schemes are serialized as `null`, and the Vue components guard
the URL again before opening it. Whatever the link points at authorizes for itself when it is
followed, which is the only check still meaningful a week later.

`fromArray()` rebuilds one from a stored row and returns `null` for a shape that does not match. A persisted notification is JSON in a database, which makes it untrusted in exactly the way a request body is.

`markAsRead()` is on by default: a notification you acted on is one you have seen, and leaving it unread would mean the count outliving the thing it counted.

## `NotificationColor`

```php
enum NotificationColor: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    public function icon(): string;      // info, check, triangle-alert, circle-alert
    public function toastType(): string; // info, success, warning, error
}
```

Closed, because each case maps to a literal set of frontend classes and an interpolated colour would compile to nothing. `toastType()` is where `Danger` becomes `error`, so a transient notification and a flash message look the same.

## Broadcasting

### `PanelNotificationSent`

```php
final class PanelNotificationSent implements ShouldBroadcast
{
    public function __construct(public readonly Authenticatable $user, public readonly array $payload);

    public function broadcastOn(): array;    // [new PrivateChannel(PanelNotification::channelFor($user))]
    public function broadcastAs(): string;   // 'panel.notification'
    public function broadcastWith(): array;  // the payload, plus `message` and `persistent`
}
```

One event for both concerns: to the frontend they are the same thing arriving — a message to show and, when it was persisted, a bell to increment. Two events would mean two subscriptions and two chances for them to disagree.

`broadcastWith()` adds `message` (a copy of the title, which the toast reads) alongside `title` and `body` (which the bell reads), rather than making either side guess.

### `PanelNotification`

A bare toast, for a job whose result is a file and nothing to store.

```php
use PandaPanel\Broadcasting\PanelNotification;

event(new PanelNotification(
    user: $user,
    message: 'Your export finished.',
    type: 'success',                 // 'success'|'info'|'warning'|'error'
    url: $downloadUrl,
    urlLabel: 'Download',
));
```

```php
public static function channelFor(Authenticatable $user): string;   // 'App.Models.User.{id}'
public function broadcastAs(): string;                              // 'panel.notification'
public function broadcastWith(): array;                             // {type, message, url, urlLabel}
```

The channel name is built here rather than written out in Vue, so the two cannot drift. `Panel::getBroadcastChannel($user)` returns it, or `null` when broadcasting is off or nobody is signed in.

### Channel authorization

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, int|string $id): bool => (int) $user->id === (int) $id,
);
```

Laravel's default channel name, unchanged, so a notification broadcast by anything else in the application arrives on the same one. A socket may only subscribe to the channel whose id is its own, whatever the frontend asks for.

### `BroadcastSupport`

```php
PandaPanel\Support\BroadcastSupport::isConfigured(): bool;
```

`Panel::broadcasting()` says whether a panel *wants* realtime notifications; this says whether the application has anything to deliver them with. Three things must hold: a default connection is named, that connection exists and has a driver, and the driver is not `null` or `log` — both real drivers, and neither something a browser can attach to.

`SharePanelData` asks both, so a panel installed into a starter kit with no broadcaster ships `broadcasting: {enabled: false, channel: null}` rather than a channel that would throw from inside `onMounted`.

## The database half

### `PanelDatabaseNotification`

```php
final class PanelDatabaseNotification extends Illuminate\Notifications\Notification
{
    public function __construct(private readonly array $payload);
    public function via(object $notifiable): array;    // ['database']
    public function toArray(object $notifiable): array;
}
```

Laravel's own notification class rather than a table of the panel's own, so `unreadNotifications`, `markAsRead()`, and the rest all work without a line of code — and a notification sent by anything else in the application shows up in the panel's bell too.

Broadcasting is deliberately *not* on `via()`: the panel pushes its own event carrying the same payload, and the bell refetches when that event says a row was written.

The `notifications` table ships with the package's own migration, controlled by `config('panda-panel.load_migrations')`.

## The notification centre

Three JSON endpoints, all scoped to the authenticated user's own rows by construction — the query starts from `$request->user()`, so there is no id a request could send that would reach somebody else's. That scope *is* the authorization, which is why none of them needs a policy.

| Route name | Verb and path | Body | Answers |
| --- | --- | --- | --- |
| `panel.{id}.notifications.index` | `GET /notifications` | — | `{notifications, unread}`, newest 30 |
| `panel.{id}.notifications.read` | `POST /notifications/read` | `id` optional | `{unread}` |
| `panel.{id}.notifications.clear` | `POST /notifications/clear` | `all` optional | `{unread}` |

`read` with no `id` marks everything read. `clear` removes read notifications, or everything when `all` is true.

Each serialized row is `{id, title, body, color, icon, actions, read, createdAt}`. A stored colour that is not one of the four falls back to `Info` rather than reaching a class name that does not exist, and an action that does not parse is dropped rather than rendered.

A user model that is not `Notifiable` gets a 403 at the door rather than a fatal three lines later. The trait is accepted whether or not the model declares `PanelNotifiable`.

The bell is turned off panel-wide with `Panel::notifications(false)`. The endpoints stay — a job can still write a notification a user reads in another panel — but the control is absent rather than present and empty.

The unread count is read on every panel request by `SharePanelData` rather than polled, so the bell is right after any navigation without a second round trip. When the `notifications` table does not exist yet, the count is `0` rather than a 500 on every page.

## Flash toasts

```php
return back()->with('success', 'Order approved.');
return redirect()->to($url)->with('error', 'That could not be saved.');
```

`ShareFlashToast` reads `error`, `warning`, `success`, `info` in that order — severity first, so an error is surfaced ahead of a success when a request somehow flashes both — and maps the first it finds onto `Inertia::flash('toast', ['type' => ..., 'message' => ...])`.

An explicit `Inertia::flash('toast', ...)` is never overwritten. A request with no session is passed through untouched.

Resource pages use this for their own messages: `CreateRecord::createdNotification()` and `EditRecord::savedNotification()` both return `['type' => ..., 'message' => ...]`, and returning `null` from either means the page says nothing.

Actions use it too — the action endpoints answer `back()->with('success', $action->getSuccessMessage())`.

## Emailed two-factor codes

```php
PandaPanel\Notifications\TwoFactorCode
```

A queued mail notification carrying one code. `PanelTwoFactorController::send()` issues the code through `PandaPanel\Auth\EmailCodeChallenge` and notifies the user with it:

```php
public function issue(Authenticatable $user): ?string;
public function verify(Authenticatable $user, string $code): bool;
public function pending(Authenticatable $user): bool;
public function secondsUntilNextSend(Authenticatable $user): int;
public function forget(Authenticatable $user): void;
```

The panel's own `two-factor` routes drive it; see [The email code challenge](../authentication/email-code-challenge.md).

## Testing

```php
fakePanelNotifications();                       // Event::fake([PanelNotificationSent::class])

// ... code under test ...

assertPanelNotificationSentTo($user, 'Your export is ready');
assertNoPanelNotifications();
assertPanelNotificationStoredFor($user, 'Your export is ready');
assertNoPanelNotificationsStoredFor($user);
```

The class behind them, for a test that would rather hold one:

```php
PandaPanel\Testing\TestsNotifications::fake(): void;
PandaPanel\Testing\TestsNotifications::assertSentTo(Authenticatable $user, ?string $title = null): void;
PandaPanel\Testing\TestsNotifications::assertNothingSent(): void;
PandaPanel\Testing\TestsNotifications::assertStoredFor(Authenticatable $user, ?string $title = null): void;
PandaPanel\Testing\TestsNotifications::assertNothingStoredFor(Authenticatable $user): void;
```

Two channels, asserted separately because they answer different questions — "was the user told" and "can they find it later" — and a notification can legitimately be one without the other.

The fake is narrow on purpose: `Event::fake()` with no argument would silence the model events the panel's integrations rely on.

## Notes

- **A toast is transient and that is correct for most messages.** It appears and it is gone, and if nobody was looking it never happened. `persistent()` is for the ones where that is wrong — "your export of 12,000 records finished".
- **Nothing executable crosses in any channel.** A notification action is a label and a URL the server produced.
- **The bell holds thirty rows.** A notification centre is not an archive; past a screenful nobody scrolls, and an unbounded query on a table that only grows is a page that gets slower forever.
- **A stored payload is untrusted input.** It was written a week ago by code that may no longer exist, so the controller re-validates every field it renders.
- **`Notification::make()` without a name generates a UUID.** The name is only an identifier; the title is what a person reads, and it defaults to the headline of the name.
- **Broadcasting off does not disable notifications.** `Panel::broadcasting(false)` stops the websocket; `Panel::notifications(false)` removes the bell. They are separate switches.

## See also

- [Toasts](../notifications/toast.md)
- [Flash bridge](../notifications/flash-bridge.md)
- [Database notifications](../notifications/database.md)
- [Notification centre](../notifications/notification-center.md)
- [Notification actions](../notifications/actions.md)
- [Broadcasting](../notifications/broadcasting.md)
- [Channel authorization](../notifications/channel-authorization.md)
- [Reverb and Echo](../notifications/reverb-echo.md)
- [Queues](../notifications/queues.md)
- [Testing notifications](../notifications/testing.md)
- [Contracts reference](contracts.md)
- [Actions reference](actions.md)
- [Events, jobs and controllers](events-jobs-controllers.md)
