// Node smoke test for zfs-wasm-real.
//
// Threading note
// --------------
// The ZFS module is built with -sPROXY_TO_PTHREAD=1. All ZFS work
// runs on a dedicated pthread (the "ZFS worker"). Exported
// zfswasm_*_begin functions are non-blocking enqueues; we then
// setTimeout-poll zfswasm_poll() until the worker signals completion,
// and zfswasm_result() returns the int rc. This is the only pattern
// that works: the JS thread that instantiated the module is
// Emscripten's "main runtime thread" and is not allowed to block on
// pthread condvars — a direct blocking call into ZFS code unwinds.

import path from 'node:path';
import { pathToFileURL } from 'node:url';

const BACKING = '/tmp/zfswasm-smoke.img';
const SIZE = 128 * 1024 * 1024;

const log = (line) => console.log(line);

// The wasm module is built with -sEXPORT_ES6=1 so the browser demo
// can `import` it. Use the matching dynamic-ESM form here.
const modUrl = pathToFileURL(path.join(process.cwd(), 'build', 'zfswasm.js'));
const { default: ZfsWasm } = await import(modUrl.href);
const mod = await ZfsWasm({
    print: (s) => console.log('[mod] ' + s),
    printErr: (s) => console.log('[mod-err] ' + s),
    onAbort: (reason) => console.log('[mod-abort] ' + reason),
});

// Pre-populate the pool's backing file inside the wasm virtual FS.
mod.FS.mkdirTree('/tmp');
mod.FS.writeFile(BACKING, new Uint8Array(SIZE));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const _poll    = mod.cwrap('zfswasm_poll', 'number', []);
const _result  = mod.cwrap('zfswasm_result', 'number', []);

async function runOp(beginFn, ...args) {
    const rc = beginFn(...args);
    if (rc !== 0) throw new Error(`enqueue failed: ${rc}`);
    let delay = 1;
    while (_poll() === 0) {
        await sleep(delay);
        if (delay < 50) delay++;
    }
    return _result();
}

const _init_begin = mod.cwrap('zfswasm_init_begin', 'number', []);
const _pc_begin   = mod.cwrap('zfswasm_pool_create_begin', 'number', ['string','string']);
const _pe_begin   = mod.cwrap('zfswasm_pool_export_begin', 'number', ['string']);
const _ds_begin   = mod.cwrap('zfswasm_ds_create_begin', 'number', ['string']);
const _snap_begin = mod.cwrap('zfswasm_snap_begin', 'number', ['string','string']);
const _clone_begin= mod.cwrap('zfswasm_clone_begin', 'number', ['string','string']);
const _fw_begin   = mod.cwrap('zfswasm_file_write_begin', 'number',
    ['string','string','number','number']);
const _fr_begin   = mod.cwrap('zfswasm_file_read_begin', 'number',
    ['string','string','number','number','number']);

async function fileWrite(ds, p, str) {
    const data = Buffer.from(str, 'utf8');
    const bufPtr = mod._malloc(data.length || 1);
    try {
        if (data.length) mod.HEAPU8.set(data, bufPtr);
        return await runOp(_fw_begin, ds, p, bufPtr, data.length);
    } finally { mod._free(bufPtr); }
}

async function fileRead(ds, p, cap = 4096) {
    const bufPtr = mod._malloc(cap);
    const outLenPtr = mod._malloc(4);
    try {
        const rc = await runOp(_fr_begin, ds, p, bufPtr, cap, outLenPtr);
        if (rc !== 0) return { rc, data: null };
        const len = mod.HEAPU32[outLenPtr >> 2];
        const bytes = Buffer.from(mod.HEAPU8.subarray(bufPtr, bufPtr + len));
        return { rc: 0, data: bytes.toString('utf8') };
    } finally { mod._free(bufPtr); mod._free(outLenPtr); }
}

async function must(label, p, code) {
    const rc = await p;
    if (rc !== 0) { log(`${label} failed errno=${rc}`); process.exit(code); }
}

log('[smoke] init…');
await must('init', runOp(_init_begin), 1);
log('[smoke] pool_create tankw on ' + BACKING);
await must('pool_create', runOp(_pc_begin, 'tankw', BACKING), 2);
log('[smoke] ds_create tankw/site');
await must('ds_create', runOp(_ds_begin, 'tankw/site'), 3);
log('[smoke] write tankw/site:/file.txt');
await must('write site', fileWrite('tankw/site', '/file.txt', 'hello world on main'), 4);
log('[smoke] snap tankw/site@v1');
await must('snap', runOp(_snap_begin, 'tankw/site', 'v1'), 5);
log('[smoke] clone tankw/site@v1 -> tankw/branch');
await must('clone', runOp(_clone_begin, 'tankw/site@v1', 'tankw/branch'), 6);
log('[smoke] write tankw/branch:/file.txt');
await must('write branch', fileWrite('tankw/branch', '/file.txt', 'on branch'), 7);

log('[smoke] read tankw/site:/file.txt');
const a = await fileRead('tankw/site', '/file.txt');
log(`  rc=${a.rc} data=${JSON.stringify(a.data)}`);
if (a.rc !== 0 || a.data !== 'hello world on main') { log('site mismatch'); process.exit(8); }

log('[smoke] read tankw/branch:/file.txt');
const b = await fileRead('tankw/branch', '/file.txt');
log(`  rc=${b.rc} data=${JSON.stringify(b.data)}`);
if (b.rc !== 0 || b.data !== 'on branch') { log('branch mismatch'); process.exit(9); }

log('[smoke] read tankw/site@v1:/file.txt');
const c = await fileRead('tankw/site@v1', '/file.txt');
log(`  rc=${c.rc} data=${JSON.stringify(c.data)}`);
if (c.rc !== 0 || c.data !== 'hello world on main') { log('snap mismatch'); process.exit(10); }

log('[smoke] pool_export tankw');
await must('export', runOp(_pe_begin, 'tankw'), 11);
log('[smoke] OK');
process.exit(0);
