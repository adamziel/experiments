// Browser demo for the OpenZFS-in-wasm bundle.
//
// Wires every operation the wasm exposes (pool create/export, dataset
// create/destroy, snapshot, snapshot destroy, clone, file read/write)
// into a tree-view UI: dataset/snapshot tree on the left, file tree
// in the middle, content editor on the right.
//
// The wasm runs on a dedicated pthread (-sPROXY_TO_PTHREAD), so each
// op is a non-blocking enqueue followed by polling. We track the
// dataset / snapshot / file tree on the JS side because the C bundle
// does not yet expose `list` APIs — every state change here either
// originated in this UI or in the demo seed scenario, so the mirror
// stays accurate.

import ZfsWasmFactory from "./build/zfswasm.js";

const POOL = "tankw";
const BACKING = "/tmp/zfswasm-demo.img";
const BACKING_SIZE = 128 * 1024 * 1024;

// ---------- DOM helpers ----------

const $ = (id) => document.getElementById(id);
const els = {
  boot: $("boot"), reset: $("reset"),
  mPool: $("m-pool"), mDs: $("m-datasets"),
  mSnaps: $("m-snapshots"), mActive: $("m-active"),
  dsTree: $("dataset-tree"), fileTree: $("file-tree"),
  filesTitle: $("files-title"), filesSubtitle: $("files-subtitle"),
  editor: $("editor"), editorTitle: $("editor-title"),
  editorSubtitle: $("editor-subtitle"), editorStatus: $("editor-status"),
  opSnap: $("op-snap"), opClone: $("op-clone"), opNewds: $("op-newds"),
  opDestroy: $("op-destroy"), opNewfile: $("op-newfile"),
  opSave: $("op-save"),
  log: $("log"), clearLog: $("clear-log"),
  promptDialog: $("prompt-dialog"), promptTitle: $("prompt-title"),
  promptHelp: $("prompt-help"), promptInput: $("prompt-input"),
  promptTextarea: $("prompt-textarea"),
};

const log = (line) => {
  const ts = new Date().toLocaleTimeString();
  els.log.textContent += `[${ts}] ${line}\n`;
  els.log.scrollTop = els.log.scrollHeight;
};
const setStatus = (msg, kind = "") => {
  els.editorStatus.textContent = msg;
  els.editorStatus.className = "status " + kind;
};

// ---------- State ----------

const state = {
  zfs: null,
  pool: null,
  // Map<datasetFullName, { kind: 'ds' | 'clone',
  //                       parentSnap?: 'pool/ds@snap',
  //                       files: Map<path, { size }>,
  //                       snaps: string[] }>
  datasets: new Map(),
  active: null,            // selected node: { kind, ds, snap?, file? }
  busy: false,
};

const isSnapshot = (id) => id.includes("@");
const dsOfSnap   = (snap) => snap.split("@")[0];

// ---------- WASM bindings ----------

