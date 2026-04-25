// Browser demo for the OpenZFS-in-wasm bundle.
//
// Hover any row in the dataset tree to act on it: snapshot, branch
// (snap + clone in one step), or destroy. Names are entered inline,
// not in a modal. The file panel and editor mirror the active node.
//
// Wasm runs on a dedicated pthread (-sPROXY_TO_PTHREAD); each op is
// a non-blocking enqueue followed by polling. Dataset / snapshot /
// file state lives JS-side because the C bundle has no list APIs.
// Every transition starts in this UI, so the mirror stays accurate.

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
  opNewfile: $("op-newfile"), opSave: $("op-save"),
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
  // Map<datasetFullName, { kind, parentSnap?, files, snaps[] }>
  datasets: new Map(),
  active: null,            // { kind, ds, snap?, file? }
  busy: false,
  editing: null,           // { row, cancel }
};

const isSnapshot = (id) => id.includes("@");
const dsOfSnap   = (snap) => snap.split("@")[0];
const snapFull   = (ds, snap) => `${ds}@${snap}`;

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
        // on; TextDecoder rejects shared views. Copy first.
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

    await mkDataset(`${POOL}/site`);
    await writeFile(`${POOL}/site`, "/index.html",
      "<h1>Hello from OpenZFS</h1>\n<p>Hover any row → branch in one click.</p>\n");
    await writeFile(`${POOL}/site`, "/notes.txt",
      "Try this:\n  1. Hover tankw/site → 'Branch' → name it.\n" +
      "  2. Edit a file in the branch.\n" +
      "  3. Click the older snapshot to confirm the original is intact.\n");
    await mkSnap(`${POOL}/site`, "v1");
    await mkClone(`${POOL}/site@v1`, `${POOL}/branch`);
    await writeFile(`${POOL}/branch`, "/index.html",
      "<h1>Hello from a branch</h1>\n<p>Try editing me. The snapshot stays.</p>\n");

    setActive({ kind: "ds", ds: `${POOL}/site` });
    refresh();
    [els.opNewfile, els.opSave, els.reset].forEach((b) => (b.disabled = false));
    setStatus("ready", "ok");
  } catch (e) {
    log("[err] " + e.message);
    setStatus(e.message, "err");
    els.boot.disabled = false;
  }
}

// ---------- Mutators ----------

async function mkDataset(full) {
  log(`ds_create ${full}`);
  const rc = await state.zfs.dsCreate(full);
  if (rc !== 0) throw new Error(`ds_create rc=${rc}`);
  state.datasets.set(full, { kind: "ds", files: new Map(), snaps: [] });
}

async function mkSnap(ds, name) {
  log(`snap ${snapFull(ds, name)}`);
  const rc = await state.zfs.snap(ds, name);
  if (rc !== 0) throw new Error(`snap rc=${rc}`);
  state.datasets.get(ds).snaps.push(name);
}

async function mkClone(snap, dst) {
  log(`clone ${snap} -> ${dst}`);
  const rc = await state.zfs.clone(snap, dst);
  if (rc !== 0) throw new Error(`clone rc=${rc}`);
  // Inherit files from the parent at snap time (best-effort mirror).
  const parentFiles = state.datasets.get(dsOfSnap(snap))?.files ?? new Map();
  state.datasets.set(dst, {
    kind: "clone",
    parentSnap: snap,
    files: new Map([...parentFiles].map(([p, f]) => [p, { ...f }])),
    snaps: [],
  });
}

// One-click branch: snap + clone. Snap name auto-derived; the user
// only names the branch.
async function branchFrom(ds, branchName) {
  const meta = state.datasets.get(ds);
  const snapName = `branchpoint-${meta.snaps.length + 1}`;
  await mkSnap(ds, snapName);
  await mkClone(snapFull(ds, snapName), `${POOL}/${branchName}`);
}

async function writeFile(ds, path, text) {
  log(`file_write ${ds}:${path} (${text.length}B)`);
  const rc = await state.zfs.fileWrite(ds, path, text);
  if (rc !== 0) throw new Error(`file_write rc=${rc}`);
  const meta = state.datasets.get(ds);
  if (meta) meta.files.set(path, { size: text.length });
}

