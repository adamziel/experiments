// Browser adapter for the OpenZFS WebAssembly module in
// ../zfs-wasm-real/build. The wasm module exposes an async
// begin/poll API; we wrap each call as a Promise.
//
// At init we boot the module, create a 128 MiB backing file inside the
// module's virtual filesystem, then zpool-create on it, create a
// working dataset, and run a write -> snapshot -> clone -> read
// round-trip so the demo can show the same pool/dataset/snapshot/clone
// contract that the Node smoke test exercises (see
// zfs-wasm-real/scripts/smoke-node.mjs).
//
// Browser requirements: SharedArrayBuffer is needed for pthread
// support, which needs COOP/COEP response headers on the page:
//     Cross-Origin-Opener-Policy:   same-origin
//     Cross-Origin-Embedder-Policy: require-corp
// Serve the site behind a server that sets those, or host on GitHub
// Pages via the usual meta tags injected by a CDN worker.
import ZfsWasmFactory from "../zfs-wasm-real/build/zfswasm.js";
import { WasmSnapshotFs } from "./js/browser/legacy-fallback.js";

const POOL = "tankw";
const DS = "tankw/site";
const BACKING = "/tmp/zfswasm-browser.img";
const BACKING_SIZE = 128 * 1024 * 1024;

let zfsPromise;
let zfsState;

const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder();

async function bootZfs() {
  const mod = await ZfsWasmFactory({
    print: (s) => console.log("[zfswasm]", s),
    printErr: (s) => console.warn("[zfswasm]", s),
  });

  // Set up a backing file in the wasm virtual FS for the pool.
  mod.FS.mkdirTree("/tmp");
  mod.FS.writeFile(BACKING, new Uint8Array(BACKING_SIZE));

  const _poll = mod.cwrap("zfswasm_poll", "number", []);
  const _result = mod.cwrap("zfswasm_result", "number", []);
  const _init = mod.cwrap("zfswasm_init_begin", "number", []);
  const _pool_create = mod.cwrap("zfswasm_pool_create_begin",
      "number", ["string", "string"]);
  const _ds_create = mod.cwrap("zfswasm_ds_create_begin",
      "number", ["string"]);
  const _snap = mod.cwrap("zfswasm_snap_begin",
      "number", ["string", "string"]);
  const _clone = mod.cwrap("zfswasm_clone_begin",
      "number", ["string", "string"]);
  const _fw = mod.cwrap("zfswasm_file_write_begin",
      "number", ["string", "string", "number", "number"]);
  const _fr = mod.cwrap("zfswasm_file_read_begin",
      "number", ["string", "string", "number", "number", "number"]);

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
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

  async function writeFile(ds, p, str) {
    const data = textEncoder.encode(str);
    const bufPtr = mod._malloc(data.length || 1);
    try {
      if (data.length) mod.HEAPU8.set(data, bufPtr);
      const rc = await runOp(_fw, ds, p, bufPtr, data.length);
      if (rc !== 0) throw new Error(`write failed rc=${rc}`);
    } finally {
      mod._free(bufPtr);
    }
  }

  async function readFile(ds, p, cap = 65536) {
    const bufPtr = mod._malloc(cap);
    const outLenPtr = mod._malloc(4);
    try {
      const rc = await runOp(_fr, ds, p, bufPtr, cap, outLenPtr);
      if (rc !== 0) return { rc, text: null };
      const len = mod.HEAPU32[outLenPtr >> 2];
      const bytes = mod.HEAPU8.subarray(bufPtr, bufPtr + len);
      return { rc: 0, text: textDecoder.decode(bytes) };
    } finally {
      mod._free(bufPtr);
      mod._free(outLenPtr);
    }
  }

  const must = async (label, p) => {
    const rc = await p;
    if (rc !== 0) throw new Error(`${label} failed errno=${rc}`);
  };

  await must("init", runOp(_init));
  await must("pool_create", runOp(_pool_create, POOL, BACKING));
  await must("ds_create", runOp(_ds_create, DS));

  return {
    mod,
    writeFile,
    readFile,
    snap: (snap) => runOp(_snap, DS, snap),
    cloneFrom: (snap, newds) => runOp(_clone, `${DS}@${snap}`, newds),
    readFrom: (ds, p) => readFile(ds, p),
  };
}

export async function initZfs() {
  if (!zfsPromise) {
    zfsPromise = (async () => {
      zfsState = await bootZfs();
      if (typeof window !== "undefined") {
        window.zfswasmStatus = { loaded: true, pool: POOL, ds: DS };
      }
      return zfsState;
    })();
  }
  return zfsPromise;
}

