# Import And Export Notifications

An import or an export tells the user what happened in one of two ways, and which one depends entirely on whether the work finished inside the request. A toast is transient — if nobody was looking it never happened, which is right for "Saved." and wrong for a job that finished ten minutes after the request that started it. So anything carrying a file also writes a persistent notification with a link.

Reach for this page when you want to change the wording, understand why a notification did or did not appear, or test one.

## A minimal working example

The wording of every message comes from the exporter or the importer, not from the action:

```php
final class UserExporter extends Exporter
{
    // …

    public static function completedMessage(int $records): string
    {
        return sprintf('%s users exported.', number_format($records));
    }
}
```

```php
final class UserImporter extends Importer
{
    // …

    public static function completedMessage(int $imported, int $failed): string
    {
        return $failed === 0
            ? sprintf('%d users imported.', $imported)
            : sprintf('%d users imported, %d rows rejected.', $imported, $failed);
    }
}
```

That one method is the title of the notification **and** the text of the toast, in both the inline and the queued path.

## What is sent, and when

| Situation | Toast | Persistent notification | Broadcast |
| --- | --- | --- | --- |
| export, inline | `success`, with a **Download** link | `export-ready` | no |
| export, queued (dispatch) | the action's success message | — | — |
| export, queued (finished) | — | `export-ready`, with **Download** | yes |
| export, queued (failed) | — | `export-failed` | yes |
| import, inline, clean | `success` | — | — |
| import, inline, with failures | `warning`, with **Download failed rows** | `import-finished` | no |
| import, queued (dispatch) | `info` — *Your import has started…* | — | — |
| import, queued (finished) | — | `import-finished`, with the report link when there is one | yes |
| import, queued (failed) | — | `import-failed` | yes |

Two rules explain the whole table:

- **A file is worth persisting.** A toast that appeared while the user was on another tab would leave them with a finished export and no way to find it.
- **A clean inline import says nothing extra.** The toast on the response already said it, and a bell that fills up with "imported 40 rows" is a bell nobody reads.

## The inline export

```php
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;

Notification::make('export-ready')
    ->title($exporter::completedMessage($result['records']))
    ->success()
    ->icon('download')
    ->persistent()
    ->broadcast(false)
    ->actions([
        NotificationAction::make('download')->label('Download')->url($url),
    ])
    ->send($user);

Inertia::flash('toast', [
    'type' => 'success',
    'message' => $exporter::completedMessage($result['records']),
    'url' => $url,
    'urlLabel' => 'Download',
]);
```

`broadcast(false)` is the detail worth noticing. The response carrying the toast is right here; pushing the same message over a websocket would show it twice. The row is still written, so the file is findable later from the notification centre.

## The queued export

```php
Notification::make('export-ready')
    ->title($exporter::completedMessage($result['records']))
    ->success()
    ->icon('download')
    ->persistent()
    ->actions([
        NotificationAction::make('download')->label('Download')->url($url),
    ])
    ->send($user);
```

Broadcast this time, because there is no response left to carry it. If the user still has the panel open, it arrives as a toast **and** increments the bell; if not, it is waiting.

On final failure, `RunPanelExport::failed()` sends:

```php
Notification::make('export-failed')
    ->title('Export failed')
    ->body($exception?->getMessage() ?? 'The file could not be written.')
    ->danger()
    ->icon('triangle-alert')
    ->persistent()
    ->send($user);
```

## The import

Inline, the notification is sent **only when rows failed**:

```php
Notification::make('import-finished')
    ->title($importer::completedMessage($result['imported'], $result['failed']))
    ->warning()
    ->persistent()
    ->broadcast(false)
    ->actions([
        NotificationAction::make('failed-rows')->label('Download failed rows')->url($url),
    ])
    ->send($user);
```

Queued, it is always sent, and its colour and icon follow the outcome — `success` and `check` when nothing failed, `warning` and `triangle-alert` when something did. The *Download failed rows* action is attached only when there is a report.

`RunPanelImport::failed()` sends `import-failed` with the reader's own message, which is what tells somebody how to fix their file: *"That file is not a readable spreadsheet."* is actionable; "The import failed" is not.

## The download links

Both actions are `PandaPanel\Notifications\NotificationAction`, which is a label and a URL and nothing else:

