import { echo } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { usePanel } from '@/panel/composables/usePanel';
import type { FlashToast } from '@/types/ui';

/** Matches `PanelNotification::broadcastAs()`; the dot marks a custom name. */
const EVENT = '.panel.notification';

const TYPES: readonly FlashToast['type'][] = [
    'success',
    'info',
    'warning',
    'error',
];

interface PanelToast extends FlashToast {
    /** Something to open, for a job whose result is a file. */
    url: string | null;
    urlLabel: string | null;
}

/**
 * A payload arriving over a websocket has crossed the same boundary an HTTP
 * response does, and is validated the same way rather than trusted.
 */
function toFlashToast(payload: unknown): PanelToast | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const { type, message, url, urlLabel } = payload as Record<string, unknown>;

    if (typeof message !== 'string' || typeof type !== 'string') {
        return null;
    }

    return TYPES.includes(type as FlashToast['type'])
        ? {
              type: type as FlashToast['type'],
              message,
              url: typeof url === 'string' && url !== '' ? url : null,
              urlLabel: typeof urlLabel === 'string' ? urlLabel : null,
          }
        : null;
}

/**
 * Subscribes the panel to its own notifications.
 *
 * Nothing connects unless the server sent a channel, which it only does for a
 * signed-in user on a panel with broadcasting on. That is what makes
 * `broadcasting(false)` cost nothing rather than merely hiding a connection
 * that was opened anyway.
 *
 * Notifications land as the same toast a flash message does, so a job that
 * finishes long after its request reaches the user the same way.
 */
export function usePanelBroadcasting(): void {
    const { broadcasting } = usePanel();

    let subscribed: string | null = null;

    onMounted(() => {
        const channel = broadcasting.value.channel;

        if (channel === null) {
            return;
        }

        echo()
            .private(channel)
            .listen(EVENT, (payload: unknown) => {
                const notification = toFlashToast(payload);

                if (notification === null) {
                    return;
                }

                const persistent =
                    typeof payload === 'object' &&
                    payload !== null &&
                    (payload as { persistent?: unknown }).persistent === true;

                // A persisted notification is also a row in the bell. The
                // event is raised before the toast so the count is right even
                // if the toast is dismissed instantly.
                if (persistent) {
                    window.dispatchEvent(new CustomEvent('panel:notification'));
                }

                toast[notification.type](notification.message, {
                    // A link rather than a navigation: the toast may arrive
                    // while the user is in the middle of something else, and
                    // moving them somewhere they did not ask to go would be
                    // worse than the file waiting.
                    action:
                        notification.url === null
                            ? undefined
                            : {
                                  label: notification.urlLabel ?? 'Open',
                                  onClick: () => {
                                      window.location.href =
                                          notification.url as string;
                                  },
                              },
                });
            });

        subscribed = channel;
    });

    onBeforeUnmount(() => {
        if (subscribed !== null) {
            echo().leave(subscribed);
            subscribed = null;
        }
    });
}
