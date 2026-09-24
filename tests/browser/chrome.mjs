/**
 * The smallest thing that can ask a layout engine a question.
 *
 * Extracted from `frozen-columns.mjs`, which had all of this inline for the
 * one behaviour it covers. Nothing about launching Chrome, speaking CDP or
 * serving a built fixture was specific to frozen columns, and two findings
 * now need the same machinery — so it is a module rather than a second copy.
 *
 * What it deliberately is not: a browser test runner. ADR 001 records that
 * this package ships none, and the two properties that keep that true are
 * kept here — **nothing installs** (Node's own WebSocket client speaks CDP,
 * and Chrome is the one already on the machine) and **nothing joins
 * `npm run ci`**. See `docs/contributing/testing.md`.
 */

import { spawn } from 'node:child_process';
import { createReadStream, existsSync, readFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const packageRoot = resolve(
    dirname(fileURLToPath(import.meta.url)),
    '../..',
);

const fixtureRoot = join(packageRoot, 'build/browser');

/** Where a Chrome is, on the platforms this is run on. */
const CHROME_CANDIDATES = [
    process.env.PANDA_CHROME,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
].filter(Boolean);

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.map': 'application/json',
};

/** Resolves once the process is gone, or after a second either way. */
function exited(child) {
    if (child === undefined || child.exitCode !== null) {
        return Promise.resolve();
    }

    return new Promise((done) => {
        const timer = setTimeout(done, 1000);

        child.once('exit', () => {
            clearTimeout(timer);
            done();
        });
    });
}

export function sleep(milliseconds) {
    return new Promise((done) => setTimeout(done, milliseconds));
}

function run(command, args) {
    return new Promise((done, fail) => {
        const child = spawn(command, args, {
            cwd: packageRoot,
            stdio: 'inherit',
        });

        child.on('error', fail);
        child.on('exit', (code) =>
            code === 0 ? done() : fail(new Error(`${command} exited ${code}`)),
        );
    });
}

/**
 * Builds the fixture page unless one is already there.
 *
 * Run through `node` with a raised heap rather than through `npx`, because the
 * fixture reaches the code editor field — every fixture that renders a form
 * does — and that pulls Monaco into the graph. Rollup transforming it needs
 * more than Node's default ~2 GB, and the failure is an out-of-memory abort
 * with no mention of which module was being transformed.
 */
export async function buildFixture({ force = false } = {}) {
    if (force || !existsSync(join(fixtureRoot, 'index.html'))) {
        await run(process.execPath, [
            '--max-old-space-size=4096',
            join(packageRoot, 'node_modules/vite/bin/vite.js'),
            'build',
            '--config',
            'frontend/browser/vite.config.ts',
        ]);
    }
}

function serve() {
    const server = createServer((request, response) => {
        const path = new URL(request.url, 'http://localhost').pathname;
        const file = join(fixtureRoot, path === '/' ? 'index.html' : path);

        if (!file.startsWith(fixtureRoot) || !existsSync(file)) {
            response.writeHead(404).end('not found');

            return;
        }

        response.writeHead(200, {
            'content-type': MIME[extname(file)] ?? 'application/octet-stream',
        });

        createReadStream(file).pipe(response);
    });

    // Port 0: the OS allocates, so two runs never collide.
    return new Promise((done) =>
        server.listen(0, '127.0.0.1', () =>
            done({ server, port: server.address().port }),
        ),
    );
}

async function launchChrome(profile, viewport) {
    const binary = CHROME_CANDIDATES.find((candidate) => existsSync(candidate));

    if (binary === undefined) {
        throw new Error(
            'No Chrome found. Set PANDA_CHROME to a Chrome or Chromium binary.',
        );
    }

    const chrome = spawn(
        binary,
        [
            '--headless=new',
            '--remote-debugging-port=0',
            `--user-data-dir=${profile}`,
            `--window-size=${viewport.width},${viewport.height}`,
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-gpu',
            '--hide-scrollbars',
            'about:blank',
        ],
        { stdio: ['ignore', 'ignore', 'ignore'] },
    );

    const portFile = join(profile, 'DevToolsActivePort');

    for (let attempt = 0; attempt < 100; attempt += 1) {
        if (existsSync(portFile)) {
            const [port] = readFileSync(portFile, 'utf8').split('\n');

            if (port) {
                return { chrome, port: Number(port) };
            }
        }

        await sleep(100);
    }

    chrome.kill();

    throw new Error('Chrome never reported a DevTools port.');
}

/** The smallest CDP client that can drive one page. */
async function connect(port) {
    const targets = await (
        await fetch(`http://127.0.0.1:${port}/json/list`)
    ).json();

    const page = targets.find((target) => target.type === 'page');

    if (page === undefined) {
        throw new Error('Chrome opened no page target.');
    }

    const socket = new WebSocket(page.webSocketDebuggerUrl);

    await new Promise((done, fail) => {
        socket.addEventListener('open', done, { once: true });
        socket.addEventListener('error', fail, { once: true });
    });

    let nextId = 0;
    const pending = new Map();
    const listeners = new Set();

    socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);

        if (message.id !== undefined) {
            const waiting = pending.get(message.id);

            pending.delete(message.id);

            if (message.error) {
                waiting.reject(new Error(message.error.message));
            } else {
                waiting.resolve(message.result);
            }

            return;
        }

        for (const listener of listeners) {
            listener(message);
        }
    });

    function send(method, params = {}) {
        nextId += 1;

        const id = nextId;

        return new Promise((done, fail) => {
            pending.set(id, { resolve: done, reject: fail });
            socket.send(JSON.stringify({ id, method, params }));
        });
    }

    function once(method) {
        return new Promise((done) => {
            const listener = (message) => {
                if (message.method === method) {
                    listeners.delete(listener);
                    done(message.params);
                }
            };

            listeners.add(listener);
        });
    }

    return { send, once, close: () => socket.close() };
}

