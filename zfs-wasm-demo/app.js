import { createSnapshotFs } from "./browser-host.js";

const state = {
  fs: null,
  selectedPath: "/",
  selectedBranchNode: "branch:main",
  expandedDirs: new Set(["/"]),
  fileTreeNodes: [],
  branchTreeNodes: [],
  branchInfo: [],
  snapshotInfo: [],
  fileDraft: null,
  branchDraft: null,
  pendingFileFocusPath: "/",
  pendingBranchFocusKey: "branch:main",
  pendingInlineFocus: null,
  logEntries: [],
};

const elements = {};

for (const id of [
  "metric-branch",
  "metric-snapshots",
  "metric-branches",
  "metric-bytes",
  "tree-selection",
  "tree-view",
  "branch-selection",
  "branch-tree-view",
  "read-target",
  "directory-summary",
  "file-editor",
  "stats-view",
  "log-view",
]) {
  elements[id] = document.getElementById(id);
}

function nowLabel() {
  return new Date().toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function basename(path) {
  return path === "/" ? "/" : path.split("/").filter(Boolean).at(-1);
}

function joinPath(parent, name) {
  return parent === "/" ? `/${name}` : `${parent}/${name}`;
}

function parentPath(path) {
  if (path === "/") {
    return "/";
  }
  const parts = path.split("/").filter(Boolean);
  parts.pop();
  return parts.length ? `/${parts.join("/")}` : "/";
}

function listDirSafe(path) {
  try {
    return state.fs.listDir(path);
  } catch {
    return null;
  }
}

function isDirectory(path) {
  return listDirSafe(path) !== null;
}

function selectedDirectoryForCreate() {
  return isDirectory(state.selectedPath) ? state.selectedPath : parentPath(state.selectedPath);
}

function branchInfoByName(name) {
  return state.branchInfo.find((branch) => branch.name === name) ?? null;
}

function snapshotInfoByName(name) {
  return state.snapshotInfo.find((snapshot) => snapshot.name === name) ?? null;
}

function pushLog(action, message, ok = true) {
  state.logEntries.unshift({ action, message, ok, time: nowLabel() });
  state.logEntries = state.logEntries.slice(0, 50);
  renderLog();
}

function renderLog() {
  if (state.logEntries.length === 0) {
    elements["log-view"].innerHTML = '<div class="empty-state">Journal is empty.</div>';
    return;
  }

  elements["log-view"].innerHTML = state.logEntries
    .map(
      (entry) => `
        <article class="log-entry">
          <div class="log-meta">
            <strong>${escapeHtml(entry.action)}</strong>
            <span class="${entry.ok ? "log-status-ok" : "log-status-error"}">${entry.ok ? "ok" : "error"} · ${escapeHtml(entry.time)}</span>
          </div>
          <div class="log-message">${escapeHtml(entry.message)}</div>
        </article>
      `,
    )
    .join("");
}

function refreshMetadata() {
  state.branchInfo = state.fs.branchInfo();
  state.snapshotInfo = state.fs.snapshotInfo();
}

function ensureSelectionsAreValid() {
  if (state.selectedPath !== "/" && !state.fs.exists(state.selectedPath)) {
    state.selectedPath = parentPath(state.selectedPath);
  }

  const branchKeys = new Set(state.branchInfo.map((branch) => `branch:${branch.name}`));
  const snapshotKeys = new Set(state.snapshotInfo.map((snapshot) => `snapshot:${snapshot.name}`));

  if (
    !branchKeys.has(state.selectedBranchNode) &&
    !snapshotKeys.has(state.selectedBranchNode)
  ) {
    state.selectedBranchNode = `branch:${state.fs.currentBranch()}`;
  }

  for (const path of [...state.expandedDirs]) {
    if (path !== "/" && !isDirectory(path)) {
      state.expandedDirs.delete(path);
    }
  }

  if (state.fileDraft && !isDirectory(state.fileDraft.parentPath)) {
    state.fileDraft = null;
  }

  if (
    state.branchDraft &&
    !state.branchInfo.some((branch) => branch.name === state.branchDraft.parentBranch)
  ) {
    state.branchDraft = null;
  }
}

function buildFileTreeNodes(path = "/", depth = 0, nodes = []) {
  const children = listDirSafe(path) ?? [];
  nodes.push({
    key: path,
    label: basename(path),
    path,
    kind: "Directory",
    depth,
    expanded: state.expandedDirs.has(path),
    meta: path === "/" ? "root" : "dir",
  });

  if (!state.expandedDirs.has(path)) {
    return nodes;
  }

  if (state.fileDraft?.parentPath === path) {
    nodes.push({
      key: `draft:${state.fileDraft.kind}:${path}`,
      label: state.fileDraft.name,
      path,
      kind: state.fileDraft.kind === "file" ? "DraftFile" : "DraftDirectory",
      depth: depth + 1,
      expanded: false,
      meta: state.fileDraft.kind === "file" ? "new file" : "new folder",
    });
  }

  for (const entry of children) {
    const childPath = joinPath(path, entry.name);
    if (entry.kind === "Directory") {
      buildFileTreeNodes(childPath, depth + 1, nodes);
    } else {
      nodes.push({
        key: childPath,
        label: entry.name,
        path: childPath,
        kind: "File",
        depth: depth + 1,
        expanded: false,
        meta: "file",
      });
    }
  }

  return nodes;
}

function appendBranchNodes(branchName, depth, nodes, childBranches, snapshotsByBranch) {
  const branch = branchInfoByName(branchName);
  nodes.push({
    key: `branch:${branchName}`,
    label: branchName,
    kind: "Branch",
    depth,
    expanded: true,
    meta:
      branchName === state.fs.currentBranch()
        ? "current"
        : branch?.source_snapshot
          ? `from ${branch.source_snapshot}`
          : "root",
  });

  if (state.branchDraft?.kind === "snapshot" && state.branchDraft.parentBranch === branchName) {
    nodes.push({
      key: `draft:snapshot:${branchName}`,
      label: state.branchDraft.name,
      kind: "DraftSnapshot",
      depth: depth + 1,
      expanded: false,
      meta: "new snapshot",
    });
  }

  for (const snapshot of snapshotsByBranch.get(branchName) ?? []) {
    nodes.push({
      key: `snapshot:${snapshot.name}`,
      label: snapshot.name,
      kind: "Snapshot",
      depth: depth + 1,
      expanded: false,
      meta: "restore",
    });
  }

  if (state.branchDraft?.kind === "branch" && state.branchDraft.parentBranch === branchName) {
    nodes.push({
      key: `draft:branch:${branchName}`,
      label: state.branchDraft.name,
      kind: "DraftBranch",
      depth: depth + 1,
      expanded: false,
      meta: state.branchDraft.sourceSnapshot
        ? `from ${state.branchDraft.sourceSnapshot}`
        : "auto snapshot",
    });
  }

  for (const childBranch of childBranches.get(branchName) ?? []) {
    appendBranchNodes(childBranch, depth + 1, nodes, childBranches, snapshotsByBranch);
  }
}

function buildBranchTreeNodes() {
  const childBranches = new Map();
  for (const branch of state.branchInfo) {
    const key = branch.parent_branch ?? "";
    if (!childBranches.has(key)) {
      childBranches.set(key, []);
    }
    childBranches.get(key).push(branch.name);
  }

  for (const names of childBranches.values()) {
    names.sort((left, right) => left.localeCompare(right));
  }

  const snapshotsByBranch = new Map();
  for (const snapshot of state.snapshotInfo) {
    if (!snapshotsByBranch.has(snapshot.source_branch)) {
      snapshotsByBranch.set(snapshot.source_branch, []);
    }
    snapshotsByBranch.get(snapshot.source_branch).push(snapshot);
  }

  for (const snapshots of snapshotsByBranch.values()) {
    snapshots.sort((left, right) => left.name.localeCompare(right.name));
  }

  const nodes = [];
  for (const rootBranch of childBranches.get("") ?? []) {
    appendBranchNodes(rootBranch, 0, nodes, childBranches, snapshotsByBranch);
  }
  return nodes;
}

function treeDraftMarkup(node) {
  const scope = node.kind === "DraftFile" || node.kind === "DraftDirectory" ? "file" : "branch";
  const icon =
    node.kind === "DraftFile"
      ? "＋"
      : node.kind === "DraftDirectory"
        ? "＋"
        : node.kind === "DraftSnapshot"
          ? "＋"
          : "＋";
  const placeholder =
    node.kind === "DraftFile"
      ? "untitled.txt"
      : node.kind === "DraftDirectory"
        ? "new-folder"
        : node.kind === "DraftSnapshot"
          ? `${state.fs.currentBranch()}-snapshot`
          : "feature";

  return `
    <div class="tree-draft" style="--depth:${node.depth}" data-draft-scope="${scope}">
      <span class="tree-row-indent" aria-hidden="true"></span>
      <span class="tree-chevron" aria-hidden="true"></span>
      <span class="tree-icon" aria-hidden="true">${icon}</span>
      <input
        class="tree-draft-input"
        data-draft-scope="${scope}"
        type="text"
        value="${escapeHtml(node.label)}"
        placeholder="${escapeHtml(placeholder)}"
        aria-label="${escapeHtml(node.meta)}"
      />
      <span class="tree-meta">${escapeHtml(node.meta)}</span>
      <button type="button" class="tree-mini-button" data-draft-action="commit" data-draft-scope="${scope}">Save</button>
      <button type="button" class="tree-mini-button ghosty" data-draft-action="cancel" data-draft-scope="${scope}">Cancel</button>
    </div>
  `;
}

function treeRowMarkup(node, index, selectedKey) {
  if (node.kind.startsWith("Draft")) {
    return treeDraftMarkup(node);
  }

  const selected = node.key === selectedKey;
  const icon =
    node.kind === "Directory"
      ? "📁"
      : node.kind === "File"
        ? "📄"
        : node.kind === "Branch"
          ? "🌿"
          : "📸";

  return `
    <button
      type="button"
      class="tree-row ${selected ? "selected" : ""}"
      role="treeitem"
      aria-level="${node.depth + 1}"
      aria-selected="${selected}"
      tabindex="${selected ? "0" : "-1"}"
      data-index="${index}"
      data-key="${escapeHtml(node.key)}"
      data-kind="${node.kind}"
      title="${escapeHtml(node.kind === "Snapshot" ? "Click to restore the current branch to this snapshot." : node.kind === "Branch" ? "Click to switch to this branch." : node.label)}"
    >
      <span class="tree-row-inner">
        <span class="tree-row-indent" style="--depth:${node.depth}"></span>
        <span class="tree-chevron">${node.kind === "Directory" ? (node.expanded ? "▾" : "▸") : ""}</span>
        <span class="tree-icon" aria-hidden="true">${icon}</span>
        <span class="tree-label">${escapeHtml(node.label)}</span>
        <span class="tree-meta">${escapeHtml(node.meta)}</span>
      </span>
    </button>
  `;
}

function focusPendingDraftInput(scope, root) {
  if (state.pendingInlineFocus !== scope) {
    return;
  }
  const input = root.querySelector(`.tree-draft-input[data-draft-scope="${scope}"]`);
  if (input) {
    input.focus();
    input.select();
  }
  state.pendingInlineFocus = null;
}

function renderFileTree() {
  state.fileTreeNodes = buildFileTreeNodes();
  elements["tree-selection"].textContent = `Selected: ${state.selectedPath}`;
  elements["tree-view"].innerHTML = state.fileTreeNodes
    .map((node, index) => treeRowMarkup(node, index, state.selectedPath))
    .join("");
  focusPendingFileTreeNode();
  focusPendingDraftInput("file", elements["tree-view"]);
}

function renderBranchTree() {
  state.branchTreeNodes = buildBranchTreeNodes();
  const selectedLabel = state.selectedBranchNode.replace(/^[^:]+:/, "");
  elements["branch-selection"].textContent = `Selected: ${selectedLabel}`;
  elements["branch-tree-view"].innerHTML = state.branchTreeNodes
    .map((node, index) => treeRowMarkup(node, index, state.selectedBranchNode))
    .join("");
  focusPendingBranchTreeNode();
  focusPendingDraftInput("branch", elements["branch-tree-view"]);
}

function focusPendingFileTreeNode() {
  const key = state.pendingFileFocusPath ?? state.selectedPath;
  const row = elements["tree-view"].querySelector(`[data-key="${CSS.escape(key)}"]`);
  if (row) {
    row.focus();
  }
  state.pendingFileFocusPath = null;
}

function focusPendingBranchTreeNode() {
  const key = state.pendingBranchFocusKey ?? state.selectedBranchNode;
  const row = elements["branch-tree-view"].querySelector(`[data-key="${CSS.escape(key)}"]`);
  if (row) {
    row.focus();
  }
  state.pendingBranchFocusKey = null;
}

function renderPreview() {
  elements["read-target"].textContent = state.selectedPath;
  if (isDirectory(state.selectedPath)) {
    const entries = state.fs.listDir(state.selectedPath);
    elements["directory-summary"].innerHTML = entries.length
      ? entries
          .map(
            (entry) => `
              <div class="directory-entry">
                <span>${escapeHtml(entry.name)}</span>
                <span class="directory-entry-kind">${escapeHtml(entry.kind)}</span>
              </div>
            `,
          )
          .join("")
      : '<div class="empty-state">Directory is empty.</div>';
    elements["file-editor"].value = "";
    elements["file-editor"].readOnly = true;
    return;
  }

  elements["directory-summary"].innerHTML = '<div class="empty-state">Editing file contents.</div>';
  elements["file-editor"].readOnly = false;
  elements["file-editor"].value = state.fs.readText(state.selectedPath);
}

function syncStats() {
  const stats = state.fs.stats();
  elements["metric-branch"].textContent = stats.current_branch;
  elements["metric-snapshots"].textContent = String(stats.snapshot_count);
  elements["metric-branches"].textContent = String(stats.branch_count);
  elements["metric-bytes"].textContent = String(stats.current_logical_bytes);
  elements["stats-view"].textContent = JSON.stringify(stats, null, 2);
}

function selectFilePath(path) {
  state.selectedPath = path;
  state.pendingFileFocusPath = path;
  if (!isDirectory(path)) {
    state.expandedDirs.add(parentPath(path));
  }
}

function toggleDirectory(path, expand = null) {
  const next = expand ?? !state.expandedDirs.has(path);
  if (next) {
    state.expandedDirs.add(path);
  } else if (path !== "/") {
    state.expandedDirs.delete(path);
  }
}

function withAction(action, fn) {
  try {
    const result = fn();
    refreshAll();
    return result;
  } catch (error) {
    pushLog(action, String(error), false);
    return null;
  }
}

function deleteSelectedPath() {
  if (state.selectedPath === "/") {
    pushLog("delete", "cannot delete root", false);
    return;
  }
  withAction("delete", () => {
    const target = state.selectedPath;
    state.fs.delete(target);
    state.selectedPath = parentPath(target);
    state.pendingFileFocusPath = state.selectedPath;
    pushLog("delete", `deleted ${target}`);
  });
}

function refreshAll() {
  refreshMetadata();
  ensureSelectionsAreValid();
  syncStats();
  renderFileTree();
  renderBranchTree();
  renderPreview();
}

function focusTreeIndex(nodes, index, kind) {
  const node = nodes[index];
  if (!node || node.kind.startsWith("Draft")) {
    return;
  }

  if (kind === "file") {
    selectFilePath(node.key);
  } else {
    state.selectedBranchNode = node.key;
    state.pendingBranchFocusKey = node.key;
  }
  refreshAll();
}

function startFileDraft(kind) {
  const parentPathForDraft = selectedDirectoryForCreate();
  state.expandedDirs.add(parentPathForDraft);
  state.fileDraft = { kind, parentPath: parentPathForDraft, name: "" };
  state.pendingInlineFocus = "file";
  refreshAll();
}

function updateFileDraft(name) {
  if (state.fileDraft) {
    state.fileDraft.name = name;
  }
}

function cancelFileDraft() {
  state.fileDraft = null;
  refreshAll();
}

function commitFileDraft() {
  const draft = state.fileDraft;
  if (!draft) {
    return;
  }

  const name = draft.name.trim();
  if (!name) {
    pushLog(draft.kind === "file" ? "new-file" : "new-dir", "name cannot be empty", false);
    return;
  }
  if (name.includes("/")) {
    pushLog(draft.kind === "file" ? "new-file" : "new-dir", "use a single name, not a full path", false);
    return;
  }

  const targetPath = joinPath(draft.parentPath, name);
  withAction(draft.kind === "file" ? "new-file" : "new-dir", () => {
    if (draft.kind === "file") {
      state.fs.writeText(targetPath, "");
    } else {
      state.fs.createDir(targetPath);
    }
    state.fileDraft = null;
    selectFilePath(targetPath);
    pushLog(draft.kind === "file" ? "new-file" : "new-dir", `created ${targetPath}`);
  });
}

function startBranchDraft(kind) {
  if (kind === "snapshot") {
    state.branchDraft = {
      kind,
      parentBranch: state.fs.currentBranch(),
      sourceSnapshot: null,
      name: "",
    };
  } else {
    const selectedSnapshot = state.selectedBranchNode.startsWith("snapshot:")
      ? state.selectedBranchNode.replace(/^snapshot:/, "")
      : null;
    const selectedBranch = state.selectedBranchNode.startsWith("branch:")
      ? state.selectedBranchNode.replace(/^branch:/, "")
      : state.fs.currentBranch();
    state.branchDraft = {
      kind,
      parentBranch: selectedSnapshot
        ? (snapshotInfoByName(selectedSnapshot)?.source_branch ?? state.fs.currentBranch())
        : selectedBranch,
      sourceSnapshot: selectedSnapshot,
      name: "",
    };
  }

  state.pendingInlineFocus = "branch";
  refreshAll();
}

function updateBranchDraft(name) {
  if (state.branchDraft) {
    state.branchDraft.name = name;
  }
}

function cancelBranchDraft() {
  state.branchDraft = null;
  refreshAll();
}

function commitBranchDraft() {
  const draft = state.branchDraft;
  if (!draft) {
    return;
  }

  const name = draft.name.trim();
  if (!name) {
    pushLog(draft.kind === "snapshot" ? "snapshot" : "new-branch", "name cannot be empty", false);
    return;
  }
  if (name.includes("/")) {
    pushLog(draft.kind === "snapshot" ? "snapshot" : "new-branch", "name cannot contain slashes", false);
    return;
  }

  if (draft.kind === "snapshot") {
    withAction("snapshot", () => {
      state.fs.snapshot(name);
      state.branchDraft = null;
      state.selectedBranchNode = `snapshot:${name}`;
      state.pendingBranchFocusKey = `snapshot:${name}`;
      pushLog("snapshot", `captured ${name} on ${state.fs.currentBranch()}`);
    });
    return;
  }

  withAction("new-branch", () => {
    let snapshotName = draft.sourceSnapshot;
    if (!snapshotName) {
      snapshotName = `${draft.parentBranch}-base-${Date.now()}`;
      if (state.fs.currentBranch() !== draft.parentBranch) {
        state.fs.checkoutBranch(draft.parentBranch);
      }
      state.fs.snapshot(snapshotName);
    }
    state.fs.cloneSnapshot(snapshotName, name);
    state.fs.checkoutBranch(name);
    state.branchDraft = null;
    state.selectedBranchNode = `branch:${name}`;
    state.pendingBranchFocusKey = `branch:${name}`;
    pushLog("new-branch", `created branch ${name} from ${snapshotName}`);
  });
}

function handleDraftKeydown(event, scope) {
  if (event.key === "Enter") {
    event.preventDefault();
    if (scope === "file") {
      commitFileDraft();
    } else {
      commitBranchDraft();
    }
  } else if (event.key === "Escape") {
    event.preventDefault();
    if (scope === "file") {
      cancelFileDraft();
    } else {
      cancelBranchDraft();
    }
  }
}

function attachFileTreeHandlers() {
  elements["tree-view"].addEventListener("input", (event) => {
    const input = event.target.closest('.tree-draft-input[data-draft-scope="file"]');
    if (!input) {
      return;
    }
    updateFileDraft(input.value);
  });

  elements["tree-view"].addEventListener("click", (event) => {
    const draftAction = event.target.closest('[data-draft-scope="file"][data-draft-action]');
    if (draftAction) {
      event.preventDefault();
      if (draftAction.dataset.draftAction === "commit") {
        commitFileDraft();
      } else {
        cancelFileDraft();
      }
      return;
    }

    if (event.target.closest(".tree-draft")) {
      return;
    }

    const row = event.target.closest(".tree-row");
    if (!row) {
      return;
    }

    const path = row.dataset.key;
    const kind = row.dataset.kind;
    selectFilePath(path);
    if (kind === "Directory") {
      toggleDirectory(path);
    }
    refreshAll();
  });

  elements["tree-view"].addEventListener("keydown", (event) => {
    const draftInput = event.target.closest('.tree-draft-input[data-draft-scope="file"]');
    if (draftInput) {
      handleDraftKeydown(event, "file");
      return;
    }

    const row = event.target.closest(".tree-row");
    if (!row) {
      return;
    }
    const index = Number(row.dataset.index);
    const node = state.fileTreeNodes[index];
    if (!node) {
      return;
    }

    switch (event.key) {
      case "ArrowDown":
        event.preventDefault();
        focusTreeIndex(state.fileTreeNodes, Math.min(index + 1, state.fileTreeNodes.length - 1), "file");
        break;
      case "ArrowUp":
        event.preventDefault();
        focusTreeIndex(state.fileTreeNodes, Math.max(index - 1, 0), "file");
        break;
      case "ArrowRight":
        event.preventDefault();
        if (node.kind === "Directory" && !node.expanded) {
          toggleDirectory(node.path, true);
          refreshAll();
        }
        break;
      case "ArrowLeft":
        event.preventDefault();
        if (node.kind === "Directory" && node.expanded && node.path !== "/") {
          toggleDirectory(node.path, false);
          refreshAll();
        } else if (node.path !== "/") {
          selectFilePath(parentPath(node.path));
          refreshAll();
        }
        break;
      case "Enter":
      case " ":
        event.preventDefault();
        if (node.kind === "Directory") {
          toggleDirectory(node.path);
        }
        selectFilePath(node.path);
        refreshAll();
        break;
    }
  });

  document.getElementById("tree-new-file").addEventListener("click", () => {
    startFileDraft("file");
  });

  document.getElementById("tree-new-dir").addEventListener("click", () => {
    startFileDraft("directory");
  });

  document.getElementById("tree-refresh").addEventListener("click", () => {
    refreshAll();
    pushLog("tree-refresh", "refreshed filesystem tree");
  });

  document.getElementById("tree-delete").addEventListener("click", () => {
    deleteSelectedPath();
  });
}

function attachBranchTreeHandlers() {
  elements["branch-tree-view"].addEventListener("input", (event) => {
    const input = event.target.closest('.tree-draft-input[data-draft-scope="branch"]');
    if (!input) {
      return;
    }
    updateBranchDraft(input.value);
  });

  elements["branch-tree-view"].addEventListener("click", (event) => {
    const draftAction = event.target.closest('[data-draft-scope="branch"][data-draft-action]');
    if (draftAction) {
      event.preventDefault();
      if (draftAction.dataset.draftAction === "commit") {
        commitBranchDraft();
      } else {
        cancelBranchDraft();
      }
      return;
    }

    if (event.target.closest(".tree-draft")) {
      return;
    }

    const row = event.target.closest(".tree-row");
    if (!row) {
      return;
    }

    const key = row.dataset.key;
    const kind = row.dataset.kind;
    state.selectedBranchNode = key;
    state.pendingBranchFocusKey = key;

    if (kind === "Branch") {
      withAction("checkout", () => {
        const branchName = key.replace(/^branch:/, "");
        state.fs.checkoutBranch(branchName);
        pushLog("checkout", `switched to ${branchName}`);
      });
      return;
    }

    if (kind === "Snapshot") {
      withAction("rollback", () => {
        const snapshotName = key.replace(/^snapshot:/, "");
        state.fs.rollback(snapshotName);
        pushLog("rollback", `restored ${state.fs.currentBranch()} to ${snapshotName}`);
      });
      return;
    }

    refreshAll();
  });

  elements["branch-tree-view"].addEventListener("keydown", (event) => {
    const draftInput = event.target.closest('.tree-draft-input[data-draft-scope="branch"]');
    if (draftInput) {
      handleDraftKeydown(event, "branch");
      return;
    }

    const row = event.target.closest(".tree-row");
    if (!row) {
      return;
    }
    const index = Number(row.dataset.index);
    const node = state.branchTreeNodes[index];
    if (!node) {
      return;
    }

    switch (event.key) {
      case "ArrowDown":
        event.preventDefault();
        focusTreeIndex(
          state.branchTreeNodes,
          Math.min(index + 1, state.branchTreeNodes.length - 1),
          "branch",
        );
        break;
      case "ArrowUp":
        event.preventDefault();
        focusTreeIndex(state.branchTreeNodes, Math.max(index - 1, 0), "branch");
        break;
      case "Enter":
      case " ":
        event.preventDefault();
        if (node.kind === "Branch") {
          withAction("checkout", () => {
            const branchName = node.key.replace(/^branch:/, "");
            state.fs.checkoutBranch(branchName);
            state.selectedBranchNode = node.key;
            pushLog("checkout", `switched to ${branchName}`);
          });
        } else if (node.kind === "Snapshot") {
          withAction("rollback", () => {
            const snapshotName = node.key.replace(/^snapshot:/, "");
            state.fs.rollback(snapshotName);
            state.selectedBranchNode = node.key;
            pushLog("rollback", `restored ${state.fs.currentBranch()} to ${snapshotName}`);
          });
        }
        break;
    }
  });

  document.getElementById("branch-new-snapshot").addEventListener("click", () => {
    startBranchDraft("snapshot");
  });

  document.getElementById("branch-new-branch").addEventListener("click", () => {
    startBranchDraft("branch");
  });

  document.getElementById("branch-refresh").addEventListener("click", () => {
    refreshAll();
    pushLog("branch-refresh", "refreshed branch tree");
  });
}

function attachPreviewHandlers() {
  document.getElementById("preview-save").addEventListener("click", () => {
    if (isDirectory(state.selectedPath)) {
      pushLog("save", "selected node is a directory", false);
      return;
    }
    withAction("save", () => {
      state.fs.writeText(state.selectedPath, elements["file-editor"].value);
      pushLog("save", `saved ${state.selectedPath}`);
    });
  });

  document.getElementById("preview-refresh").addEventListener("click", () => {
    refreshAll();
    pushLog("preview-refresh", `refreshed ${state.selectedPath}`);
  });
}

function attachGlobalButtons() {
  document.getElementById("seed-demo").addEventListener("click", () => {
    withAction("scenario", () => {
      state.fs.writeText("/projects/demo/readme.txt", "main branch draft");
      state.fs.writeText("/projects/demo/plan.md", "1. snapshot\n2. branch\n3. mutate");
      state.fs.snapshot("seed");
      state.fs.cloneSnapshot("seed", "feature-ui");
      state.fs.checkoutBranch("feature-ui");
      state.fs.writeText("/projects/demo/readme.txt", "feature branch draft");
      state.fs.writeText("/projects/demo/branch-only.txt", "only on feature-ui");
      state.expandedDirs.add("/projects");
      state.expandedDirs.add("/projects/demo");
      state.selectedPath = "/projects/demo/readme.txt";
      state.selectedBranchNode = "branch:feature-ui";
      pushLog("scenario", "loaded demo scenario with snapshot seed and branch feature-ui");
    });
  });

  document.getElementById("refresh-all").addEventListener("click", () => {
    refreshAll();
    pushLog("refresh", "refreshed all views");
  });

  document.getElementById("reset-fs").addEventListener("click", async () => {
    state.fs = await createSnapshotFs();
    state.selectedPath = "/";
    state.selectedBranchNode = "branch:main";
    state.expandedDirs = new Set(["/"]);
    state.fileDraft = null;
    state.branchDraft = null;
    state.pendingInlineFocus = null;
    refreshAll();
    pushLog("reset", "replaced the in-memory filesystem with a fresh instance");
  });

  document.getElementById("clear-log").addEventListener("click", () => {
    state.logEntries = [];
    renderLog();
  });
}

async function boot() {
  state.fs = await createSnapshotFs();
  attachFileTreeHandlers();
  attachBranchTreeHandlers();
  attachPreviewHandlers();
  attachGlobalButtons();
  renderLog();
  refreshAll();
  pushLog("boot", "browser demo ready");
}

boot().catch((error) => {
  elements["file-editor"].readOnly = true;
  elements["file-editor"].value = `Boot failed:\n${String(error)}`;
  pushLog("boot", String(error), false);
});