async function bootZfs() {
  els.boot.disabled = true;
  log("loading wasm…");
  const mod = await ZfsWasmFactory({
    print: (s) => log("[mod] " + s),
    printErr: (s) => log("[mod-err] " + s),
    onAbort: (r) => log("[abort] " + r),
  });

  mod.FS.mkdirTree("/tmp");
  mod.FS.writeFile(BACKING, new Uint8Array(BACKING_SIZE));

  const _poll   = mod.cwrap("zfswasm_poll", "number", []);
  const _result = mod.cwrap("zfswasm_result", "number", []);
  const init    = mod.cwrap("zfswasm_init_begin", "number", []);
  const pcreate = mod.cwrap("zfswasm_pool_create_begin", "number", ["string","string"]);
  const dscreate= mod.cwrap("zfswasm_ds_create_begin", "number", ["string"]);
  const dsdestr = mod.cwrap("zfswasm_ds_destroy_begin", "number", ["string"]);
  const snap    = mod.cwrap("zfswasm_snap_begin", "number", ["string","string"]);
  const sndest  = mod.cwrap("zfswasm_snap_destroy_begin", "number", ["string"]);
  const clone   = mod.cwrap("zfswasm_clone_begin", "number", ["string","string"]);
  const fw      = mod.cwrap("zfswasm_file_write_begin", "number",
                            ["string","string","number","number"]);
  const fr      = mod.cwrap("zfswasm_file_read_begin", "number",
                            ["string","string","number","number","number"]);

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  async function call(begin, ...args) {
    const rc = begin(...args);
    if (rc !== 0) throw new Error(`enqueue rc=${rc}`);
    let delay = 1;
    while (_poll() === 0) {
      await sleep(delay);
      if (delay < 50) delay++;
    }
    return _result();
  }

  return {
    mod,
    init:      ()         => call(init),
    poolCreate:(name, bk) => call(pcreate, name, bk),
    dsCreate:  (full)     => call(dscreate, full),
    dsDestroy: (full)     => call(dsdestr,  full),
    snap:      (ds, name) => call(snap, ds, name),
    snapDestroy:(full)    => call(sndest, full),
    clone:     (snap, dst)=> call(clone, snap, dst),
    async fileWrite(ds, path, text) {
      const bytes = new TextEncoder().encode(text);
      const buf = mod._malloc(bytes.length || 1);
      try {
        if (bytes.length) mod.HEAPU8.set(bytes, buf);
        return await call(fw, ds, path, buf, bytes.length);
      } finally { mod._free(buf); }
    },
    async fileRead(ds, path, cap = 16384) {
      const buf = mod._malloc(cap);
      const out = mod._malloc(4);
      try {
        const rc = await call(fr, ds, path, buf, cap, out);
        if (rc !== 0) return { rc, data: null };
        const len = mod.HEAPU32[out >> 2];
        // HEAPU8 is backed by a SharedArrayBuffer when pthreads are
        // on; TextDecoder rejects shared views. Copy into a non-shared
        // Uint8Array first.
        const copy = new Uint8Array(len);
        copy.set(mod.HEAPU8.subarray(buf, buf + len));
        return {
          rc: 0,
          data: new TextDecoder().decode(copy),
          len,
        };
      } finally { mod._free(buf); mod._free(out); }
    },
  };
}

// ---------- Boot + demo seed ----------

async function boot() {
  try {
    state.zfs = await bootZfs();
    log("init…");
    if ((await state.zfs.init()) !== 0) throw new Error("init failed");
    log(`pool_create ${POOL} on ${BACKING}`);
    if ((await state.zfs.poolCreate(POOL, BACKING)) !== 0)
      throw new Error("pool_create failed");
    state.pool = POOL;

    // Seed: site dataset with two files, snapshot, clone, branch edits.
    await mkDataset(`${POOL}/site`);
    await writeFile(`${POOL}/site`, "/index.html",
      "<h1>Hello from OpenZFS</h1>\n<p>Edit me, snapshot, branch.</p>\n");
    await writeFile(`${POOL}/site`, "/notes.txt",
      "Welcome — try the Snapshot button on tankw/site, then Clone.\n");
    await mkSnap(`${POOL}/site`, "v1");
    await mkClone(`${POOL}/site@v1`, `${POOL}/branch`);
    await writeFile(`${POOL}/branch`, "/index.html",
      "<h1>Hello from a branch</h1>\n<p>This is the cloned dataset.</p>\n");

    setActive({ kind: "ds", ds: `${POOL}/site` });
    refresh();
    [els.opSnap, els.opClone, els.opNewds, els.opDestroy,
     els.opNewfile, els.opSave, els.reset].forEach((b) => (b.disabled = false));
    setStatus("ready", "ok");
  } catch (e) {
    log("[err] " + e.message);
    setStatus(e.message, "err");
    els.boot.disabled = false;
  }
}

// ---------- State mutators ----------

async function mkDataset(full) {
  log(`ds_create ${full}`);
  const rc = await state.zfs.dsCreate(full);
  if (rc !== 0) throw new Error(`ds_create rc=${rc}`);
  state.datasets.set(full, { kind: "ds", files: new Map(), snaps: [] });
}

