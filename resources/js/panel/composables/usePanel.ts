import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type {
    PanelBroadcasting,
    PanelDefinition,
    PanelNotificationSettings,
    PanelSearchSettings,
    PanelShellSettings,
    PanelSummary,
    PanelTenancy,
} from '@/panel/types/panel';

/**
 * Content width tokens map to literal classes.
 *
 * Building the class by interpolation (`max-w-${token}`) would make it
 * invisible to the Tailwind compiler and it would silently not exist in the
 * bundle.
 */
const MAX_WIDTH_CLASSES = {
    full: 'max-w-full',
    '7xl': 'max-w-7xl',
    '6xl': 'max-w-6xl',
    '5xl': 'max-w-5xl',
    '4xl': 'max-w-4xl',
    '3xl': 'max-w-3xl',
} as const;

type MaxContentWidth = keyof typeof MAX_WIDTH_CLASSES;

const DEFAULT_MAX_WIDTH_CLASS = MAX_WIDTH_CLASSES.full;

function resolveMaxWidthClass(token: string | null): string {
    if (token !== null && token in MAX_WIDTH_CLASSES) {
        return MAX_WIDTH_CLASSES[token as MaxContentWidth];
    }

    return DEFAULT_MAX_WIDTH_CLASS;
}

export type UsePanelReturn = {
    panel: ComputedRef<PanelDefinition | null>;
    hasPanel: ComputedRef<boolean>;
    maxContentWidthClass: ComputedRef<string>;
    panels: ComputedRef<PanelSummary[]>;
    canSwitchPanels: ComputedRef<boolean>;
    broadcasting: ComputedRef<PanelBroadcasting>;
    search: ComputedRef<PanelSearchSettings>;
    notifications: ComputedRef<PanelNotificationSettings>;
    shell: ComputedRef<PanelShellSettings>;
    tenancy: ComputedRef<PanelTenancy | null>;
    canSwitchTenants: ComputedRef<boolean>;
};

/**
 * Reads the panel shared props. Every consumer must tolerate a null panel,
 * because the shell can render during a navigation that leaves the panel.
 */
export function usePanel(): UsePanelReturn {
    const page = usePage();

    const panel = computed(() => page.props.panel);

    /**
     * Empty outside a panel, and holding a single entry when the user may
     * enter only one, so the switcher can hide itself rather than offering a
     * move to where the user already is.
     */
    const panels = computed(() => page.props.panels ?? []);

    /** Null for every panel that declared no tenancy, which is most of them. */
    const tenancy = computed(() => page.props.tenancy ?? null);

    return {
        panel,
        hasPanel: computed(() => panel.value !== null),
        maxContentWidthClass: computed(() =>
            resolveMaxWidthClass(panel.value?.maxContentWidth ?? null),
        ),
        panels,
        canSwitchPanels: computed(() => panels.value.length > 1),
        broadcasting: computed(
            () => page.props.broadcasting ?? { enabled: false, channel: null },
        ),
        search: computed(
            () =>
                page.props.search ?? {
                    enabled: false,
                    url: null,
                    debounce: 300,
                    keyBindings: [],
                },
        ),
        notifications: computed(
            () =>
                page.props.notifications ?? {
                    enabled: false,
                    indexUrl: null,
                    readUrl: null,
                    clearUrl: null,
                    unread: 0,
                },
        ),
        tenancy,
        /**
         * Two conditions, and the second is the one worth stating: a tenant
         * with no URL is a destination the switcher cannot send anybody to,
         * so a panel that never called `tenantUrlUsing()` gets no switcher
         * rather than a broken one.
         */
        canSwitchTenants: computed(
            () =>
                (tenancy.value?.available.length ?? 0) > 1 &&
                (tenancy.value?.available ?? []).some(
                    (entry) => entry.url !== null,
                ),
        ),
        // Everything on by default: the shell a panel has said nothing about
        // is the whole shell.
        shell: computed(
            () =>
                panel.value?.shell ?? {
                    navigation: true,
                    topbar: true,
                    breadcrumbs: true,
                    topbarComponent: null,
                    userMenuItems: [],
                },
        ),
    };
}
