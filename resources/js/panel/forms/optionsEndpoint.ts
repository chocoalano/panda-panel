import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { SelectOption } from '@/panel/types/form';

/**
 * The endpoint a searchable select asks for the options its bounded first
 * page could not show.
 *
 * Provided by the form rather than threaded through every layout: a field can
 * sit inside a section inside a grid inside a wizard step, and passing this
 * down by prop would mean four components carrying a value none of them use.
 *
 * The URL already carries the form's context — which resource, which page,
 * and for a relation form which owner and operation. The client appends only
 * the field name and the search term, so a keystroke can never change which
 * form is being asked about.
 */
const OPTIONS_URL: InjectionKey<() => string | null> = Symbol(
    'panel.form.optionsUrl',
);

export function provideOptionsUrl(url: () => string | null): void {
    provide(OPTIONS_URL, url);
}

/**
 * Null when the form did not provide one, which is the honest answer for a
 * form rendered outside a resource: the field then shows the options it was
 * given and nothing more.
 */
export function useOptionsUrl(): () => string | null {
    return inject(OPTIONS_URL, () => null);
}

/**
 * Narrowed rather than asserted: this crosses from the server as untyped JSON
 * like every other payload, and a shape that does not match must leave the
 * list as it was instead of throwing inside the renderer.
 */
function toOptions(value: unknown): SelectOption[] | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const options = (value as { options?: unknown }).options;

    if (!Array.isArray(options)) {
        return null;
    }

    const narrowed: SelectOption[] = [];

    for (const option of options) {
        if (
            typeof option === 'object' &&
            option !== null &&
            typeof (option as SelectOption).value === 'string' &&
            typeof (option as SelectOption).label === 'string'
        ) {
            narrowed.push(option as SelectOption);
        }
    }

    return narrowed;
}

/**
 * Fetches the options for one field.
 *
 * Returns null on any failure, so a search that could not be answered leaves
 * the field showing what it already had rather than emptying it — an empty
 * list reads as "nothing matches", which is a different and wrong answer.
 */
export async function fetchOptions(
    url: string,
    field: string,
    search: string,
): Promise<SelectOption[] | null> {
    const target = new URL(url, window.location.origin);

    target.searchParams.set('field', field);
    target.searchParams.set('search', search);

    try {
        const response = await fetch(target.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        return response.ok ? toOptions(await response.json()) : null;
    } catch {
        return null;
    }
}
