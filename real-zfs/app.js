// Browser demo for the OpenZFS wasm bundle.
//
// The wasm module is built with -sPROXY_TO_PTHREAD, so all ZFS work
// runs on a dedicated pthread worker. Exported zfswasm_*_begin
// functions are non-blocking enqueues; we poll zfswasm_poll() until
// the worker signals completion, then read the int rc from
// zfswasm_result().

import ZfsWasmFactory from "./build/zfswasm.js";

const POOL = "tankw";
const DS = `${POOL}/site`;
const BRANCH = `${POOL}/branch`;
const SNAP_NAME = "v1";
const SNAP_FULL = `${DS}@${SNAP_NAME}`;
const BACKING = "/tmp/zfswasm-demo.img";
const BACKING_SIZE = 128 * 1024 * 1024;

const $ = (id) => document.getElementById(id);
const logEl = $("log");
const log = (line) => {
  logEl.textContent += line + "\n";
  logEl.scrollTop = logEl.scrollHeight;
};

let zfs;

async function boot() {
  const bootBtn = $("op-boot");
  const status = $("boot-status");
  bootBtn.disabled = true;
  status.textContent = "loading wasm…";
  status.className = "status";

  const mod = await ZfsWasmFactory({
    print: (s) => log("[mod] " + s),
    printErr: (s) => log("[mod-err] " + s),
    onAbort: (r) => log("[abort] " + r),
  });

  mod.FS.mkdirTree("/tmp");
  mod.FS.writeFile(BACKING, new Uint8Array(BACKING_SIZE));

  const bindings = bindApi(mod);

  status.textContent = "creating pool…";
  let rc = await bindings.runOp(bindings.init);
  if (rc !== 0) throw new Error(`init rc=${rc}`);

  rc = await bindings.runOp(bindings.poolCreate, POOL, BACKING);
  if (rc !== 0) throw new Error(`pool_create rc=${rc}`);

  rc = await bindings.runOp(bindings.dsCreate, DS);
  if (rc !== 0) throw new Error(`ds_create rc=${rc}`);

  zfs = bindings;
  status.textContent = `pool ${POOL} ready, dataset ${DS} created.`;
  status.classList.add("ok");
  $("ops-card").hidden = false;
  $("results-card").hidden = false;
  log("[boot] OK");
}

function bindApi(mod) {
  const _poll = mod.cwrap("zfswasm_poll", "number", []);
  const _result = mod.cwrap("zfswasm_result", "number", []);
  const init = mod.cwrap("zfswasm_init_begin", "number", []);
  const poolCreate = mod.cwrap("zfswasm_pool_create_begin", "number",
    ["string", "string"]);
  const dsCreate = mod.cwrap("zfswasm_ds_create_begin", "number",
    ["string"]);
  const snap = mod.cwrap("zfswasm_snap_begin", "number",
    ["string", "string"]);
  const clone = mod.cwrap("zfswasm_clone_begin", "number",
    ["string", "string"]);
  const _fw = mod.cwrap("zfswasm_file_write_begin", "number",
    ["string", "string", "number", "number"]);
  const _fr = mod.cwrap("zfswasm_file_read_begin", "number",
    ["string", "string", "number", "number", "number"]);

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function runOp(beginFn, ...args) {
    const rc = beginFn(...args);
    if (rc !== 0) throw new Error(`enqueue rc=${rc}`);
    let delay = 1;
    while (_poll() === 0) {
      await sleep(delay);
      if (delay < 50) delay++;
    }
    return _result();
  }

  async function fileWrite(ds, path, text) {
    const bytes = new TextEncoder().encode(text);
    const buf = mod._malloc(bytes.length || 1);
    try {
      if (bytes.length) mod.HEAPU8.set(bytes, buf);
      return await runOp(_fw, ds, path, buf, bytes.length);
    } finally {
      mod._free(buf);
    }
  }

  async function fileRead(ds, path, cap = 4096) {
    const buf = mod._malloc(cap);
    const outLen = mod._malloc(4);
    try {
      const rc = await runOp(_fr, ds, path, buf, cap, outLen);
      if (rc !== 0) return { rc, data: null };
      const len = mod.HEAPU32[outLen >> 2];
      const data = new TextDecoder().decode(
        mod.HEAPU8.subarray(buf, buf + len));
      return { rc: 0, data };
    } finally {
      mod._free(buf);
      mod._free(outLen);
    }
  }

  return {
    init, poolCreate, dsCreate, snap, clone,
    runOp, fileWrite, fileRead,
  };
}

async function writeMain() {
  const text = $("main-text").value;
  log(`[write main] ${JSON.stringify(text)}`);
  const rc = await zfs.fileWrite(DS, "/file.txt", text);
  log(`[write main] rc=${rc}`);
}

async function snap() {
  log(`[snap] ${SNAP_FULL}`);
  const rc = await zfs.runOp(zfs.snap, DS, SNAP_NAME);
  log(`[snap] rc=${rc}`);
}

async function clone() {
  log(`[clone] ${SNAP_FULL} -> ${BRANCH}`);
  const rc = await zfs.runOp(zfs.clone, SNAP_FULL, BRANCH);
  log(`[clone] rc=${rc}`);
}

async function writeBranch() {
  const text = $("branch-text").value;
  log(`[write branch] ${JSON.stringify(text)}`);
  const rc = await zfs.fileWrite(BRANCH, "/file.txt", text);
  log(`[write branch] rc=${rc}`);
}

async function readAll() {
  log("[read] site / branch / snapshot");
  const a = await zfs.fileRead(DS, "/file.txt");
  const b = await zfs.fileRead(BRANCH, "/file.txt");
  const c = await zfs.fileRead(SNAP_FULL, "/file.txt");
  $("out-site").textContent = render(a);
  $("out-branch").textContent = render(b);
  $("out-snap").textContent = render(c);
}

function render({ rc, data }) {
  if (rc !== 0) return `rc=${rc}`;
  return data || "(empty)";
}

function wire(id, fn) {
  $(id).addEventListener("click", async () => {
    try {
      await fn();
    } catch (e) {
      log("[err] " + e.message);
    }
  });
}

$("op-boot").addEventListener("click", () => {
  boot().catch((e) => {
    $("boot-status").textContent = "boot failed: " + e.message;
    $("boot-status").className = "status err";
    log("[boot err] " + e.message);
  });
});
wire("op-write-main", writeMain);
wire("op-snap", snap);
wire("op-clone", clone);
wire("op-write-branch", writeBranch);
wire("op-read", readAll);
