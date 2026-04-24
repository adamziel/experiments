// Node smoke test for zfs-wasm-real.
//
// The ZFS library uses pthreads internally (taskqs, txg sync, cv/mutex
// waits). When the Node main thread calls into the wasm module it can't
// block on pthread primitives — Emscripten unwinds with "unwind". So we
// load the module inside a worker_thread, where Atomics.wait() works
// and blocking is legal.
//
// Flow on the worker:
//   init -> pool_create -> ds_create(tankw/site)
//   write "hello world on main" to /file.txt on tankw/site
//   snap v1 -> clone tankw/site@v1 -> tankw/branch
//   write "on branch" to /file.txt on tankw/branch
//   read tankw/site, tankw/branch, tankw/site@v1 — each must match
//
// Run inside the Docker builder image:
//   scripts/docker-run.sh "cd build && node ../scripts/smoke-node.mjs"
//
// Exit 0 on success, nonzero otherwise.

import { Worker, isMainThread, parentPort, workerData } from 'node:worker_threads';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const BACKING = '/tmp/zfswasm-smoke.img';
const SIZE = 128 * 1024 * 1024;

if (isMainThread) {
    fs.rmSync(BACKING, { force: true });
    const fd = fs.openSync(BACKING, 'w');
    fs.ftruncateSync(fd, SIZE);
    fs.closeSync(fd);

    const worker = new Worker(__filename, {
        workerData: { backing: BACKING, cwd: process.cwd() },
    });
    worker.on('message', (m) => {
        if (m && m.type === 'log') console.log(m.line);
        else if (m && m.type === 'done') process.exit(m.code);
    });
    worker.on('error', (e) => { console.error('worker error:', e); process.exit(99); });
    worker.on('exit', (code) => {
        if (code !== 0) process.exit(code || 100);
    });
} else {
    runWorker().catch((e) => {
        parentPort.postMessage({ type: 'log', line: 'worker threw: ' + (e && e.stack || e) });
        parentPort.postMessage({ type: 'done', code: 90 });
    });
}

async function runWorker() {
    const { createRequire } = await import('node:module');
    const require = createRequire(import.meta.url);

    const log = (line) => parentPort.postMessage({ type: 'log', line });
    const die = (code) => parentPort.postMessage({ type: 'done', code });

    const ZfsWasm = require(path.join(workerData.cwd, 'zfswasm.js'));
    const mod = await ZfsWasm({
        print: (s) => parentPort.postMessage({ type: 'log', line: '[mod] ' + s }),
        printErr: (s) => parentPort.postMessage({ type: 'log', line: '[mod-err] ' + s }),
        onAbort: (reason) => parentPort.postMessage({ type: 'log', line: '[mod-abort] ' + reason }),
    });

    // Each call is awaited; `async: true` is a harmless no-op without
    // Asyncify but keeps the smoke test compatible if we later enable
    // it. The module still runs inside a node worker_thread, so
    // Atomics.wait() in pthread_cond_wait is legal.
    const ASYNC = { async: true };
    const init = (...a) => mod.ccall('zfswasm_init', 'number', [], a, ASYNC);
    const poolCreate = (p, f) => mod.ccall('zfswasm_pool_create', 'number', ['string','string'], [p,f], ASYNC);
    const poolExport = (p) => mod.ccall('zfswasm_pool_export', 'number', ['string'], [p], ASYNC);
    const dsCreate  = (n) => mod.ccall('zfswasm_ds_create', 'number', ['string'], [n], ASYNC);
    const snap      = (ds, s) => mod.ccall('zfswasm_snap', 'number', ['string','string'], [ds,s], ASYNC);
    const clone     = (s, n) => mod.ccall('zfswasm_clone', 'number', ['string','string'], [s,n], ASYNC);
    const fileWriteRaw = (ds, p, bp, len) => mod.ccall('zfswasm_file_write', 'number',
        ['string','string','number','number'], [ds,p,bp,len], ASYNC);
    const fileReadRaw = (ds, p, bp, cap, lp) => mod.ccall('zfswasm_file_read', 'number',
        ['string','string','number','number','number'], [ds,p,bp,cap,lp], ASYNC);

    async function fileWrite(ds, p, str) {
        const data = Buffer.from(str, 'utf8');
        const bufPtr = mod._malloc(data.length || 1);
        try {
            if (data.length) mod.HEAPU8.set(data, bufPtr);
            return await fileWriteRaw(ds, p, bufPtr, data.length);
        } finally { mod._free(bufPtr); }
    }

    async function fileRead(ds, p, cap = 4096) {
        const bufPtr = mod._malloc(cap);
        const outLenPtr = mod._malloc(4);
        try {
            const rc = await fileReadRaw(ds, p, bufPtr, cap, outLenPtr);
            if (rc !== 0) return { rc, data: null };
            const len = mod.HEAPU32[outLenPtr >> 2];
            const bytes = Buffer.from(mod.HEAPU8.subarray(bufPtr, bufPtr + len));
            return { rc: 0, data: bytes.toString('utf8') };
        } finally { mod._free(bufPtr); mod._free(outLenPtr); }
    }

    async function must(label, promise, code) {
        const rc = await promise;
        if (rc !== 0) { log(`${label} failed errno=${rc}`); die(code); throw new Error('abort'); }
    }

    log('[smoke] init…');
    await must('init', init(), 1);
    log('[smoke] pool_create tankw on ' + workerData.backing);
    await must('pool_create', poolCreate('tankw', workerData.backing), 2);
    log('[smoke] ds_create tankw/site');
    await must('ds_create', dsCreate('tankw/site'), 3);
    log('[smoke] write tankw/site:/file.txt');
    await must('write site', fileWrite('tankw/site', '/file.txt', 'hello world on main'), 4);
    log('[smoke] snap tankw/site@v1');
    await must('snap', snap('tankw/site', 'v1'), 5);
    log('[smoke] clone tankw/site@v1 -> tankw/branch');
    await must('clone', clone('tankw/site@v1', 'tankw/branch'), 6);
    log('[smoke] write tankw/branch:/file.txt');
    await must('write branch', fileWrite('tankw/branch', '/file.txt', 'on branch'), 7);

    log('[smoke] read tankw/site:/file.txt');
    const a = await fileRead('tankw/site', '/file.txt');
    log(`  rc=${a.rc} data=${JSON.stringify(a.data)}`);
    if (a.rc !== 0 || a.data !== 'hello world on main') { log('site mismatch'); return die(8); }

    log('[smoke] read tankw/branch:/file.txt');
    const b = await fileRead('tankw/branch', '/file.txt');
    log(`  rc=${b.rc} data=${JSON.stringify(b.data)}`);
    if (b.rc !== 0 || b.data !== 'on branch') { log('branch mismatch'); return die(9); }

    log('[smoke] read tankw/site@v1:/file.txt');
    const c = await fileRead('tankw/site@v1', '/file.txt');
    log(`  rc=${c.rc} data=${JSON.stringify(c.data)}`);
    if (c.rc !== 0 || c.data !== 'hello world on main') { log('snap mismatch'); return die(10); }

    log('[smoke] pool_export tankw');
    await must('export', poolExport('tankw'), 11);
    log('[smoke] OK');
    die(0);
}
