import type { Component } from 'vue';

type WidgetLoader = () => Promise<{ default: Component }>;

/**
 * Resolves a custom widget's Vue component.
 *
 * The glob is a build-time allowlist: only components that exist under
 * `resources/js/pages/Panels/**\/Widgets/` are in the bundle, so a name that
 * was not compiled in cannot be reached however it arrives. The name always
 * originates from a registered PHP widget class, never from request input,
 * and this lookup is the second lock rather than the first.
 *
 * The pattern is relative, not the `@` alias. Vite's dev server resolves an
 * aliased glob to nothing at all — `Object.assign({})` — while the
 * production build resolves it normally, so an aliased pattern means every
 * custom widget renders the fallback in development and works once built.
 */
const modules = import.meta.glob<{ default: Component }>(
    '../../pages/Panels/**/Widgets/*.vue',
);

/**
 * Keyed by the name PHP sends — the path below `pages/`, without extension.
 *
 * The map is derived from the real paths rather than the keys being
 * reconstructed from the name: Vite's key format follows the pattern as
 * written (`../../pages/...` here), and it differs between the dev server
 * and the build. A mismatch fails silently, as a widget that renders the
 * fallback forever.
 */
const PAGES_SEGMENT = '/pages/';

const componentsByName: Record<string, WidgetLoader> = Object.fromEntries(
    Object.entries(modules)
        .filter(([path]) => path.includes(PAGES_SEGMENT))
        .map(([path, loader]) => [
            path.slice(
                path.indexOf(PAGES_SEGMENT) + PAGES_SEGMENT.length,
                -'.vue'.length,
            ),
            loader,
        ]),
);

export function hasWidgetComponent(name: string): boolean {
    return name in componentsByName;
}

/**
 * Null for an unknown name. Callers render a neutral fallback rather than
 * throwing, so one mistyped component cannot take the dashboard down.
 */
export function resolveWidgetComponent(name: string): WidgetLoader | null {
    return componentsByName[name] ?? null;
}