```php
NotificationAction::make('download')
    ->label('Download')
    ->url(route($panel->routeName('export-file'), [
        'file' => $result['file'],
        'exporter' => $exporter,
    ], absolute: false));
```

A notification is a row that may still be there next week, long after the page that sent it stopped existing, so what crosses is a link the server produced. Whatever the link points at authorizes for itself when it is followed — which is the only check still meaningful a week later, and why both download endpoints re-derive the directory from whoever is asking.

By default, following a notification action marks the notification read (`markAsRead(true)`), and the URL opens in the same tab (`url($url, newTab: false)`).

## The toast payload

The transient half is `Inertia::flash('toast', …)`, whose shape the frontend reads in `resources/js/lib/flashToast.ts`:

```php
Inertia::flash('toast', [
    'type' => 'success',    // success | info | warning | error
    'message' => 'Imported 998 rows.',
    'url' => '/admin/imports/failed-rows-2026-08-15-114233.csv?importer=…',
    'urlLabel' => 'Download failed rows',
]);
```

`url` and `urlLabel` render as the toast's action button; without a `url` there is no button. A toast never navigates on its own — that would interrupt whatever the user did next.

`PandaPanel\Http\Middleware\ShareFlashToast` maps Laravel's conventional flash keys (`error`, `warning`, `success`, `info`, in that order of precedence) onto the same channel, and **never overwrites an explicit toast**. That is what decides which message an export shows:

- an inline export flashes its own toast, so the download link wins;
- a queued export flashes nothing, so the action's `getSuccessMessage()` — `Your export is ready.` by default — is what appears.

Override it when the exporter queues:

```php
ExportAction::make(OrderExporter::class, OrderResource::class)
    ->successMessage('Preparing your export. You will be notified when it is ready.');
```

## Colours and icons

`Notification` uses `PandaPanel\Notifications\Enums\NotificationColor`, and a notification with no explicit icon takes its colour's own.

| Colour | Toast type | Default icon | Used by |
| --- | --- | --- | --- |
| `success` | `success` | `check` | export ready, clean queued import |
| `info` | `info` | `info` | — |
| `warning` | `warning` | `triangle-alert` | an import with failures |
| `danger` | `error` | `circle-alert` | — |

The export notifications override the icon to `download`; the failure notifications override it to `triangle-alert`.

## Where they end up

A persistent notification is written through Laravel's own `notifications` table by `PandaPanel\Notifications\PanelDatabaseNotification`, so `unreadNotifications` and `markAsRead()` work exactly as they do anywhere else. A broadcast one is a `PandaPanel\Notifications\PanelNotificationSent` event on the user's private channel, delivered as `.panel.notification`.

The user model has to be notifiable for the persistent half — `send()` calls `notify()` only when the method exists, so a user model without the trait has nowhere to store one.

## Testing

The package ships helpers for both halves:

```php
fakePanelNotifications();

// … run the export or import …

assertPanelNotificationSentTo($user, 'Export failed');
assertPanelNotificationStoredFor($user, 'Your export of 12 records is ready.');
assertNoPanelNotifications();
assertNoPanelNotificationsStoredFor($user);
```

The title argument is optional; without it, the assertion is about any notification.

## Notes

- **The record count in the message is the count that was written**, taken from `ExportRun::write()`, not from the count that decided whether to queue.
- **`completedMessage()` is called in the worker** for a queued run, so anything it depends on — a translation locale, a formatter's configuration — is the worker's, not the request's.
- **Nothing is emailed.** These are toasts and database notifications; sending mail is an application concern, and the notification centre is where the panel puts things.
- **A user deleted between the request and the job gets nothing at all.** Both jobs return silently when `Auth::getProvider()->retrieveById()` finds nobody.
- **Broadcasting is optional.** Without a configured broadcaster the persistent rows still arrive; only the live toast is missing.

## See also

- [ExportAction](export-action.md) and [ImportAction](import-action.md)
- [Queued exports](queued-exports.md) and [Queued imports](queued-imports.md)
- [Failure reports](failure-reports.md)
- [Toast notifications](../notifications/toast.md)
- [Database notifications](../notifications/database.md)
- [Notification actions](../notifications/actions.md)
- [The notification centre](../notifications/notification-center.md)
- [Flash bridge](../notifications/flash-bridge.md)
- [Testing notifications](../testing/notifications.md)
