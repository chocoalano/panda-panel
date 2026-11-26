/**
 * @vitest-environment happy-dom
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    findTourTarget,
    normalizeTour,
    rememberTour,
    shouldAutoStart,
    tourStorageKey,
} from '@/panel/tour/tour';

afterEach(() => {
    globalThis.localStorage.clear();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('normalizeTour', () => {
    it('keeps a well formed tour', () => {
        const tour = {
            key: 'page:reports',
            steps: [
                {
                    title: 'Welcome',
                    description: 'Start here.',
                    target: '.panel-table',
                    side: 'left',
                },
            ],
        };

        expect(normalizeTour(tour)).toEqual(tour);
    });

    it.each([
        ['nothing', null],
        ['an absent prop', undefined],
        ['a tour with no key', { steps: [{ title: 'a' }] }],
        ['a tour with no steps', { key: 'a', steps: [] }],
        ['steps that are not a list', { key: 'a', steps: 'a' }],
        ['steps none of which has a title', { key: 'a', steps: [{}] }],
    ])('answers null for %s', (_, value) => {
        expect(normalizeTour(value)).toBeNull();
    });

    it('fills what a step leaves out and drops one with nothing to say', () => {
        expect(
            normalizeTour({
                key: 'a',
                steps: [{ title: 'a', side: 'sideways' }, { description: 'b' }],
            }),
        ).toEqual({
            key: 'a',
            steps: [
                {
                    title: 'a',
                    description: null,
                    target: null,
                    side: 'bottom',
                },
            ],
        });
    });
});

describe('remembering a tour', () => {
    it('keeps panels, users, and tours apart', () => {
        const keys = [
            tourStorageKey('admin', 1, 'page:a'),
            tourStorageKey('app', 1, 'page:a'),
            tourStorageKey('admin', 2, 'page:a'),
            tourStorageKey('admin', 1, 'page:b'),
            tourStorageKey('admin', null, 'page:a'),
        ];

        expect(new Set(keys).size).toBe(keys.length);
    });

    it('starts by itself until it has been remembered', () => {
        expect(shouldAutoStart('tour')).toBe(true);
        expect(rememberTour('tour')).toBe(true);
        expect(shouldAutoStart('tour')).toBe(false);
        expect(shouldAutoStart('another')).toBe(true);
    });

    // A browser that blocks site data throws on access. Starting anyway would
    // mean starting on every visit, forever.
    it('does not start by itself where it cannot be read', () => {
        vi.spyOn(globalThis.localStorage, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        expect(shouldAutoStart('tour')).toBe(false);
    });

    it('says so when it cannot be written', () => {
        vi.spyOn(globalThis.localStorage, 'setItem').mockImplementation(() => {
            throw new Error('full');
        });

        expect(rememberTour('tour')).toBe(false);
    });
});

describe('findTourTarget', () => {
    function element(visible: boolean): HTMLElement {
        const node = document.createElement('div');

        node.className = 'thing';
        node.getClientRects = () =>
            (visible ? [{}] : []) as unknown as DOMRectList;
        document.body.append(node);

        return node;
    }

    it('takes the first match that is on screen', () => {
        element(false);
        const shown = element(true);
        element(true);

        expect(findTourTarget('.thing')).toBe(shown);
    });

    it.each([
        ['no selector', null],
        ['a selector that matches nothing', '.absent'],
        ['a selector that is not one', '[['],
    ])('answers null for %s', (_, selector) => {
        element(true);

        expect(findTourTarget(selector)).toBeNull();
    });

    it('answers null when every match is hidden', () => {
        element(false);

        expect(findTourTarget('.thing')).toBeNull();
    });
});