/**
 * Opens one page and hands it to `body`, then tears everything down.
 *
 * The page object is deliberately small — go, evaluate, viewport, press,
 * type — because a wider surface is a runner, and a runner is the thing that
 * needs an ADR.
 */
export async function withPage(
    body,
    { viewport = { width: 1280, height: 800 } } = {},
) {
    const { server, port } = await serve();
    const profile = await mkdtemp(join(tmpdir(), 'panda-browser-'));

    let chrome;
    let client;

    try {
        const launched = await launchChrome(profile, viewport);

        chrome = launched.chrome;
        client = await connect(launched.port);

        await client.send('Page.enable');
        await client.send('Runtime.enable');
        await client.send('DOM.enable');

        // Without this, a headless page is never *the focused page*, so
        // `document.activeElement` is set and `:focus` matches nothing — and
        // every focus-styling assertion quietly measures the unfocused state.
        // It cost an hour to find; it is one line to prevent.
        await client.send('Emulation.setFocusEmulationEnabled', {
            enabled: true,
        });

        const page = {
            /** Resizes the viewport. Layout settles before this resolves. */
            async setViewport(next) {
                await client.send('Emulation.setDeviceMetricsOverride', {
                    width: next.width,
                    height: next.height,
                    deviceScaleFactor: 1,
                    mobile: Boolean(next.mobile),
                });

                await sleep(120);
            },

            /** Loads a fixture by name, then waits a frame for it to mount. */
            async go(fixture) {
                const loaded = client.once('Page.loadEventFired');

                await client.send('Page.navigate', {
                    url: `http://127.0.0.1:${port}/index.html?fixture=${fixture}`,
                });

                await loaded;
                await sleep(300);
            },

            /**
             * Emulates a media feature — `prefers-reduced-motion`, a colour
             * scheme, `forced-colors`.
             *
             * This is the one capability a DOM implementation cannot fake at
             * all: the preference has to change what the *stylesheet* resolves
             * to, and only an engine that applies stylesheets can do that.
             */
            async emulateMedia(features) {
                await client.send('Emulation.setEmulatedMedia', {
                    features: Object.entries(features).map(([name, value]) => ({
                        name,
                        value,
                    })),
                });

                await sleep(120);
            },

            /** Runs an expression in the page and returns its value. */
            async evaluate(expression) {
                const { result, exceptionDetails } = await client.send(
                    'Runtime.evaluate',
                    {
                        expression,
                        awaitPromise: true,
                        returnByValue: true,
                    },
                );

                if (exceptionDetails) {
                    throw new Error(
                        exceptionDetails.exception?.description ??
                            exceptionDetails.text,
                    );
                }

                return result.value;
            },

            /**
             * A real key press through the input pipeline, not a synthetic
             * `KeyboardEvent` — which is the whole point of being in a
             * browser: a dispatched event skips the focus and default-action
             * handling that a real press goes through.
             */
            async press(key, { code = key, text } = {}) {
                const shared = { key, code, windowsVirtualKeyCode: 0 };

                await client.send('Input.dispatchKeyEvent', {
                    ...shared,
                    type: text === undefined ? 'rawKeyDown' : 'keyDown',
                    text,
                });
                await client.send('Input.dispatchKeyEvent', {
                    ...shared,
                    type: 'keyUp',
                });
                await sleep(40);
            },

            /** Types text one character at a time, as a person would. */
            async type(value) {
                for (const character of value) {
                    await client.send('Input.dispatchKeyEvent', {
                        type: 'keyDown',
                        text: character,
                        key: character,
                    });
                    await client.send('Input.dispatchKeyEvent', {
                        type: 'keyUp',
                        key: character,
                    });
                }

                await sleep(60);
            },
        };

        await page.setViewport(viewport);

        return await body(page);
    } finally {
        client?.close();
        chrome?.kill();
        server.close();

        // Chrome writes to its profile on the way out, so a removal issued the
        // instant after `kill()` races it and throws ENOTEMPTY into an
        // otherwise green run. Wait for the process to actually be gone, and
        // treat a leftover temporary directory as what it is — litter in
        // `os.tmpdir()`, not a failed check.
        await exited(chrome);

        try {
            await rm(profile, { recursive: true, force: true, maxRetries: 5 });
        } catch {
            // Left for the operating system to reap.
        }
    }
}

/** Collects results and prints them the way the existing script does. */
export function reporter(title) {
    const checks = [];

    return {
        check(name, passed, detail) {
            checks.push({ name, passed, detail });
        },
        note(name, detail) {
            checks.push({ name, passed: null, detail });
        },
        finish() {
            let failed = 0;

            console.log(`\n${title}`);

            for (const { name, passed, detail } of checks) {
                const mark = passed === null ? '  ·' : passed ? '  ✓' : '  ✗';

                console.log(`${mark} ${name} — ${detail}`);

                failed += passed === false ? 1 : 0;
            }

            const asserted = checks.filter(
                (entry) => entry.passed !== null,
            ).length;

            console.log(
                `\n${asserted - failed} passed, ${failed} failed, ` +
                    `${checks.length - asserted} recorded`,
            );

            process.exitCode = failed === 0 ? 0 : 1;

            return failed;
        },
    };
}
