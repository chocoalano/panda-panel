# Notification Actions

A notification action is a button on a stored notification: "Download", "Download failed rows", "View order". It is a **label and a URL**, never an action name to resolve. Reach for one whenever the notification is about something the user now has to open.

## A minimal working example

```php
<?php

use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;

Notification::make('export-ready')
    ->title('Your export is ready')
    ->success()
    ->persistent()
    ->actions([
        NotificationAction::make('download')
            ->label('Download')
            ->url('/admin/exports/users.csv'),
    ])
    ->send($user);
```

The bell now shows the notification with a Download button. Pressing it marks the notification read and navigates.

## Why it is not an `Action`

`PandaPanel\Actions\Action` is resolved against the schema that declared it — a table, a form, an infolist. A notification has no schema. It is a row in a table that may still be there next week, long after the page that sent it stopped existing, and the schema that could have resolved an action name may not exist by then.

So what crosses is a link the server produced, exactly as a link action's URL is. Whatever the link points at authorizes for itself when it is followed, which is the only check that can still be trusted a week later.

The practical consequences:

| Not supported | Why | What to do instead |
| --- | --- | --- |
| A closure to run | nothing executable may cross | point the URL at a route that does the work |
| A confirmation modal | there is no schema to open one from | confirm on the page the URL leads to |
| A form | same | the destination renders the form |
| Visibility or authorization callbacks | evaluated when? the row outlives the request | decide before sending; authorize at the URL |

## Every method on `NotificationAction`

`PandaPanel\Notifications\NotificationAction` is final, and every setter returns `self`.

| Method | Signature | Default |
| --- | --- | --- |
| `make` | `static make(string $name): self` | — |
| `label` | `label(string $label): self` | `Str::headline($name)` |
| `url` | `url(string $url, bool $newTab = false): self` | `null` |
| `variant` | `variant(ActionVariant $variant): self` | `ActionVariant::Outline` |
| `markAsRead` | `markAsRead(bool $mark = true): self` | `true` |
| `getName` | `getName(): string` | — |
| `toArray` | `toArray(): array` | — |
| `fromArray` | `static fromArray(array $data): ?array` | — |

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Notifications\NotificationAction;

// The label falls back to the headline of the name.
NotificationAction::make('failed-rows')->toArray()['label'];   // 'Failed Rows'

// A second tab, for a file the user should keep the panel open behind.
NotificationAction::make('report')
    ->label('Open report')
    ->url('/admin/imports/failed-rows.csv', newTab: true);

// A destructive-looking button, for a notification that leads somewhere final.
NotificationAction::make('review')
    ->label('Review')
    ->url('/admin/orders/42')
    ->variant(ActionVariant::Destructive);

// Leave it unread — for an action the user may take several times.
NotificationAction::make('retry')
    ->label('Retry')
    ->url('/admin/exports/retry/42')
    ->markAsRead(false);
```

### `url(string $url, bool $newTab = false)`

The URL and the tab behaviour are one call, because they are one decision. A URL is required for the button to do anything. Relative URLs and the `http`, `https`, `mailto`, and `tel` schemes are kept; unsafe schemes are stored as `null`, and the frontend applies the same guard before opening the action.

Build it with `route()` rather than by hand, and use `absolute: false` so the row survives a hostname change:

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->currentPanel();

NotificationAction::make('download')
    ->label('Download')
    ->url(route($panel->routeName('export-file'), [
        'file' => $result['file'],
        'exporter' => $exporter,
    ], absolute: false));
```

### `variant(ActionVariant $variant)`

`PandaPanel\Actions\Enums\ActionVariant` — the same closed set the rest of the panel uses, because each case maps to a shadcn button variant on the frontend:

| Case | Value |
| --- | --- |
| `ActionVariant::Default` | `default` |
| `ActionVariant::Secondary` | `secondary` |
| `ActionVariant::Outline` | `outline` (the default here) |
| `ActionVariant::Ghost` | `ghost` |
| `ActionVariant::Destructive` | `destructive` |

