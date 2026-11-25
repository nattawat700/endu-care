import { spawn } from 'node:child_process';
import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const [browserPath, profilePath, htmlPath, pdfPath] = process.argv.slice(2);
const browser = spawn(browserPath, [
    '--headless',
    '--disable-background-networking',
    '--disable-extensions',
    '--disable-gpu',
    '--disable-sync',
    '--no-default-browser-check',
    '--no-first-run',
    '--remote-debugging-pipe',
    '--user-data-dir=' + profilePath,
    'about:blank',
], { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'], windowsHide: true });

const pending = new Map();
let messageId = 0;
let buffer = '';
let stopped = false;
const stop = () => {
    stopped = true;
    for (const request of pending.values()) request.reject(new Error('Browser connection closed'));
    pending.clear();
};

browser.once('error', stop);
browser.once('exit', stop);
browser.stdio[3].on('error', stop);
browser.stdio[4].on('error', stop);
browser.stdio[4].setEncoding('utf8').on('data', (chunk) => {
    buffer += chunk;
    for (let separator; (separator = buffer.indexOf('\0')) >= 0;) {
        const message = JSON.parse(buffer.slice(0, separator));
        buffer = buffer.slice(separator + 1);
        const request = pending.get(message.id);
        if (request) {
            pending.delete(message.id);
            if (message.error) request.reject(new Error('Browser command failed'));
            else request.resolve(message.result);
        }
    }
});

const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    if (stopped) {
        reject(new Error('Browser connection closed'));
        return;
    }
    const id = ++messageId;
    pending.set(id, { resolve, reject });
    browser.stdio[3].write(JSON.stringify({ id, method, params, sessionId }) + '\0');
});

const timeout = setTimeout(() => {
    stop();
    browser.kill();
}, 13000);

try {
    const url = pathToFileURL(htmlPath).href;
    const { targetId } = await send('Target.createTarget', { url });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    for (;;) {
        const state = await send('Runtime.evaluate', {
            expression: 'document.readyState === "complete" ? location.href : ""',
            returnByValue: true,
        }, sessionId);
        if (state.result.value === url) break;
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    await send('Runtime.evaluate', {
        expression: 'document.fonts.ready.then(() => Promise.all(Array.from(document.images, image => image.decode().catch(() => {})))).then(() => true)',
        awaitPromise: true,
        returnByValue: true,
    }, sessionId);
    const pdf = await send('Page.printToPDF', {
        printBackground: true,
        displayHeaderFooter: false,
        preferCSSPageSize: true,
    }, sessionId);
    await writeFile(pdfPath, Buffer.from(pdf.data, 'base64'));
} catch {
    process.exitCode = 1;
} finally {
    clearTimeout(timeout);
    browser.stdio[3].end();
    browser.kill();
}