async function mkSnap(ds, name) {
  log(`snap ${ds}@${name}`);
  const rc = await state.zfs.snap(ds, name);
  if (rc !== 0) throw new Error(`snap rc=${rc}`);
  state.datasets.get(ds).snaps.push(name);
}

async function mkClone(snap, dst) {
  log(`clone ${snap} -> ${dst}`);
  const rc = await state.zfs.clone(snap, dst);
  if (rc !== 0) throw new Error(`clone rc=${rc}`);
  // Inherit files from the parent at snap time (best-effort mirror).
  const parentFiles = state.datasets.get(dsOfSnap(snap))?.files
                       ?? new Map();
  state.datasets.set(dst, {
    kind: "clone",
    parentSnap: snap,
    files: new Map([...parentFiles]),
    snaps: [],
  });
}

async function writeFile(ds, path, text) {
  log(`file_write ${ds}:${path} (${text.length}B)`);
  const rc = await state.zfs.fileWrite(ds, path, text);
  if (rc !== 0) throw new Error(`file_write rc=${rc}`);
  const meta = state.datasets.get(ds);
  if (meta) meta.files.set(path, { size: text.length });
}

async function destroyDataset(full) {
  log(`destroy ${full}`);
  const rc = isSnapshot(full)
    ? await state.zfs.snapDestroy(full)
    : await state.zfs.dsDestroy(full);
  if (rc !== 0) throw new Error(`destroy rc=${rc}`);
  if (isSnapshot(full)) {
    const ds = state.datasets.get(dsOfSnap(full));
    if (ds) ds.snaps = ds.snaps.filter((n) => `${dsOfSnap(full)}@${n}` !== full);
  } else {
    state.datasets.delete(full);
  }
}

// ---------- UI: tree rendering ----------

function refresh() {
  // Active label
  els.mPool.textContent = state.pool ?? "—";
  els.mDs.textContent = [...state.datasets.values()].length;
  els.mSnaps.textContent = [...state.datasets.values()]
    .reduce((n, d) => n + d.snaps.length, 0);
  els.mActive.textContent = activeLabel();

  renderDatasetTree();
  renderFileTree();
  renderEditor();
}

function activeLabel() {
  if (!state.active) return "—";
  const a = state.active;
  if (a.kind === "snap") return `${a.ds}@${a.snap}`;
  return a.ds;
}

function renderDatasetTree() {
  els.dsTree.innerHTML = "";
  if (!state.pool) return;
  // Pool root.
  const root = node({
    label: state.pool, tag: "POOL", className: "tree-node muted",
  });
  els.dsTree.appendChild(root);

  // Build parent-snap -> [clone names] map.
  const childrenOfSnap = new Map();
  for (const [name, meta] of state.datasets) {
    if (meta.kind === "clone")
      childrenOfSnap.set(meta.parentSnap,
        [...(childrenOfSnap.get(meta.parentSnap) ?? []), name]);
  }

  // Top-level datasets: directly under pool, not clones of any snap.
  const topLevel = [...state.datasets.entries()]
    .filter(([, m]) => m.kind === "ds")
    .map(([n]) => n)
    .sort();

  const wrap = document.createElement("div");
  wrap.className = "tree-children";
  root.after(wrap);

  for (const ds of topLevel) renderDatasetSubtree(wrap, ds, childrenOfSnap);
}

