import init, { WasmSnapshotFs } from "./pkg/zfs_wasm.js";

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

  delete(path) {
    this.inner.delete(path);
    return this;
  }

  exists(path) {
    return this.inner.exists(path);
  }

  listDir(path) {
    return JSON.parse(this.inner.list_dir_json(path));
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

  snapshotNames() {
    return JSON.parse(this.inner.snapshot_names_json());
  }

  stats() {
    return JSON.parse(this.inner.stats_json());
  }
}

export async function initSnapshotFs(input) {
  if (!initPromise) {
    initPromise = init(input);
  } else if (input !== undefined) {
    await initPromise;
  }

  await initPromise;
}

export async function createSnapshotFs(input) {
  await initSnapshotFs(input);
  return new BrowserSnapshotFsHost();
}
