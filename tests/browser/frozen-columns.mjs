#!/usr/bin/env node

/**
 * Frozen columns, in a browser, on a phone.
 *
 * The package has no browser test runner and ADR 001 says so on purpose —
 * this is not one. It is a script: it builds the fixture in
 * `frontend/browser`, serves it, drives a Chrome that is already on the
 * machine over the DevTools protocol, and asks the layout engine the four
 * questions nothing else can answer.
 *
 *   1. does the table actually overflow at 360px,
 *   2. do the pinned headers stay `position: sticky` there,
 *   3. do they hold their place while the lane scrolls under them,
 *   4. is a pinned cell opaque, and does the last one carry its seam.
 *
 * Every one of those is a width the browser decided. A unit test can assert
 * the arithmetic over those widths — `useFrozenColumns.test.ts` does — and
 * can assert nothing about whether the browser agrees.
 *
 *     node tests/browser/frozen-columns.mjs
 *
 * No dependency, and none to install: Node's own WebSocket client speaks
 * CDP, and Chrome is the one already installed. `PANDA_CHROME` names a
 * different binary.
 */

import { spawn } from 'node:child_process';
import { createReadStream, existsSync, readFileSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const fixtureRoot = join(packageRoot, 'build/browser');

const VIEWPORT = { width: 360, height: 800 };

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

function run(command, args) {
    return new Promise((resolveRun, rejectRun) => {
        const child = spawn(command, args, {
            cwd: packageRoot,
            stdio: 'inherit',
        });

        child.on('error', rejectRun);
        child.on('exit', (code) =>
            code === 0
                ? resolveRun()
                : rejectRun(new Error(`${command} exited ${code}`)),
        );
    });
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

    return new Promise((resolveServer) => {
        server.listen(0, '127.0.0.1', () =>
            resolveServer({ server, port: server.address().port }),
        );
    });
}

function sleep(milliseconds) {
    return new Promise((resolveSleep) => setTimeout(resolveSleep, milliseconds));
}

async function launchChrome(profile) {
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
            `--window-size=${VIEWPORT.width},${VIEWPORT.height}`,
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

    await new Promise((resolveOpen, rejectOpen) => {
        socket.addEventListener('open', resolveOpen, { once: true });
        socket.addEventListener('error', rejectOpen, { once: true });
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

        return new Promise((resolveSend, rejectSend) => {
            pending.set(id, { resolve: resolveSend, reject: rejectSend });
            socket.send(JSON.stringify({ id, method, params }));
        });
    }

    function once(method) {
        return new Promise((resolveEvent) => {
            const listener = (message) => {
                if (message.method === method) {
                    listeners.delete(listener);
                    resolveEvent(message.params);
                }
            };

            listeners.add(listener);
        });
    }

    return { send, once, close: () => socket.close() };
}

/**
 * What the page is asked, in the page.
 *
 * Nothing here knows a column name: it reads the pinned cells out of the
 * markup by the classes the renderer writes, so the same script covers any
 * table the package draws.
 */
const PROBE = `(async () => {
    const lane = document.querySelector('[data-slot="table-container"]');
    const headerRow = document.querySelector('thead tr');
    const heads = [...headerRow.querySelectorAll('th')];

    const pinnedStart = heads.filter(
        (head) => getComputedStyle(head).position === 'sticky'
            && head.style.left !== ''
    );
    const pinnedEnd = heads.filter(
        (head) => getComputedStyle(head).position === 'sticky'
            && head.style.right !== ''
    );
    const scrolling = heads.filter((head) => !pinnedStart.includes(head) && !pinnedEnd.includes(head));

    const frame = () => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));

    const before = heads.map((head) => head.getBoundingClientRect().x);

    lane.scrollLeft = 240;
    await frame();

    const after = heads.map((head) => head.getBoundingClientRect().x);

    const bodyCell = document.querySelector('tbody tr td.panel-table-frozen-cell');
    const bodyRow = bodyCell?.closest('tr');
    const lastStart = pinnedStart[pinnedStart.length - 1];
    const edge = lastStart ? getComputedStyle(lastStart, '::after') : null;

    return {
        viewport: { width: window.innerWidth, height: window.innerHeight },
        overflows: lane.scrollWidth > lane.clientWidth,
        scrollWidth: lane.scrollWidth,
        scrolled: lane.scrollLeft,
        laneWidth: lane.clientWidth,
        pinnedStartCount: pinnedStart.length,
        pinnedEndCount: pinnedEnd.length,
        startPositions: pinnedStart.map((head) => getComputedStyle(head).position),
        startLabels: pinnedStart.map((head) => head.textContent.trim()),
        startOffsets: pinnedStart.map((head) => head.style.left),
        startHeld: pinnedStart.map((head) => {
            const index = heads.indexOf(head);
            return Math.abs(after[index] - before[index]) < 0.5;
        }),
        scrollingMoved: scrolling.some((head) => {
            const index = heads.indexOf(head);
            return Math.abs(after[index] - before[index]) > 1;
        }),
        headerBackground: pinnedStart.map((head) => getComputedStyle(head).backgroundColor),
        rowBackground: bodyRow ? getComputedStyle(bodyRow).backgroundColor : null,
        cellBackground: bodyCell ? getComputedStyle(bodyCell).backgroundColor : null,
        cellPlate: bodyCell ? getComputedStyle(bodyCell, '::before').backgroundColor : null,
        edgeContent: edge ? edge.content : null,
        edgeWidth: edge ? edge.width : null,
        edgeBorder: edge ? edge.borderLeftWidth : null,
        warnings: window.__vueWarnings ?? [],
    };
})()`;

const checks = [];

function check(name, passed, detail) {
    checks.push({ name, passed, detail });
}

async function main() {
    if (!existsSync(join(fixtureRoot, 'index.html')) || process.argv.includes('--build')) {
        await run('npx', [
            'vite',
            'build',
            '--config',
            'frontend/browser/vite.config.ts',
        ]);
    }

    const { server, port } = await serve();
    const profile = await mkdtemp(join(tmpdir(), 'panda-frozen-'));

    let chrome;
    let client;

    try {
        const launched = await launchChrome(profile);

        chrome = launched.chrome;
        client = await connect(launched.port);

        await client.send('Page.enable');
        await client.send('Runtime.enable');
        await client.send('Emulation.setDeviceMetricsOverride', {
            ...VIEWPORT,
            deviceScaleFactor: 1,
            mobile: true,
        });

        const loaded = client.once('Page.loadEventFired');

        await client.send('Page.navigate', {
            url: `http://127.0.0.1:${port}/index.html`,
        });

        await loaded;

        // The first measurement lands a frame after mount.
        await sleep(300);

        const { result, exceptionDetails } = await client.send(
            'Runtime.evaluate',
            {
                expression: PROBE,
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

        const page = result.value;

        if (process.argv.includes('--json')) {
            console.log(JSON.stringify(page, null, 2));
        }

        check(
            `renders at ${VIEWPORT.width}px`,
            page.viewport.width === VIEWPORT.width,
            `viewport ${page.viewport.width}px`,
        );
        check(
            'the table overflows its lane',
            page.overflows === true,
            `scrollWidth ${page.scrollWidth}px > clientWidth ${page.laneWidth}px`,
        );
        check(
            'two leading columns are pinned',
            page.pinnedStartCount >= 2,
            `${page.pinnedStartCount} pinned: ${page.startLabels.join(', ')}`,
        );
        check(
            'both are position: sticky',
            page.startPositions.slice(0, 2).every((value) => value === 'sticky'),
            page.startPositions.join(', '),
        );
        check(
            'the first sits at the edge and the second behind it',
            page.startOffsets[0] === '0px' &&
                parseFloat(page.startOffsets[1]) > 0,
            page.startOffsets.join(', '),
        );
        check(
            'the lane scrolled under them',
            page.scrolled > 0 && page.scrollingMoved === true,
            `scrollLeft ${page.scrolled}`,
        );
        check(
            'neither pinned header moved',
            page.startHeld.slice(0, 2).every(Boolean),
            page.startHeld.join(', '),
        );
        check(
            'the trailing side is pinned independently',
            page.pinnedEndCount >= 1,
            `${page.pinnedEndCount} pinned to the end`,
        );
        check(
            'a pinned header is opaque',
            page.headerBackground.every(
                (colour) => colour !== 'rgba(0, 0, 0, 0)',
            ),
            page.headerBackground.join(', '),
        );
        check(
            'a pinned body cell inherits an opaque row background',
            page.cellBackground !== 'rgba(0, 0, 0, 0)' &&
                page.rowBackground !== 'rgba(0, 0, 0, 0)',
            `row ${page.rowBackground}, cell ${page.cellBackground}`,
        );
        check(
            'and is backed by an opaque plate',
            page.cellPlate !== 'rgba(0, 0, 0, 0)',
            String(page.cellPlate),
        );
        check(
            'the last pinned column carries the seam',
            page.edgeContent === '""' &&
                parseFloat(page.edgeWidth) > 0 &&
                parseFloat(page.edgeBorder) > 0,
            `content ${page.edgeContent}, width ${page.edgeWidth}, border ${page.edgeBorder}`,
        );
        check(
            'no recursive update warning',
            page.warnings.every(
                (warning) => !warning.includes('recursive updates'),
            ),
            page.warnings.length === 0
                ? 'no warnings'
                : page.warnings.join(' | '),
        );
    } finally {
        client?.close();
        chrome?.kill();
        server.close();
        await rm(profile, { recursive: true, force: true });
    }

    let failed = 0;

    for (const { name, passed, detail } of checks) {
        console.log(`${passed ? '  ✓' : '  ✗'} ${name} — ${detail}`);

        failed += passed ? 0 : 1;
    }

    console.log(
        `\n${checks.length - failed} passed, ${failed} failed at ${VIEWPORT.width}×${VIEWPORT.height}`,
    );

    process.exitCode = failed === 0 ? 0 : 1;
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
