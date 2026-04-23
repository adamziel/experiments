import { createSnapshotFs } from "./browser-host.js";

const state = {
  fs: null,
  selectedPath: "/",
  selectedBranchNode: "branch:main",
  expandedDirs: new Set(["/"]),
  fileTreeNodes: [],
  branchTreeNodes: [],
  pendingFileFocusPath: "/",
  pendingBranchFocusKey: "branch:main",
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

function ensureSelectionsAreValid() {
  if (state.selectedPath !== "/" && !state.fs.exists(state.selectedPath)) {
    state.selectedPath = parentPath(state.selectedPath);
  }

  const branchNames = state.fs.branchNames();
  const snapshotNames = state.fs.snapshotNames();
  if (!branchNames.includes(state.selectedBranchNode.replace(/^branch:/, ""))) {
    state.selectedBranchNode = `branch:${state.fs.currentBranch()}`;
  }

  for (const path of [...state.expandedDirs]) {
    if (path !== "/" && !isDirectory(path)) {
      state.expandedDirs.delete(path);
    }
  }

  for (const key of [...snapshotNames]) {
    void key;
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
  });

  if (!state.expandedDirs.has(path)) {
    return nodes;
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
      });
    }
  }

  return nodes;
}

function buildBranchTreeNodes() {
  const nodes = [
    {
      key: "group:branches",
      label: "Branches",
      kind: "Group",
      depth: 0,
      expanded: true,
    },
  ];

  for (const branch of state.fs.branchNames()) {
    nodes.push({
      key: `branch:${branch}`,
      label: branch,
      kind: "Branch",
      depth: 1,
      expanded: false,
    });
  }

  nodes.push({
    key: "group:snapshots",
    label: "Snapshots",
    kind: "Group",
    depth: 0,
    expanded: true,
  });

  for (const snapshot of state.fs.snapshotNames()) {
    nodes.push({
      key: `snapshot:${snapshot}`,
      label: snapshot,
      kind: "Snapshot",
      depth: 1,
      expanded: false,
    });
  }

  return nodes;
}

function treeRowMarkup(node, index, selectedKey) {
  const expandable = node.kind === "Directory" || node.kind === "Group";
  const selected = node.key === selectedKey;
  const icon =
    node.kind === "Directory"
      ? "📁"
      : node.kind === "File"
        ? "📄"
        : node.kind === "Branch"
          ? "🌿"
          : node.kind === "Snapshot"
            ? "📸"
            : "▤";
  const meta =
    node.kind === "Branch"
      ? (node.label === state.fs.currentBranch() ? "current" : "branch")
      : node.kind === "Snapshot"
        ? "snapshot"
        : node.kind === "Directory"
          ? "dir"
          : node.kind === "File"
            ? "file"
            : "group";
  return `
    <button
      type="button"
      class="tree-row ${selected ? "selected" : ""}"
      role="treeitem"
      aria-level="${node.depth + 1}"
      ${expandable ? `aria-expanded="${node.expanded}"` : ""}
      aria-selected="${selected}"
      tabindex="${selected ? "0" : "-1"}"
      data-index="${index}"
      data-key="${escapeHtml(node.key)}"
      data-kind="${node.kind}"
    >
      <span class="tree-row-inner">
        <span class="tree-row-indent" style="--depth:${node.depth}"></span>
        <span class="tree-chevron">${node.kind === "Directory" ? (node.expanded ? "▾" : "▸") : ""}</span>
        <span class="tree-icon" aria-hidden="true">${icon}</span>
        <span class="tree-label">${escapeHtml(node.label)}</span>
        <span class="tree-meta">${escapeHtml(meta)}</span>
      </span>
    </button>
  `;
}

function renderFileTree() {
  state.fileTreeNodes = buildFileTreeNodes();
  elements["tree-selection"].textContent = `Selected: ${state.selectedPath}`;
  elements["tree-view"].innerHTML = state.fileTreeNodes
    .map((node, index) => treeRowMarkup(node, index, state.selectedPath))
    .join("");
  focusPendingFileTreeNode();
}