async function destroyTarget(target) {
  log(`destroy ${target}`);
  const rc = isSnapshot(target)
    ? await state.zfs.snapDestroy(target)
    : await state.zfs.dsDestroy(target);
  if (rc !== 0) throw new Error(`destroy rc=${rc}`);
  if (isSnapshot(target)) {
    const ds = state.datasets.get(dsOfSnap(target));
    if (ds) ds.snaps = ds.snaps.filter(
      (n) => snapFull(dsOfSnap(target), n) !== target);
  } else {
    state.datasets.delete(target);
  }
}

// ---------- Tree rendering ----------

function refresh() {
  els.mPool.textContent = state.pool ?? "—";
  els.mDs.textContent = state.datasets.size;
  els.mSnaps.textContent = [...state.datasets.values()]
    .reduce((n, d) => n + d.snaps.length, 0);
  els.mActive.textContent = activeLabel();
  renderDatasetTree();
  renderFileTree();
  renderEditor();
}

function activeLabel() {
  const a = state.active;
  if (!a) return "—";
  return a.kind === "snap" ? snapFull(a.ds, a.snap) : a.ds;
}

function renderDatasetTree() {
  els.dsTree.innerHTML = "";
  if (!state.pool) return;

  const childrenOfSnap = new Map();
  for (const [name, meta] of state.datasets) {
    if (meta.kind === "clone")
      childrenOfSnap.set(meta.parentSnap,
        [...(childrenOfSnap.get(meta.parentSnap) ?? []), name]);
  }

  const topLevel = [...state.datasets.entries()]
    .filter(([, m]) => m.kind === "ds")
    .map(([n]) => n)
    .sort();

  els.dsTree.appendChild(poolRow());

  const wrap = document.createElement("div");
  wrap.className = "tree-children";
  els.dsTree.appendChild(wrap);

  for (const ds of topLevel) renderDsRow(wrap, ds, childrenOfSnap);
}

function poolRow() {
  const row = document.createElement("div");
  row.className = "row muted";
  row.innerHTML = `
    <div class="label">
      <span class="name">${state.pool}</span>
      <span class="tag pool">POOL</span>
    </div>
    <div></div>
    <div class="actions"></div>
  `;
  attachRowMenu(row, [
    {
      label: "+ New dataset",
      kind: "primary",
      run: () => inlineEdit(row, {
        placeholder: "data",
        hint: `${state.pool}/`,
        onCommit: withBusy(async (name) => {
          const full = `${state.pool}/${name}`;
          await mkDataset(full);
          setActive({ kind: "ds", ds: full });
          setStatus(`created ${full}`, "ok");
        }),
      }),
    },
  ]);
  return row;
}

function renderDsRow(parentEl, ds, childrenOfSnap) {
  const meta = state.datasets.get(ds);
  const isActive = state.active?.kind === "ds" && state.active.ds === ds;

  const row = document.createElement("div");
  row.className = "row" + (isActive ? " selected" : "");
  row.tabIndex = 0;
  row.dataset.id = ds;

  const tagText = meta.kind === "clone" ? "clone" : "ds";
  const pinHTML = isActive ? `<span class="pin" title="active"></span>` : "";

  row.innerHTML = `
    <div class="label">
      <span class="name">${ds}</span>
      <span class="tag ${tagText}">${tagText.toUpperCase()}</span>
    </div>
    <div>${pinHTML}</div>
    <div class="actions"></div>
  `;

  row.addEventListener("click", () => {
    setActive({ kind: "ds", ds });
    refresh();
  });

  attachRowMenu(row, [
    {
      label: "🌿 Branch (snap + clone)",
      kind: "primary",
      run: () => inlineEdit(row, {
        placeholder: nextBranchName(),
        hint: `${state.pool}/`,
        onCommit: withBusy(async (name) => {
          const full = `${state.pool}/${name}`;
          await branchFrom(ds, name);
          setActive({ kind: "ds", ds: full });
          setStatus(`branched ${ds} → ${full}`, "ok");
        }),
      }),
    },
    {
      label: "📷 Snapshot only",
      run: () => inlineEdit(row, {
        placeholder: nextSnapName(meta),
        hint: `${ds}@`,
        onCommit: withBusy(async (name) => {
          await mkSnap(ds, name);
          setStatus(`snapshot ${snapFull(ds, name)}`, "ok");
        }),
      }),
    },
    { divider: true },
    {
      label: "✕ Destroy dataset",
      kind: "danger",
      run: () => confirmInline(row, "Destroy?", withBusy(async () => {
        await destroyTarget(ds);
        if (state.active?.ds === ds) setActive(null);
        setStatus(`destroyed ${ds}`, "ok");
      })),
    },
  ]);

  parentEl.appendChild(row);

  const children = document.createElement("div");
  children.className = "tree-children";
  parentEl.appendChild(children);

  for (const snap of meta.snaps) {
    const full = snapFull(ds, snap);
    renderSnapRow(children, ds, snap, full);
    const cloneNames = childrenOfSnap.get(full) ?? [];
    for (const c of cloneNames) {
      const sub = document.createElement("div");
      sub.className = "tree-children";
      children.appendChild(sub);
      renderDsRow(sub, c, childrenOfSnap);
    }
  }
}

