import type { NavigationGroup } from '@/panel/types/navigation';
import type {
    PanelBroadcasting,
    PanelDefinition,
    PanelNotificationSettings,
    PanelSearchSettings,
    PanelSummary,
    PanelTenancy,
} from '@/panel/types/panel';
import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            // Panel props are present only on panel routes: null and empty
            // everywhere else, never absent.
            panel: PanelDefinition | null;
            navigation: NavigationGroup[];
            panels: PanelSummary[];
            broadcasting: PanelBroadcasting;
            search: PanelSearchSettings;
            notifications: PanelNotificationSettings;
            // Null for a panel that declared no tenancy, which is most of
            // them — so the check reads `tenancy === null` rather than
            // testing an empty list.
            tenancy: PanelTenancy | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
