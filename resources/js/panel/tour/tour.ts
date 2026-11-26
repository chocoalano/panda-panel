/**
 * The tour's decisions, kept out of the component so they can be tested
 * without mounting one: what a payload means, whether it has been seen, and
 * which element a step is about.
 */
import type {
    TourDefinition,
    TourSide,
    TourStepDefinition,
} from '@/panel/types/tour';

const SIDES: readonly TourSide[] = ['top', 'right', 'bottom', 'left'];

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function toStep(value: unknown): TourStepDefinition | null {
    if (!isRecord(value) || typeof value.title !== 'string') {
        return null;
    }

    return {
        title: value.title,
        description:
            typeof value.description === 'string' ? value.description : null,
        target: typeof value.target === 'string' ? value.target : null,
        side: SIDES.find((side) => side === value.side) ?? 'bottom',
    };
}

/**
 * Null for anything there is nothing to open from, which includes every page
 * that declared no tour.
 */
export function normalizeTour(value: unknown): TourDefinition | null {
    if (
        !isRecord(value) ||
        typeof value.key !== 'string' ||
        !Array.isArray(value.steps)
    ) {
        return null;
    }

    const steps = value.steps
        .map(toStep)
        .filter((step): step is TourStepDefinition => step !== null);

    return steps.length === 0 ? null : { key: value.key, steps };
}

/**
 * Per user as well as per panel: two people sharing a browser have each not
 * seen it.
 */
export function tourStorageKey(
    panel: string,
    user: string | number | null,
    tour: string,
): string {
    return `panda-panel:tour:${panel}:${user ?? 'guest'}:${tour}`;
}

/**
 * Whether a tour should open without being asked. False where storage cannot
 * be read — a browser set to block site data throws on access — because a
 * tour that cannot be remembered would open on every visit.
 */
export function shouldAutoStart(storageKey: string): boolean {
    try {
        return globalThis.localStorage.getItem(storageKey) === null;
    } catch {
        return false;
    }
}

/** False when it could not be written. */
export function rememberTour(storageKey: string): boolean {
    try {
        globalThis.localStorage.setItem(storageKey, 'seen');

        return true;
    } catch {
        return false;
    }
}

/**
 * The first element on screen that a step's selector matches.
 *
 * On screen, because a table keeps hidden twins of its controls for other
 * widths, and pointing at one of those points at nothing.
 */
export function findTourTarget(selector: string | null): HTMLElement | null {
    if (selector === null) {
        return null;
    }

    try {
        return (
            Array.from(document.querySelectorAll<HTMLElement>(selector)).find(
                (element) => element.getClientRects().length > 0,
            ) ?? null
        );
    } catch {
        // An application's own `selector()` that CSS cannot parse.
        return null;
    }
}
