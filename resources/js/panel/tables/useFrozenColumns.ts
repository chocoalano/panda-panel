import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { ComponentPublicInstance, CSSProperties, Ref } from 'vue';

/**
 * Where each frozen column has to sit, measured rather than declared.
 *
 * A `position: sticky` cell needs a `left` (or `right`) offset, and that
 * offset is the total width of every frozen column before it. Nothing on the
 * server knows those widths: a column may declare none at all, and the ones
 * that do can be overridden by content. So the widths are read from the cells
 * the browser actually laid out, and re-read whenever they change.
 *
 * The alternative — requiring `width()` on every frozen column and adding the
 * numbers up in PHP — was rejected because it is wrong exactly when it
 * matters. A column sized to its content is the normal case, and a table
 * whose frozen columns drifted one pixel out of line on a long name would be
 * worse than one that never froze anything.
 */

/** Frozen cells sit above ordinary ones; frozen headers above those. */
const BODY_Z = 10;
const HEADER_Z = 20;

/**
 * The share of the visible table frozen columns may occupy.
 *
 * Past this, freezing stops being a help and becomes the problem: on a phone,
 * three pinned columns can leave a strip too narrow to read the rest through,
 * and the user cannot scroll their way out of it because the pinned columns
 * are the ones in the way. Above the threshold the pinning is dropped and the
 * table behaves like an ordinary one — the honest degradation, and the reason
 * this is measured on every resize rather than decided once.
 */
const MAX_FROZEN_SHARE = 0.6;

export interface FrozenColumn {
    /** The key a cell identifies itself with — a column name, or a slot id. */
    key: string;
    side: 'start' | 'end';
}

/**
 * What Vue hands a template ref.
 *
 * `TableHead` is a component, so the ref is its instance rather than the
 * element — which is why the element has to be unwrapped from `$el` before
 * anything can be measured.
 */
type RefTarget = Element | ComponentPublicInstance | null;

export type UseFrozenColumnsReturn = {
    /** Registers the header cell whose width defines this column's. */
    measure: (key: string) => (target: RefTarget) => void;
    /** The sticky styles for one cell, or an empty object when it is not frozen. */
    styleFor: (key: string, header?: boolean) => CSSProperties;
    /** Whether this cell sits at the boundary, and should carry the divider. */
    isEdge: (key: string) => boolean;
    /** False while the frozen columns would take too much of the viewport. */
    active: Ref<boolean>;
};

export function useFrozenColumns(
    columns: Ref<FrozenColumn[]>,
    container: Ref<HTMLElement | null>,
): UseFrozenColumnsReturn {
    const widths = ref<Record<string, number>>({});
    const active = ref(true);

    const elements = new Map<string, Element>();

    let observer: ResizeObserver | null = null;

    function remeasure(): void {
        const next: Record<string, number> = {};

        for (const [key, element] of elements) {
            next[key] = element.getBoundingClientRect().width;
        }

        widths.value = next;

        const frozenWidth = columns.value.reduce(
            (total, column) => total + (next[column.key] ?? 0),
            0,
        );

        const available = container.value?.clientWidth ?? 0;

        // Nothing frozen is always fine; the guard is only about how much.
        active.value =
            frozenWidth === 0 ||
            available === 0 ||
            frozenWidth / available <= MAX_FROZEN_SHARE;
    }

    function elementOf(target: RefTarget): Element | null {
        if (target === null) {
            return null;
        }

        if (target instanceof Element) {
            return target;
        }

        const root = (target as { $el?: unknown }).$el;

        return root instanceof Element ? root : null;
    }

    function measure(key: string) {
        return (target: RefTarget): void => {
            const element = elementOf(target);

            const existing = elements.get(key);

            if (existing) {
                observer?.unobserve(existing);
                elements.delete(key);
            }

            if (element) {
                elements.set(key, element);
                observer?.observe(element);
            }

            remeasure();
        };
    }

    onMounted(() => {
        observer = new ResizeObserver(remeasure);

        for (const element of elements.values()) {
            observer.observe(element);
        }

        if (container.value) {
            observer.observe(container.value);
        }

        remeasure();
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });

    // A column hidden from the column manager, or a schema swapped by a
    // filter, changes which cells exist and therefore every offset after them.
    watch(columns, remeasure);

    function offsetFor(
        key: string,
    ): { side: 'start' | 'end'; offset: number } | null {
        const index = columns.value.findIndex((column) => column.key === key);

        if (index === -1) {
            return null;
        }

        const column = columns.value[index];

        // Leading columns accumulate from the left in order; trailing ones
        // accumulate from the right, so they are summed in reverse.
        const preceding =
            column.side === 'start'
                ? columns.value.slice(0, index)
                : columns.value.slice(index + 1);

        const offset = preceding
            .filter((other) => other.side === column.side)
            .reduce(
                (total, other) => total + (widths.value[other.key] ?? 0),
                0,
            );

        return { side: column.side, offset };
    }

    function styleFor(key: string, header = false): CSSProperties {
        if (!active.value) {
            return {};
        }

        const resolved = offsetFor(key);

        if (resolved === null) {
            return {};
        }

        return {
            position: 'sticky',
            [resolved.side === 'start' ? 'left' : 'right']:
                `${resolved.offset}px`,
            zIndex: header ? HEADER_Z : BODY_Z,
        };
    }

    function isEdge(key: string): boolean {
        if (!active.value) {
            return false;
        }

        const starts = columns.value.filter(
            (column) => column.side === 'start',
        );
        const ends = columns.value.filter((column) => column.side === 'end');

        return starts[starts.length - 1]?.key === key || ends[0]?.key === key;
    }

    return { measure, styleFor, isEdge, active };
}