function renderSnapRow(parentEl, ds, snap, full) {
  const isActive = state.active?.kind === "snap"
    && state.active.ds === ds && state.active.snap === snap;
  const row = document.createElement("div");
  row.className = "row snap" + (isActive ? " selected" : "");
  row.tabIndex = 0;
  row.dataset.id = full;

  row.innerHTML = `
    <div class="label">
      <span class="name">@${snap}</span>
      <span class="tag snap">SNAP</span>
      <span class="tag ro">RO</span>
    </div>
    <div></div>
    <div class="actions"></div>
  `;

  row.addEventListener("click", () => {
    setActive({ kind: "snap", ds, snap });
    refresh();
  });

  attachRowMenu(row, [
    {
      label: "🌿 Branch from snapshot",
      kind: "primary",
      run: () => inlineEdit(row, {
        placeholder: nextBranchName(),
        hint: `${state.pool}/`,
        onCommit: withBusy(async (name) => {
          const dst = `${state.pool}/${name}`;
          await mkClone(full, dst);
          setActive({ kind: "ds", ds: dst });
          setStatus(`cloned ${full} → ${dst}`, "ok");
        }),
      }),
    },
    { divider: true },
    {
      label: "✕ Destroy snapshot",
      kind: "danger",
      run: () => confirmInline(row, "Destroy?", withBusy(async () => {
        await destroyTarget(full);
        if (state.active?.kind === "snap"
            && state.active.ds === ds && state.active.snap === snap) {
          setActive(null);
        }
        setStatus(`destroyed ${full}`, "ok");
      })),
    },
  ]);

  parentEl.appendChild(row);
}

// ---------- Per-row dropdown menu ----------
//
// Each tree row carries a single ⋯ button on the right. Clicking it
// opens a dropdown anchored beneath the button with the row's actions.
// One menu is open at a time; clicking outside or selecting an item
// closes it. Keyboard: Escape closes.

let openMenu = null;

function attachRowMenu(row, items) {
  const actionsEl = row.querySelector(".actions");
  const trigger = document.createElement("button");
  trigger.type = "button";
  trigger.className = "menu-trigger";
  trigger.title = "Actions";
  trigger.setAttribute("aria-label", "Actions");
  trigger.textContent = "⋯";
  actionsEl.appendChild(trigger);

  trigger.addEventListener("click", (e) => {
    e.stopPropagation();
    if (openMenu?.row === row) { closeMenu(); return; }
    closeMenu();
    showMenu(row, trigger, items);
  });
}

function showMenu(row, trigger, items) {
  const menu = document.createElement("div");
  menu.className = "row-menu";
  for (const item of items) {
    if (item.divider) {
      menu.appendChild(document.createElement("hr"));
      continue;
    }
    const btn = document.createElement("button");
    btn.type = "button";
    btn.textContent = item.label;
    if (item.kind) btn.classList.add(item.kind);
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      closeMenu();
      item.run();
    });
    menu.appendChild(btn);
  }
  trigger.classList.add("open");
  row.querySelector(".actions").appendChild(menu);
  openMenu = { row, menu, trigger };
}