### `markAsRead(bool $mark = true)`

On by default: a notification you acted on is one you have seen, and leaving it unread would mean the count outlives the thing it counted. The frontend honours it before it navigates:

```ts
if (action.markAsRead) {
    await markRead(item.id);
}
```

## The stored shape

`toArray()` is what lands in the `data` column and what the centre serves back:

```php
[
    'name' => 'download',
    'label' => 'Download',
    'url' => '/admin/exports/users.csv',
    'variant' => 'outline',
    'markAsRead' => true,
    'newTab' => false,
]
```

## Reading one back

```php
public static function fromArray(array $data): ?array
```

`PanelNotificationController` calls this for every entry in a stored `actions` array. A persisted notification is JSON in a database, so what comes back is untrusted in exactly the way a request body is:

| Stored | Result |
| --- | --- |
| `name` or `label` missing, or not a string | `null` — the action is dropped, not rendered |
| `url` missing, empty, or not a string | `url: null` |
| `variant` not one of the five | `outline` |
| `markAsRead` anything but `true` | `false` |
| `newTab` anything but `true` | `false` |

```php
use PandaPanel\Notifications\NotificationAction;

NotificationAction::fromArray(['name' => 'a', 'label' => 'A', 'variant' => 'chartreuse']);
// ['name' => 'a', 'label' => 'A', 'url' => null, 'variant' => 'outline',
//  'markAsRead' => true, 'newTab' => false]

NotificationAction::fromArray(['label' => 'A']);   // null
```

Note the asymmetry that keeps it strict where it matters and lenient where it does not: `markAsRead` defaults to `true` when absent, but a value that is not literally `true` reads as `false`.

## How the panel itself uses them

Both queued jobs in the package attach exactly one action, and both are worth copying.

`RunPanelExport` — the file is the point of the notification:

```php
Notification::make('export-ready')
    ->title($exporter::completedMessage($result['records']))
    ->success()
    ->icon('download')
    ->persistent()
    ->actions([
        NotificationAction::make('download')
            ->label('Download')
            ->url(route($panel->routeName('export-file'), [
                'file' => $result['file'],
                'exporter' => $exporter,
            ], absolute: false)),
    ])
    ->send($user);
```

`RunPanelImport` — the failure report travels with the notification rather than being something to go looking for:

```php
if ($result['report'] !== null) {
    $notification->actions([
        NotificationAction::make('failed-rows')
            ->label('Download failed rows')
            ->url(route($panel->routeName('import-file'), [
                'file' => $result['report'],
                'importer' => $importer,
            ], absolute: false)),
    ]);
}
```

Both URLs point at controllers that build their directory from whoever is asking, so the only files reachable are that user's own. That is the pattern: the link is a link, and the destination is what authorizes.

## Gotchas

- **Actions never appear on a toast.** The client's toast reads `url` and `urlLabel`, which `Notification::toArray()` does not produce. A notification with actions and no `persistent()` shows a toast with no buttons and leaves nothing behind. For a clickable toast, dispatch `PandaPanel\Broadcasting\PanelNotification` with a `$url` and `$urlLabel`.
- **`actions()` replaces, it does not append.** `Notification::actions()` calls `array_values()` on what you hand it; calling it twice keeps only the second list.
- **A relative URL is the safer choice.** The row may be read from a different host than it was written on — a queued job on a worker, an application behind two domains. `absolute: false` keeps it valid.
- **Names are not validated.** Unlike `Action::make()`, `NotificationAction::make()` accepts any string: the name is never posted back to a route, so there is nothing to constrain.
- **A dead link stays a dead link.** An export file that has been cleaned up still has its notification. Decide how long you keep files, and clear old notifications to match.

## See also

- [Database notifications](database.md) — where actions are stored
- [Notification center](notification-center.md) — how a button is pressed
- [Toast notifications](toast.md) — and why buttons are not there
- [Actions overview](../actions/overview.md) — the schema-resolved kind
- [Import and export actions](../actions/import-export.md)
- [Queued notifications](queues.md)
