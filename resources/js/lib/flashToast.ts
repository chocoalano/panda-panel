import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message, {
            // A link rather than a navigation: a toast that moved the page
            // would interrupt whatever the user did next.
            action: data.url
                ? {
                      label: data.urlLabel ?? 'Open',
                      onClick: () => {
                          window.location.href = data.url as string;
                      },
                  }
                : undefined,
        });
    });
}