function closeMenu() {
  if (!openMenu) return;
  openMenu.menu.remove();
  openMenu.trigger.classList.remove("open");
  openMenu = null;
}

document.addEventListener("click", () => closeMenu());
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") closeMenu();
});

function nextSnapName(meta) {
  return `v${meta.snaps.length + 1}`;
}
function nextBranchName() {
  let i = 1;
  while (state.datasets.has(`${state.pool}/branch${i}`)) i++;
  return `branch${i}`;
}

// ---------- Inline editing ----------
//
// Replaces a row's label cell with an input + ✓/✕ for the duration
// of the edit. Enter commits, Escape cancels. Only one inline edit
// at a time — opening another cancels the previous.

function inlineEdit(row, { placeholder, hint, onCommit }) {
  cancelInline();

  const labelCell = row.querySelector(".label");
  const original = labelCell.innerHTML;
  row.classList.add("editing");

  labelCell.innerHTML = `
    <span class="inline-edit">
      <span class="hint">${hint}</span>
      <input value="${placeholder}" />
      <button type="button" class="icon-btn primary" title="Confirm">✓</button>
      <button type="button" class="icon-btn" title="Cancel">✕</button>
    </span>
  `;
  const input = labelCell.querySelector("input");
  const [okBtn, cancelBtn] = labelCell.querySelectorAll("button");
  input.focus();
  input.select();

  const cancel = () => {
    state.editing = null;
    row.classList.remove("editing");
    labelCell.innerHTML = original;
  };
  const commit = async () => {
    const value = input.value.trim();
    if (!value) return cancel();
    state.editing = null;
    row.classList.remove("editing");
    labelCell.innerHTML = original;
    await onCommit(value);
    refresh();
  };

  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter") { e.preventDefault(); commit(); }
    if (e.key === "Escape") { e.preventDefault(); cancel(); }
  });
  okBtn.addEventListener("click", (e) => { e.stopPropagation(); commit(); });
  cancelBtn.addEventListener("click", (e) => { e.stopPropagation(); cancel(); });

  state.editing = { row, cancel };
}

function cancelInline() {
  if (state.editing) state.editing.cancel();
}

function confirmInline(row, message, onConfirm) {
  cancelInline();
  const actions = row.querySelector(".actions");
  const original = actions.innerHTML;
  row.classList.add("editing");

  actions.innerHTML = `
    <span class="hint" style="margin-right:6px;color:var(--err);">${message}</span>
    <button class="icon-btn danger" type="button">Yes</button>
    <button class="icon-btn"        type="button">No</button>
  `;
  const [yes, no] = actions.querySelectorAll("button");
  const restore = () => {
    state.editing = null;
    row.classList.remove("editing");
    actions.innerHTML = original;
  };
  yes.addEventListener("click", async (e) => {
    e.stopPropagation();
    restore();
    await onConfirm();
    refresh();
  });
  no.addEventListener("click", (e) => { e.stopPropagation(); restore(); });
  state.editing = { row, cancel: restore };
}

// ---------- File panel + editor ----------

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
    : "Click a file to open. New file… adds one.";

  const sorted = [...meta.files.keys()].sort();
  if (!sorted.length) {
    const empty = document.createElement("div");
    empty.className = "row muted";
    empty.textContent = "(empty)";
    els.fileTree.appendChild(empty);
    return;
  }

  for (const path of sorted) {
    const file = meta.files.get(path);
    const isSel = state.active?.file === path;
    const row = document.createElement("div");
    row.className = "row" + (isSel ? " selected" : "");
    row.innerHTML = `
      <div class="label">
        <span class="name">${path}</span>
        <span class="tag">${file.size}B</span>
      </div>
      <div></div>
      <div></div>
    `;
    row.addEventListener("click", () => {
      state.active = { ...state.active, file: path };
      refresh();
      loadFileIntoEditor(path);
    });
    els.fileTree.appendChild(row);
  }
}

async function loadFileIntoEditor(path) {
  const target = state.active.kind === "snap"
    ? snapFull(state.active.ds, state.active.snap)
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

// ---------- Modal (only used for multi-line file content) ----------

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
