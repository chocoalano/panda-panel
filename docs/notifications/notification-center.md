# Notification Center

The bell in the panel header, and the three endpoints behind it. It lists the signed-in user's stored notifications, marks them read, and clears them. You get it for free on every panel — this page is for turning it off, calling its endpoints yourself, and understanding why none of them needs a policy.

## A minimal working example

Send something persistent, then open the panel:

```php
<?php

use PandaPanel\Notifications\Notification;

Notification::make('export-ready')
    ->title('Your export is ready')
    ->body('1,204 records')
    ->success()
    ->persistent()
    ->send($user);
```

The badge on the bell reads 1 on the next panel request. Opening it fetches the list.

## The endpoints

Registered inside each panel's route group by `PanelRouteRegistrar`, under a `notifications` prefix, and served by `PandaPanel\Http\Controllers\PanelNotificationController`:

| Route name | Method | Path | Controller method |
| --- | --- | --- | --- |
| `panel.{id}.notifications.index` | `GET` | `/{panel path}/notifications` | `index` |
| `panel.{id}.notifications.read` | `POST` | `/{panel path}/notifications/read` | `read` |
| `panel.{id}.notifications.clear` | `POST` | `/{panel path}/notifications/clear` | `clear` |

They sit inside the panel's own middleware, so a panel that called `->auth()` has them behind `auth` like everything else.

```php
route('panel.admin.notifications.index');   // http://localhost/admin/notifications
```

JSON, not Inertia. The bell opens over whatever page is on screen, and a full page response would replace it.

### `index`

```php
public function index(Request $request): JsonResponse
```

The newest 30, and the unread count:

```jsonc
{
  "notifications": [
    {
      "id": "9b5f…",
      "title": "Your export is ready",
      "body": "1,204 records",
      "color": "success",
      "icon": "download",
      "actions": [
        { "name": "download", "label": "Download", "url": "/admin/exports/users.csv",
          "variant": "outline", "markAsRead": true, "newTab": false }
      ],
      "read": false,
      "createdAt": "2 minutes ago"
    }
  ],
  "unread": 1
}
```

`PanelNotificationController::LIMIT` is 30, a private constant rather than configuration. A notification centre is not an archive: past a screenful nobody scrolls, and an unbounded query on a table that only grows is a page that gets slower forever. Older rows are still in the table and still reachable with Eloquent.

`createdAt` is `diffForHumans()`, resolved on the server so the frontend does not carry a date library.

### `read`

```php
public function read(Request $request): JsonResponse
```

| Body | Effect |
| --- | --- |
| `{}` | every unread notification of this user gets `read_at = now()` |
| `{"id": "9b5f…"}` | that one, if it belongs to this user |
| `{"id": 42}` — a non-string | `422 Invalid notification.` |

```bash
curl -X POST https://example.test/admin/notifications/read \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"id":"9b5f7c2e-1a4d-4c1a-9c33-0f5b7c9d2e11"}'
```

```json
{ "unread": 0 }
```

An id belonging to another user matches nothing rather than 403s — the same outcome, and one fewer thing to leak.

### `clear`

```php
public function clear(Request $request): JsonResponse
```

| Body | Effect |
| --- | --- |
| `{}` or `{"all": false}` | deletes this user's **read** notifications |
| `{"all": true}` | deletes all of this user's notifications |

```bash
curl -X POST https://example.test/admin/notifications/clear \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"all":true}'
```

```json
{ "unread": 0 }
```

`$request->boolean('all')` is used, so `"true"`, `1` and `on` all count; anything else is false and only read rows go.

## Why there is no policy

Every query starts from `$request->user()`:

```php
$user->notifications()->latest()->limit(self::LIMIT)->get();
$user->notifications()->whereKey($id)->update(['read_at' => now()]);
```

There is no id a request could send that would reach somebody else's row. The scope *is* the authorization, which is why none of these endpoints has an authorization check to get wrong. The package's negative tests assert exactly this: one user's `id` posted by another marks nothing.

The one guard that does exist is at the door:

```php
abort_unless(
    $user instanceof PanelNotifiable || (is_object($user) && method_exists($user, 'unreadNotifications')),
    403,
);
```

A user model that is not `Notifiable` cannot answer any of these questions, and a 403 says so rather than fataling three lines later.

