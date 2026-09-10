import { createApp, defineAsyncComponent, h } from 'vue';
import { FIXTURES } from './fixtures';
import './fixture.css';

/**
 * The fixture host, mounted for a real browser to lay out.
 *
 * One build serves every fixture: `index.html?fixture=rich-editor` mounts the
 * rich editor, `?fixture=touch-targets` the controls U18 measures. Everything
 * mounted here is a *real* published component compiled against the *real*
 * stylesheet — a fixture that restyled anything would be measuring itself.
 *
 * Nothing else imports this page and no application ever sees it. Vue's
 * warnings and errors are collected on `window` so the driver can fail on a
 * recursive-update loop, which is a class of bug that produces no exception
 * and no wrong value — only a console line.
 */
const requested =
    new URL(window.location.href).searchParams.get('fixture') ??
    'frozen-columns';

const loader = FIXTURES[requested];

if (loader === undefined) {
    document.body.textContent = `Unknown fixture: ${requested}. Known: ${Object.keys(FIXTURES).join(', ')}`;
} else {
    const app = createApp({
        render: () => h(defineAsyncComponent(loader)),
    });

    app.config.errorHandler = (error: unknown) => {
        const errors = ((window as unknown as Record<string, unknown>)
            .__errors ?? []) as string[];

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
}
