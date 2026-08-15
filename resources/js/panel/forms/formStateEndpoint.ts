import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import { postJson } from '@/panel/forms/http';
import type { FormDefinition, FormValues } from '@/panel/types/form';

/**
 * Where a `live()` field asks what the form should look like now.
 *
 * Provided rather than passed down, for the reason the options URL is: a
 * field can sit four layouts deep and every one of them would otherwise carry
 * a prop it does not use.
 *
 * The URL already names the resource, the page, and — when editing — the
 * record. The client sends only the values and which field changed, so a
 * keystroke can never change which form is being asked about.
 */
const FORM_STATE_URL: InjectionKey<() => string | null> = Symbol(
    'panel.form.formStateUrl',
);

export function provideFormStateUrl(url: () => string | null): void {
    provide(FORM_STATE_URL, url);
}

/**
 * Null when the form provided none, which is the honest answer for a form
 * rendered outside a resource: its live fields then behave as ordinary ones.
 */
export function useFormStateUrl(): () => string | null {
    return inject(FORM_STATE_URL, () => null);
}

/**
 * Narrowed rather than asserted. This crosses the wire as untyped JSON like
 * every other payload, and a shape that does not match must leave the form
 * showing what it already had.
 */
function toFormDefinition(payload: unknown): FormDefinition | null {
    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const form = (payload as { form?: unknown }).form;

    if (typeof form !== 'object' || form === null) {
        return null;
    }

    const schema = (form as { schema?: unknown }).schema;
    const columns = (form as { columns?: unknown }).columns;

    if (!Array.isArray(schema) || typeof columns !== 'number') {
        return null;
    }

    return form as FormDefinition;
}

/**
 * Asks the server to rebuild the schema against what has been typed.
 *
 * Returns null on any failure, so a request that could not be answered leaves
 * the form exactly as it was. Rebuilding is an enrichment — a select whose
 * options depend on another field, a total computed from three of them — and
 * losing it must not cost the user what they have entered.
 */
export async function fetchFormState(
    url: string,
    values: FormValues,
    changed: string,
    previous: unknown,
    signal?: AbortSignal,
): Promise<FormDefinition | null> {
    const payload = await postJson(
        url,
        { state: values, changed, previous },
        signal,
    );

    return payload === null ? null : toFormDefinition(payload);
}
