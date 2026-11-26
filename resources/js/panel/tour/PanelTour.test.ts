/**
 * @vitest-environment happy-dom
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

/**
 * The tour as a reader meets it: it opens once by itself, walks its steps,
 * and comes back only when asked.
 */
const { shared } = vi.hoisted(() => ({
    shared: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    shared.props = reactive<Record<string, unknown>>({});

    return {
        usePage: () => shared,
    };
});

const { default: PanelTour } = await import('@/panel/tour/PanelTour.vue');

const TRANSLATIONS = {
    tour: {
        start: 'Show tour',
        progress: 'Step :current of :total',
        next: 'Next',
        back: 'Back',
        skip: 'Skip',
        done: 'Done',
    },
};

function step(title: string, target: string | null = null) {
    return { title, description: `About ${title}`, target, side: 'bottom' };
}

function visit(tour: unknown): void {
    Object.assign(shared.props, {
        panel: { id: 'admin' },
        auth: { user: { id: 7 } },
        translations: TRANSLATIONS,
        page: { heading: 'Users', tour },
    });
}

const mounted: Array<{ unmount: () => void }> = [];

async function open() {
    const wrapper = mount(PanelTour, { attachTo: document.body });

    mounted.push(wrapper);
    await settle();

    return wrapper;
}

async function settle(): Promise<void> {
    await flushPromises();
    await nextTick();
    await flushPromises();
}

function button(label: string): HTMLButtonElement | undefined {
    return [...document.body.querySelectorAll('button')].find(
        (candidate) =>
            candidate.textContent?.trim() === label ||
            candidate.getAttribute('aria-label') === label,
    );
}

async function press(label: string): Promise<void> {
    const found = button(label);

    expect(found, `no button reads ${label}`).toBeDefined();

    found?.click();
    await settle();
}

const text = () => document.body.textContent ?? '';

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    globalThis.localStorage.clear();
});

describe('a page with no tour', () => {
    it('draws nothing at all', async () => {
        visit(null);

        const wrapper = await open();

        expect(wrapper.find('button').exists()).toBe(false);
        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
    });
});

describe('a page with a tour', () => {
    const tour = { key: 'page:users', steps: [step('First'), step('Second')] };

    it('opens by itself the first time', async () => {
        visit(tour);

        await open();

        expect(text()).toContain('First');
        expect(text()).toContain('Step 1 of 2');
    });

    it('stays closed the next time, until it is asked for', async () => {
        visit(tour);

        const first = await open();
        first.unmount();
        mounted.splice(0);
        document.body.innerHTML = '';

        await open();

        expect(text()).not.toContain('Step 1 of 2');

        await press('Show tour');

        expect(text()).toContain('Step 1 of 2');
    });

    it('walks forward, back, and out', async () => {
        visit(tour);
        await open();

        expect(button('Back')).toBeUndefined();

        await press('Next');
        expect(text()).toContain('About Second');
        expect(button('Next')).toBeUndefined();

        await press('Back');
        expect(text()).toContain('About First');

        await press('Next');
        await press('Done');
        expect(text()).not.toContain('Step ');
    });

    // A reader who skips halfway down a long form is not asking to be taken
    // back to the top of it, which is where the button is.
    it('hands focus back to its button without moving the page', async () => {
        visit(tour);
        await open();

        const trigger = button('Show tour') as HTMLButtonElement;
        const focus = vi.spyOn(trigger, 'focus');

        await press('Skip');

        expect(focus).toHaveBeenCalledWith({ preventScroll: true });
    });

    it('can be skipped, and starts over when asked again', async () => {
        visit(tour);
        await open();
        await press('Next');
        await press('Skip');

        expect(text()).not.toContain('Step ');

        await press('Show tour');

        expect(text()).toContain('Step 1 of 2');
    });

    it('closes and opens the next page’s own tour', async () => {
        visit(tour);
        await open();

        visit({ key: 'page:roles', steps: [step('Roles')] });
        await settle();

        expect(text()).toContain('About Roles');
        expect(text()).not.toContain('About First');

        visit(null);
        await settle();

        expect(text()).not.toContain('About Roles');
        expect(button('Show tour')).toBeUndefined();
    });
});

describe('where a step is shown', () => {
    function target(): void {
        const node = document.createElement('div');

        node.className = 'panel-table';
        node.getClientRects = () => [{}] as unknown as DOMRectList;
        node.scrollIntoView = vi.fn();
        document.body.append(node);
    }

    it('anchors to what it points at, with the rest dimmed', async () => {
        target();
        visit({ key: 'a', steps: [step('Table', '.panel-table')] });

        await open();

        expect(
            document.body.querySelector('[data-slot="popover-content"]'),
        ).not.toBeNull();
        expect(
            document.body.querySelector('[data-tour-highlight]'),
        ).not.toBeNull();
        expect(
            document.body.querySelector('[data-slot="dialog-content"]'),
        ).toBeNull();
    });

    it('is centred when what it points at is not there', async () => {
        visit({ key: 'a', steps: [step('Table', '.panel-table')] });

        await open();

        expect(
            document.body.querySelector('[data-slot="dialog-content"]'),
        ).not.toBeNull();
        expect(document.body.querySelector('[data-tour-highlight]')).toBeNull();
        expect(text()).toContain('About Table');
    });
});
