// Temporary in-memory implementation of the WasmSnapshotFs surface the
// Rust demo used to provide. The full OpenZFS module in
// ../zfs-wasm-real/build does the real work in Node via the smoke
// test (see zfs-wasm-real/scripts/smoke-node.mjs); swapping the
// browser demo to drive the UI from ZFS datasets needs an OPFS bridge
// that is a separate work item.
//
// This keeps the demo page functional (tree view, branching, snapshots)
// while the browser side of the OPFS+pthread integration catches up.

const textEncoder = new TextEncoder();
const textDecoder = new TextDecoder();

function splitPath(p) {
  return p.split("/").filter((s) => s.length > 0);
}

function cloneTree(node) {
  if (node.kind === "file") return { kind: "file", bytes: new Uint8Array(node.bytes) };
  const out = { kind: "dir", children: {} };
  for (const [k, v] of Object.entries(node.children)) out.children[k] = cloneTree(v);
  return out;
}

export class WasmSnapshotFs {
  constructor() {
    this.root = { kind: "dir", children: {} };
    this.branches = { main: this.root };
    this.currentBranch = "main";
    this.snapshots = {};
  }

  free() {}
  [Symbol.dispose]() {}

  _resolve(path, { create = false, asDir = false } = {}) {
    const parts = splitPath(path);
    let node = this.root;
    for (let i = 0; i < parts.length; i++) {
      const name = parts[i];
      if (node.kind !== "dir") throw new Error("not a directory: " + path);
      if (!(name in node.children)) {
        if (!create) throw new Error("no such path: " + path);
        const isLast = i === parts.length - 1;
        node.children[name] = isLast && !asDir
          ? { kind: "file", bytes: new Uint8Array() }
          : { kind: "dir", children: {} };
      }
      node = node.children[name];
    }
    return node;
  }

  create_dir(path) {
    const parts = splitPath(path);
    let node = this.root;
    for (const name of parts) {
      if (!(name in node.children)) node.children[name] = { kind: "dir", children: {} };
      node = node.children[name];
    }
  }

  write_file(path, data) {
    const parts = splitPath(path);
    const name = parts.pop();
    let node = this.root;
    for (const p of parts) {
      if (!(p in node.children)) node.children[p] = { kind: "dir", children: {} };
      node = node.children[p];
    }
    node.children[name] = { kind: "file", bytes: new Uint8Array(data) };
  }

  read_file(path) {
    const n = this._resolve(path);
    if (n.kind !== "file") throw new Error("not a file: " + path);
    return new Uint8Array(n.bytes);
  }

  read_file_in_snapshot(snap, path) {
    const root = this.snapshots[snap];
    if (!root) throw new Error("no snapshot: " + snap);
    return readFrom(root, path);
  }

  exists(path) {
    try { this._resolve(path); return true; } catch { return false; }
  }

  exists_in_snapshot(snap, path) {
    const root = this.snapshots[snap];
    if (!root) return false;
    try { walk(root, path); return true; } catch { return false; }
  }

  delete(path) {
    const parts = splitPath(path);
    const name = parts.pop();
    let node = this.root;
    for (const p of parts) node = node.children[p];
    delete node.children[name];
  }

  list_dir_json(path) {
    const n = path === "/" ? this.root : this._resolve(path);
    return JSON.stringify(listDir(n));
  }

  list_dir_in_snapshot_json(snap, path) {
    const root = this.snapshots[snap];
    if (!root) throw new Error("no snapshot: " + snap);
    const n = path === "/" ? root : walk(root, path);
    return JSON.stringify(listDir(n));
  }

  snapshot(name) { this.snapshots[name] = cloneTree(this.root); }
  rollback(name) {
    if (!this.snapshots[name]) throw new Error("no snapshot: " + name);
    this.root = cloneTree(this.snapshots[name]);
    this.branches[this.currentBranch] = this.root;
  }

  clone_snapshot(snapName, branchName) {
    if (!this.snapshots[snapName]) throw new Error("no snapshot: " + snapName);
    this.branches[branchName] = cloneTree(this.snapshots[snapName]);
  }

  checkout_branch(name) {
    if (!this.branches[name]) throw new Error("no branch: " + name);
    this.currentBranch = name;
    this.root = this.branches[name];
  }

  current_branch() { return this.currentBranch; }
  branch_names_json() { return JSON.stringify(Object.keys(this.branches)); }
  snapshot_names_json() { return JSON.stringify(Object.keys(this.snapshots)); }

  branch_info_json() {
    return JSON.stringify(Object.keys(this.branches).map((n) => ({ name: n })));
  }
  snapshot_info_json() {
    return JSON.stringify(Object.keys(this.snapshots).map((n) => ({ name: n })));
  }
  stats_json() {
    return JSON.stringify({ branches: Object.keys(this.branches).length,
      snapshots: Object.keys(this.snapshots).length,
      backend: "in-memory fallback (zfs-wasm-real browser bridge pending)" });
  }
}

function listDir(node) {
  return Object.entries(node.children).map(([name, child]) => ({
    name, kind: child.kind,
    size: child.kind === "file" ? child.bytes.length : undefined,
  }));
}

function walk(root, path) {
  const parts = splitPath(path);
  let n = root;
  for (const p of parts) { if (n.kind !== "dir") throw new Error("not a dir"); n = n.children[p]; if (!n) throw new Error("not found"); }
  return n;
}

function readFrom(root, path) {
  const n = walk(root, path);
  if (n.kind !== "file") throw new Error("not a file");
  return new Uint8Array(n.bytes);
}