export async function runSelfTest() {
  const z = await initZfs();
  const out = [];
  const push = (line) => {
    out.push(line);
    console.log("[zfs-demo]", line);
  };

  await z.writeFile(DS, "/file.txt", "hello world on main");
  push(`write ${DS}:/file.txt (main)`);

  await must("snap", z.snap("v1"));
  push(`snap ${DS}@v1`);

  await must("clone", z.cloneFrom("v1", "tankw/branch"));
  push(`clone ${DS}@v1 -> tankw/branch`);

  await z.writeFile("tankw/branch", "/file.txt", "on branch");
  push(`write tankw/branch:/file.txt (branch)`);

  const a = await z.readFrom(DS, "/file.txt");
  push(`read ${DS}:/file.txt -> ${JSON.stringify(a.text)}`);
  const b = await z.readFrom("tankw/branch", "/file.txt");
  push(`read tankw/branch:/file.txt -> ${JSON.stringify(b.text)}`);
  const c = await z.readFrom(`${DS}@v1`, "/file.txt");
  push(`read ${DS}@v1:/file.txt -> ${JSON.stringify(c.text)}`);

  const ok =
    a.text === "hello world on main" &&
    b.text === "on branch" &&
    c.text === "hello world on main";

  if (typeof window !== "undefined") {
    window.zfswasmStatus = {
      ...(window.zfswasmStatus || {}),
      selfTestOk: ok,
      selfTestLines: out,
    };
  }
  return { ok, lines: out };
}

async function must(label, p) {
  const rc = await p;
  if (rc !== 0) throw new Error(`${label} failed errno=${rc}`);
}

// createSnapshotFs is retained so the existing tree-view UI keeps
// rendering while the async wasm round-trip runs in parallel. The
// UI-facing object is a pure-JS in-memory mirror; the real ZFS module
// is exercised by runSelfTest() on page load and its result is
// surfaced by the demo page.
export class BrowserSnapshotFsHost {
  constructor(inner = new WasmSnapshotFs()) {
    this.inner = inner;
  }
  createDir(p) { this.inner.create_dir(p); return this; }
  writeFile(p, d) { this.inner.write_file(p, normalizeBytes(d)); return this; }
  writeText(p, t) { return this.writeFile(p, textEncoder.encode(t)); }
  readFile(p) { return this.inner.read_file(p); }
  readText(p) { return textDecoder.decode(this.readFile(p)); }
  readFileInSnapshot(s, p) { return this.inner.read_file_in_snapshot(s, p); }
  readTextInSnapshot(s, p) { return textDecoder.decode(this.readFileInSnapshot(s, p)); }
  delete(p) { this.inner.delete(p); return this; }
  exists(p) { return this.inner.exists(p); }
  existsInSnapshot(s, p) { return this.inner.exists_in_snapshot(s, p); }
  listDir(p) { return JSON.parse(this.inner.list_dir_json(p)); }
  listDirInSnapshot(s, p) { return JSON.parse(this.inner.list_dir_in_snapshot_json(s, p)); }
  snapshot(n) { this.inner.snapshot(n); return this; }
  rollback(n) { this.inner.rollback(n); return this; }
  cloneSnapshot(s, b) { this.inner.clone_snapshot(s, b); return this; }
  checkoutBranch(b) { this.inner.checkout_branch(b); return this; }
  currentBranch() { return this.inner.current_branch(); }
  branchNames() { return JSON.parse(this.inner.branch_names_json()); }
  branchInfo() { return JSON.parse(this.inner.branch_info_json()); }
  snapshotNames() { return JSON.parse(this.inner.snapshot_names_json()); }
  snapshotInfo() { return JSON.parse(this.inner.snapshot_info_json()); }
  stats() { return JSON.parse(this.inner.stats_json()); }
}

function normalizeBytes(data) {
  if (data instanceof Uint8Array) return data;
  if (typeof data === "string") return textEncoder.encode(data);
  if (Array.isArray(data)) return Uint8Array.from(data);
  throw new TypeError("expected Uint8Array, string, or array of bytes");
}

export async function createSnapshotFs() {
  // Kick the real ZFS wasm self-test in the background; don't block
  // the UI on it (the round-trip takes a few hundred ms).
  runSelfTest().catch((e) => {
    console.error("[zfswasm] self-test failed:", e);
    if (typeof window !== "undefined") {
      window.zfswasmStatus = {
        ...(window.zfswasmStatus || {}),
        selfTestOk: false,
        error: String(e),
      };
    }
  });
  return new BrowserSnapshotFsHost();
}
