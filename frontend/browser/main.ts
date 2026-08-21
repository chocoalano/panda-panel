import { createApp, h } from 'vue';
import DataTable from '@/panel/tables/DataTable.vue';
import { rows, state, table } from './fixture';
import './fixture.css';

/**
 * The frozen-column fixture, mounted for a real browser to lay out.
 *
 * Everything freezing does is decided by widths a layout engine produces, so
 * the only place the behaviour can be asserted is one. `tests/browser` drives
 * this page; nothing else imports it and no application ever sees it.
 *
 * A recursive-update loop surfaces here as a Vue warning on the console,
 * which the runner reads and fails on — the unit tests assert the three
 * things that prevent the loop, and this asserts that it does not happen.
 */
const app = createApp({
    render: () => h(DataTable, { table, rows, state, bordered: true }),
});

app.config.errorHandler = (error: unknown) => {
    const errors = ((window as unknown as Record<string, unknown>).__errors ??
        []) as string[];

    errors.push(String((error as Error)?.stack ?? error));

    (window as unknown as Record<string, unknown>).__errors = errors;

    console.error(error);
};

app.config.warnHandler = (message: string) => {
    const warnings = ((window as unknown as Record<string, unknown>)
        .__vueWarnings ?? []) as string[];

    warnings.push(message);

    (window as unknown as Record<string, unknown>).__vueWarnings = warnings;

    console.warn(message);
};

app.mount('#app');