function renderDatasetSubtree(parentEl, ds, childrenOfSnap) {
  const meta = state.datasets.get(ds);
  const tagText = meta.kind === "clone" ? "clone" : "ds";
  const dsEl = node({
    label: ds, tag: tagText, className: "tree-node",
    selected: state.active?.kind !== "snap" && state.active?.ds === ds,
    onClick: () => { setActive({ kind: "ds", ds }); refresh(); },
  });
  parentEl.appendChild(dsEl);

  const children = document.createElement("div");
  children.className = "tree-children";
  parentEl.appendChild(children);

  for (const snap of meta.snaps) {
    const full = `${ds}@${snap}`;
    const snapEl = node({
      label: `@${snap}`, tag: "snap ro", className: "tree-node snap",
      selected: state.active?.kind === "snap" && state.active.ds === ds
        && state.active.snap === snap,
      onClick: () => { setActive({ kind: "snap", ds, snap }); refresh(); },
    });
    children.appendChild(snapEl);

    const cloneNames = childrenOfSnap.get(full) ?? [];
    for (const c of cloneNames) {
      const sub = document.createElement("div");
      sub.className = "tree-children";
      children.appendChild(sub);
      renderDatasetSubtree(sub, c, childrenOfSnap);
    }
  }
}

function renderFileTree() {
  els.fileTree.innerHTML = "";
  if (!state.active) return;
  const ds = state.active.ds;
  const meta = state.datasets.get(ds);
  if (!meta) return;

  const isSnap = state.active.kind === "snap";
  els.filesTitle.textContent = isSnap
    ? `Files in ${ds}@${state.active.snap}` : `Files in ${ds}`;
  els.filesSubtitle.textContent = isSnap
    ? "Read-only view of the snapshot."
    : "Click a file to open.";

  const sorted = [...meta.files.keys()].sort();
  if (!sorted.length) {
    const empty = document.createElement("div");
    empty.className = "tree-node muted";
    empty.textContent = "(empty)";
    els.fileTree.appendChild(empty);
    return;
  }

  for (const path of sorted) {
    const file = meta.files.get(path);
    const el = node({
      label: path, tag: `${file.size}B`, className: "tree-node",
      selected: state.active?.file === path,
      onClick: () => {
        state.active = { ...state.active, file: path };
        refresh();
        loadFileIntoEditor(path);
      },
    });
    els.fileTree.appendChild(el);
  }
}

async function loadFileIntoEditor(path) {
  const target = state.active.kind === "snap"
    ? `${state.active.ds}@${state.active.snap}`
    : state.active.ds;
  log(`file_read ${target}:${path}`);
  const r = await state.zfs.fileRead(target, path);
  if (r.rc !== 0) {
    setStatus(`file_read rc=${r.rc}`, "err");
    return;
  }
  els.editor.value = r.data ?? "";
  els.editor.disabled = state.active.kind === "snap";
  els.editorTitle.textContent = `${target}:${path}`;
  els.editorSubtitle.textContent = state.active.kind === "snap"
    ? "Snapshot — read only" : "Edit and Save to write back to ZFS.";
  els.opSave.disabled = state.active.kind === "snap";
  setStatus(`${r.len} bytes`, "ok");
}

function renderEditor() {
  if (!state.active?.file) {
    els.editor.value = "";
    els.editor.disabled = true;
    els.editorTitle.textContent = "No file selected";
    els.editorSubtitle.textContent = "Pick a file from the file tree.";
    els.opSave.disabled = true;
    setStatus("");
  }
}

function setActive(active) {
  state.active = active;
}

function node({ label, tag, className, selected, onClick }) {
  const el = document.createElement("div");
  el.className = className + (selected ? " selected" : "");
  el.role = "treeitem";

  const lbl = document.createElement("span");
  lbl.textContent = label;
  el.appendChild(lbl);

  if (tag) {
    for (const t of String(tag).split(" ")) {
      const badge = document.createElement("span");
      badge.className = `tag ${t}`;
      badge.textContent = t.toUpperCase();
      el.appendChild(badge);
    }
  }
  if (onClick) el.addEventListener("click", onClick);
  return el;
}

// ---------- Prompt dialog ----------

function promptText({ title, help, value = "", multiline = false }) {
  return new Promise((resolve) => {
    els.promptTitle.textContent = title;
    els.promptHelp.textContent = help ?? "";
    els.promptInput.value = value;
    els.promptInput.hidden = !!multiline;
    els.promptTextarea.hidden = !multiline;
    if (multiline) els.promptTextarea.value = value;
    const onClose = () => {
      els.promptDialog.removeEventListener("close", onClose);
      const v = els.promptDialog.returnValue === "ok"
        ? (multiline ? els.promptTextarea.value : els.promptInput.value)
        : null;
      resolve(v);
    };
    els.promptDialog.addEventListener("close", onClose);
    els.promptDialog.showModal();
    setTimeout(() => {
      (multiline ? els.promptTextarea : els.promptInput).focus();
    }, 0);
  });
}