function renderBranchTree() {
  state.branchTreeNodes = buildBranchTreeNodes();
  const selectedLabel = state.selectedBranchNode.replace(/^[^:]+:/, "");
  elements["branch-selection"].textContent = `Selected: ${selectedLabel}`;
  elements["branch-tree-view"].innerHTML = state.branchTreeNodes
    .map((node, index) => treeRowMarkup(node, index, state.selectedBranchNode))
    .join("");
  focusPendingBranchTreeNode();
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
    throw error;
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
  ensureSelectionsAreValid();
  syncStats();
  renderFileTree();
  renderBranchTree();
  renderPreview();
}

function focusTreeIndex(nodes, index, kind) {
  const node = nodes[index];
  if (!node) {
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

function attachFileTreeHandlers() {
  elements["tree-view"].addEventListener("click", (event) => {
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
    const candidate = prompt("New file path", joinPath(selectedDirectoryForCreate(), "untitled.txt"));
    if (!candidate) {
      return;
    }
    withAction("new-file", () => {
      state.fs.writeText(candidate, "");
      selectFilePath(candidate);
      pushLog("new-file", `created ${candidate}`);
    });
  });

  document.getElementById("tree-new-dir").addEventListener("click", () => {
    const candidate = prompt("New folder path", joinPath(selectedDirectoryForCreate(), "new-folder"));
    if (!candidate) {
      return;
    }
    withAction("new-dir", () => {
      state.fs.createDir(candidate);
      selectFilePath(candidate);
      pushLog("new-dir", `created ${candidate}`);
    });
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
  elements["branch-tree-view"].addEventListener("click", (event) => {
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
        state.fs.checkoutBranch(key.replace(/^branch:/, ""));
        pushLog("checkout", `switched to ${key.replace(/^branch:/, "")}`);
      });
      return;
    }

    refreshAll();
  });

  elements["branch-tree-view"].addEventListener("keydown", (event) => {
    const row = event.target.closest(".tree-row");
    if (!row) {
      return;
    }
    const index = Number(row.dataset.index);
    switch (event.key) {
      case "ArrowDown":
        event.preventDefault();
        focusTreeIndex(state.branchTreeNodes, Math.min(index + 1, state.branchTreeNodes.length - 1), "branch");
        break;
      case "ArrowUp":
        event.preventDefault();
        focusTreeIndex(state.branchTreeNodes, Math.max(index - 1, 0), "branch");
        break;
      case "Enter":
      case " ":
        event.preventDefault();
        if (row.dataset.kind === "Branch") {
          withAction("checkout", () => {
            state.fs.checkoutBranch(row.dataset.key.replace(/^branch:/, ""));
            state.selectedBranchNode = row.dataset.key;
            pushLog("checkout", `switched to ${row.dataset.key.replace(/^branch:/, "")}`);
          });
        }
        break;
    }
  });

  document.getElementById("branch-new-snapshot").addEventListener("click", () => {
    const candidate = prompt("Snapshot name", `${state.fs.currentBranch()}-${Date.now()}`);
    if (!candidate) {
      return;
    }
    withAction("snapshot", () => {
      state.fs.snapshot(candidate);
      state.selectedBranchNode = `snapshot:${candidate}`;
      pushLog("snapshot", `captured ${candidate}`);
    });
  });

  document.getElementById("branch-new-branch").addEventListener("click", () => {
    const branchName = prompt("New branch name", "feature");
    if (!branchName) {
      return;
    }

    let snapshotName = state.selectedBranchNode.startsWith("snapshot:")
      ? state.selectedBranchNode.replace(/^snapshot:/, "")
      : null;

    withAction("new-branch", () => {
      if (!snapshotName) {
        snapshotName = `${state.fs.currentBranch()}-base-${Date.now()}`;
        state.fs.snapshot(snapshotName);
      }
      state.fs.cloneSnapshot(snapshotName, branchName);
      state.selectedBranchNode = `branch:${branchName}`;
      state.fs.checkoutBranch(branchName);
      pushLog("new-branch", `created branch ${branchName} from ${snapshotName}`);
    });
  });

  document.getElementById("branch-rollback").addEventListener("click", () => {
    const snapshotName = state.selectedBranchNode.startsWith("snapshot:")
      ? state.selectedBranchNode.replace(/^snapshot:/, "")
      : prompt("Rollback to snapshot", state.fs.snapshotNames().at(-1) ?? "");
    if (!snapshotName) {
      return;
    }
    withAction("rollback", () => {
      state.fs.rollback(snapshotName);
      pushLog("rollback", `rolled back to ${snapshotName}`);
    });
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