## A stored row is untrusted

`serialize()` treats the `data` column the way a request body is treated, because it is JSON somebody wrote a week ago:

| Stored value | What is served |
| --- | --- |
| `color` outside the four cases | `info`, and `info`'s icon |
| `title` that is not a string | `""` |
| `body` that is not a string | `null` |
| an action that does not parse | dropped from the list, not rendered |

```php
$data['color'] = 'chartreuse';   // serves color: 'info', icon: 'info'
$data['actions'] = ['nonsense']; // serves actions: []
```

Nothing throws, and nothing reaches a class name that does not exist in the bundle.

## The shared prop

`SharePanelData` puts the endpoints and the count on every panel request:

```php
'notifications' => [
    'enabled' => true,
    'indexUrl' => '/admin/notifications',
    'readUrl' => '/admin/notifications/read',
    'clearUrl' => '/admin/notifications/clear',
    'unread' => 1,
],
```

| Field | Type | Value when disabled |
| --- | --- | --- |
| `enabled` | `bool` | `false` |
| `indexUrl`, `readUrl`, `clearUrl` | `string\|null` | `null` |
| `unread` | `int` | `0` |

It is disabled when there is no current panel, when the panel called `->notifications(false)`, or when nobody is signed in.

The count is read on every panel request rather than polled, so the badge is right after any navigation without a second round trip. It is one indexed count on a table scoped to one user. If the `notifications` table does not exist yet, `QueryException` is caught and the answer is 0.

In Vue:

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { notifications } = usePanel();

notifications.value.unread;     // number
notifications.value.indexUrl;   // string | null
```

## Turning the bell off

```php
use PandaPanel\Core\Panel;

$panel->notifications(false);
```

| Method | Signature | Default |
| --- | --- | --- |
| `notifications` | `notifications(bool $notifications = true): self` | `true` |
| `hasNotifications` | `hasNotifications(): bool` | `true` |

The endpoints stay registered — a job can still write a notification the user reads in another panel — but the shared prop reports `enabled: false` and `PanelNotifications.vue` renders nothing at all rather than an empty control.

## How the component behaves

`resources/js/panel/components/PanelNotifications.vue`, mounted by `PanelHeader.vue`:

- The badge shows the shared count until a local action changes it, then the local number wins until the next navigation.
- The list is fetched only when the sheet is opened. A notification nobody looked at costs nothing.
- `window` event `panel:notification`, raised by `usePanelBroadcasting` when a persistent notification arrives, refetches the list if it is open and increments the badge if it is not. A refetch rather than pushing the payload in: the broadcast does not carry the row's id, and a list holding an entry that cannot be marked read would be worse than one request.
- "Mark all read" posts `{}` to `readUrl`; "Clear read" posts `{ all: false }` to `clearUrl`.
- Pressing an action marks the notification read first (unless the action said not to), then opens its URL — `window.open(url, '_blank', 'noopener')` for `newTab`, otherwise `router.visit(url)`. Unsafe schemes are ignored before either call.
- A response that does not parse leaves the list as it was and shows "Notifications could not be loaded."

Every request carries `Accept: application/json` and `credentials: 'same-origin'`; the two posts go through `postJson()` in `resources/js/panel/forms/http.ts`, which adds `X-Requested-With` and the CSRF token.

## Notes

- **The bell is per panel, the rows are not.** Every panel reads the same `notifications` table for the same user, so a notification written by a job during an `admin` request appears in the `app` panel's bell too.
- **`unread` in a response is authoritative.** All three endpoints answer with the recomputed count, which is what the component trusts after a mutation.
- **`read` with no id updates unread rows only**, so a repeated "mark all read" is cheap rather than a full table update.
- **Deleting is permanent.** `clear` is a `delete()`, not a soft delete. There is no undo, and no confirmation on the frontend beyond the button itself.

## See also

- [Database notifications](database.md) — the rows behind the bell
- [Notification actions](actions.md) — the buttons on each row
- [Toast notifications](toast.md) — the transient half
- [Broadcasting](broadcasting.md) — what raises `panel:notification`
- [Server metadata to Vue](../concepts/metadata-to-vue.md) — the shared props in full
- [Panel API reference](../panels/api.md)
- [Testing notifications](testing.md)