// ---------- Toolbar wiring ----------

function withBusy(fn) {
  return async (...args) => {
    if (state.busy) return;
    state.busy = true;
    try { await fn(...args); }
    catch (e) { log("[err] " + e.message); setStatus(e.message, "err"); }
    finally { state.busy = false; refresh(); }
  };
}

els.boot.addEventListener("click", boot);

els.opSnap.addEventListener("click", withBusy(async () => {
  if (!state.active || state.active.kind !== "ds") {
    setStatus("select a dataset first", "err"); return;
  }
  const name = await promptText({
    title: `Snapshot ${state.active.ds}`,
    help: "Snap name. Will be saved as " + state.active.ds + "@<name>.",
    value: `v${(state.datasets.get(state.active.ds).snaps.length || 0) + 1}`,
  });
  if (!name) return;
  await mkSnap(state.active.ds, name);
  setStatus(`snapshot ${state.active.ds}@${name}`, "ok");
}));

els.opClone.addEventListener("click", withBusy(async () => {
  if (!state.active || state.active.kind !== "snap") {
    setStatus("select a snapshot first", "err"); return;
  }
  const dst = await promptText({
    title: `Clone ${state.active.ds}@${state.active.snap}`,
    help: "New dataset name. Must be unique under the pool.",
    value: `${POOL}/clone${state.datasets.size}`,
  });
  if (!dst) return;
  await mkClone(`${state.active.ds}@${state.active.snap}`, dst);
  setActive({ kind: "ds", ds: dst });
  setStatus(`cloned to ${dst}`, "ok");
}));

els.opNewds.addEventListener("click", withBusy(async () => {
  const name = await promptText({
    title: "New dataset",
    help: `Will be created as ${POOL}/<name>.`,
    value: "data",
  });
  if (!name) return;
  const full = `${POOL}/${name}`;
  await mkDataset(full);
  setActive({ kind: "ds", ds: full });
  setStatus(`created ${full}`, "ok");
}));

els.opDestroy.addEventListener("click", withBusy(async () => {
  if (!state.active) return;
  const target = state.active.kind === "snap"
    ? `${state.active.ds}@${state.active.snap}`
    : state.active.ds;
  const ok = confirm(`Destroy ${target}?`);
  if (!ok) return;
  await destroyDataset(target);
  setActive(null);
  setStatus(`destroyed ${target}`, "ok");
}));

els.opNewfile.addEventListener("click", withBusy(async () => {
  if (!state.active || state.active.kind !== "ds") {
    setStatus("select a dataset first", "err"); return;
  }
  const path = await promptText({
    title: `New file in ${state.active.ds}`,
    help: "File path inside the dataset (e.g. /readme.md).",
    value: "/readme.md",
  });
  if (!path) return;
  const text = await promptText({
    title: `Initial content for ${path}`,
    help: "UTF-8. Stored as a DMU plain-file object.",
    value: "Hello from the wasm ZFS demo.\n",
    multiline: true,
  });
  if (text === null) return;
  await writeFile(state.active.ds, path, text);
  state.active.file = path;
  setStatus(`wrote ${path}`, "ok");
  refresh();
  loadFileIntoEditor(path);
}));

els.opSave.addEventListener("click", withBusy(async () => {
  if (!state.active?.file || state.active.kind === "snap") return;
  const text = els.editor.value;
  await writeFile(state.active.ds, state.active.file, text);
  setStatus(`saved ${text.length} bytes`, "ok");
}));

els.reset.addEventListener("click", () => window.location.reload());
els.clearLog.addEventListener("click", () => { els.log.textContent = ""; });
