import { inject, onUnmounted, provide, shallowRef, watch } from 'vue';
import type { InjectionKey } from 'vue';

/**
 * Reasons a form must not be sent yet, held by the controls that know them.
 *
 * A form submits its values, and two things a reader can see are not in them:
 * text typed into a control that does not amount to a value, and a file still
 * on its way to the server. Both leave the value null, which an optional field
 * accepts — so without this, submitting saves "nothing" over what is on screen.
 */
export interface SubmitBlockers {
    /**
     * Blocks the submit with `message` against the field at `path`, until the
     * returned function is called.
     */
    hold: (path: string, message: string) => () => void;
    /** What is blocking now, keyed like server errors. Reactive. */
    current: () => Record<string, string>;
}

const BLOCKERS: InjectionKey<SubmitBlockers> = Symbol('panel.form.blockers');

export function createSubmitBlockers(): SubmitBlockers {
    const held = shallowRef<ReadonlyArray<{ path: string; message: string }>>(
        [],
    );

    return {
        hold(path, message) {
            const entry = { path, message };

            held.value = [...held.value, entry];

            return () => {
                held.value = held.value.filter((other) => other !== entry);
            };
        },
        current: () =>
            Object.fromEntries(
                held.value.map((entry) => [entry.path, entry.message]),
            ),
    };
}

export function provideSubmitBlockers(blockers: SubmitBlockers): void {
    provide(BLOCKERS, blockers);
}

/** Null outside a form, where there is no submit to hold back. */
export function useSubmitBlockers(): SubmitBlockers | null {
    return inject(BLOCKERS, null);
}

/**
 * Blocks for as long as `message` answers one, and no longer than the control
 * that asked is mounted: once it is gone, so is what it was holding.
 */
export function useSubmitBlock(
    path: () => string,
    message: () => string | null,
): void {
    const blockers = useSubmitBlockers();

    if (blockers === null) {
        return;
    }

    let release: (() => void) | null = null;

    watch(
        [path, message],
        ([at, why]) => {
            release?.();
            release = why === null ? null : blockers.hold(at, why);
        },
        { immediate: true },
    );

    onUnmounted(() => release?.());
}
