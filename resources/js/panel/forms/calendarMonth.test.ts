/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Which month the date picker's calendar opens on.
 *
 * It opened on the current month whatever the field held, so editing a date
 * from another month started with a walk back to it — and where the bounds
 * excluded today, with a grid in which nothing could be chosen. The years
 * here are long past, so no day these tests run on can make them pass by
 * coincidence.
 */
vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), on: () => () => {} },
    usePage: () => ({ props: {}, url: '/' }),
}));

const { default: PanelDatePicker } =
    await import('@/panel/components/PanelDatePicker.vue');

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

async function openCalendar(props: Record<string, unknown>): Promise<void> {
    mounted.push(
        mount(PanelDatePicker, {
            attachTo: document.body,
            props: { modelValue: null, ...props },
        }),
    );

    document
        .querySelector<HTMLButtonElement>('button[aria-haspopup="dialog"]')
        ?.click();
    await flushPromises();
}

/** A day of the grid, if the month on screen has it and it can be chosen. */
function choosable(date: string): Element | null {
    return document.querySelector(
        `[data-value="${date}"]:not([data-disabled]):not([data-outside-view])`,
    );
}

describe('the month the calendar opens on', () => {
    it('is the month of the date held', async () => {
        await openCalendar({ modelValue: '2020-03-15' });

        expect(choosable('2020-03-15')).not.toBeNull();
    });

    it('is the last month allowed when today is past the bounds', async () => {
        await openCalendar({ min: '2020-01-01', max: '2020-03-20' });

        expect(choosable('2020-03-20')).not.toBeNull();
    });

    it('is the first month allowed when today is before the bounds', async () => {
        await openCalendar({ min: '2999-05-10' });

        expect(choosable('2999-05-10')).not.toBeNull();
    });
});
