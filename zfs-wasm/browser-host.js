// Moved from the old Rust bindgen pkg to the real OpenZFS WebAssembly
// build in ../zfs-wasm-real/build. That bundle is an Emscripten
// MODULARIZE UMD (factory name ZfsWasm) plus a worker.js sidecar.
//
// For the browser demo we only need to (a) load the module and (b)
// call zfswasm_init so it's live; the rest of the BrowserSnapshotFs
// methods keep an in-memory tree for the existing UI. A full swap to
// driving the UI from ZFS datasets needs OPFS plumbing that doesn't
// fit in this change.
import ZfsWasmFactory from "../zfs-wasm-real/build/zfswasm.js";
import { WasmSnapshotFs } from "./js/browser/legacy-fallback.js";

const decoder = new TextDecoder();
const encoder = new TextEncoder();

let initPromise;

function normalizeBytes(data) {
  if (data instanceof Uint8Array) {
    return data;
  }

  if (typeof data === "string") {
    return encoder.encode(data);
  }

  if (Array.isArray(data)) {
    return Uint8Array.from(data);
  }

  throw new TypeError("expected Uint8Array, string, or array of bytes");
}

export class BrowserSnapshotFsHost {
  constructor(inner = new WasmSnapshotFs()) {
    this.inner = inner;
  }

  createDir(path) {
    this.inner.create_dir(path);
    return this;
  }

  writeFile(path, data) {
    this.inner.write_file(path, normalizeBytes(data));
    return this;
  }

  writeText(path, text) {
    return this.writeFile(path, encoder.encode(text));
  }

  readFile(path) {
    return this.inner.read_file(path);
  }

  readText(path) {
    return decoder.decode(this.readFile(path));
  }

  readFileInSnapshot(snapshotName, path) {
    return this.inner.read_file_in_snapshot(snapshotName, path);
  }

  readTextInSnapshot(snapshotName, path) {
    return decoder.decode(this.readFileInSnapshot(snapshotName, path));
  }

  delete(path) {
    this.inner.delete(path);
    return this;
  }

  exists(path) {
    return this.inner.exists(path);
  }

  existsInSnapshot(snapshotName, path) {
    return this.inner.exists_in_snapshot(snapshotName, path);
  }

  listDir(path) {
    return JSON.parse(this.inner.list_dir_json(path));
  }

  listDirInSnapshot(snapshotName, path) {
    return JSON.parse(this.inner.list_dir_in_snapshot_json(snapshotName, path));
  }

  snapshot(name) {
    this.inner.snapshot(name);
    return this;
  }

  rollback(name) {
    this.inner.rollback(name);
    return this;
  }

  cloneSnapshot(snapshotName, branchName) {
    this.inner.clone_snapshot(snapshotName, branchName);
    return this;
  }

  checkoutBranch(branchName) {
    this.inner.checkout_branch(branchName);
    return this;
  }

  currentBranch() {
    return this.inner.current_branch();
  }

  branchNames() {
    return JSON.parse(this.inner.branch_names_json());
  }

  branchInfo() {
    return JSON.parse(this.inner.branch_info_json());
  }

  snapshotNames() {
    return JSON.parse(this.inner.snapshot_names_json());
  }

  snapshotInfo() {
    return JSON.parse(this.inner.snapshot_info_json());
  }

  stats() {
    return JSON.parse(this.inner.stats_json());
  }
}

export async function initSnapshotFs(input) {
  if (!initPromise) {
    initPromise = (async () => {
      // Boot the real OpenZFS WebAssembly module. The smoke test in
      // zfs-wasm-real/scripts/smoke-node.mjs exercises the full
      // pool/dataset/snapshot/clone/file round-trip; the browser demo
      // only proves the module loads here and surfaces its status on
      // `window.zfswasmStatus` for the page to read.
      const mod = await ZfsWasmFactory({
        print: (s) => console.log("[zfswasm]", s),
        printErr: (s) => console.warn("[zfswasm]", s),
      });
      const init = mod.cwrap("zfswasm_init", "number", []);
      const rc = init();
      if (typeof window !== "undefined") {
        window.zfswasmStatus = { loaded: true, initRc: rc };
      }
      console.log("[zfswasm] loaded, init rc =", rc);
    })();
  }

  await initPromise;
}

export async function createSnapshotFs(input) {
  await initSnapshotFs(input);
  return new BrowserSnapshotFsHost();
}
