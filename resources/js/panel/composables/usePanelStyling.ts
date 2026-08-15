import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { usePanel } from '@/panel/composables/usePanel';

export type UsePanelStylingReturn = {
    /**
     * The panel's colours as inline custom properties, for the shell root.
     *
     * Custom properties rather than classes because that is how this
     * application's stylesheet is already written — a panel carries its own
     * theme without anything being compiled.
     */
    themeStyle: ComputedRef<Record<string, string>>;
    /**
     * The classes for one named part of the shell.
     *
     * Always includes the stable `panel-{name}` class, which is there whether
     * or not the panel said anything — that is what a stylesheet targets.
     * Anything the panel added for that hook follows it.
     */
    hook: (name: string) => string;
};

/**
 * The two ways a panel changes how it looks without shipping a component.
 *
 * Both are read from the shared panel props, so they apply to every page of
 * the panel and to none outside it.
 */
export function usePanelStyling(): UsePanelStylingReturn {
    const { panel } = usePanel();

    /**
     * Light and dark are merged rather than switched here.
     *
     * The dark values are written under a media-and-attribute guard by the
     * stylesheet; what an inline style can do is set the light values and let
     * the cascade handle the rest. A theme that needs to differ by scheme
     * belongs in a stylesheet, which is what `Panel::assets()` is for.
     */
    const themeStyle = computed<Record<string, string>>(() => {
        const theme = panel.value?.theme;

        if (theme === undefined) {
            return {};
        }

        const style: Record<string, string> = {};

        for (const [property, value] of Object.entries(theme.light ?? {})) {
            style[`--${property}`] = value;
        }

        return style;
    });

    function hook(name: string): string {
        const extra = panel.value?.cssHooks?.[name] ?? '';

        return extra === '' ? `panel-${name}` : `panel-${name} ${extra}`;
    }

    return { themeStyle, hook };
}
