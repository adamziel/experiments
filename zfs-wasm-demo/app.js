import { createSnapshotFs } from "./browser-host.js";

const state = {
  fs: null,
  currentListPath: "/",
  selectedPath: "/",
  expandedDirs: new Set(["/"]),
  visibleTreeNodes: [],
  pendingFocusPath: "/",
  logEntries: [],
};

const elements = {};

function bindElement(id) {
  elements[id] = document.getElementById(id);
}

[
  "metric-branch",
  "metric-snapshots",
  "metric-branches",
  "metric-bytes",
  "directory-view",
  "file-view",
  "read-target",
  "branch-list",
  "snapshot-list",
  "stats-view",
  "log-view",
  "tree-view",
  "tree-selection",
].forEach(bindElement);

function formatJson(value) {
  return JSON.stringify(value, null, 2);
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

function ensureTreeSelectionIsValid() {
  if (state.selectedPath !== "/" && !state.fs.exists(state.selectedPath)) {
    state.selectedPath = parentPath(state.selectedPath);
  }
  if (!isDirectory(state.currentListPath)) {
    state.currentListPath = isDirectory(state.selectedPath) ? state.selectedPath : "/";
  }
}

function pushLog(action, message, ok = true) {
  state.logEntries.unshift({
    action,
    message,
    ok,
    time: nowLabel(),
  });
  state.logEntries = state.logEntries.slice(0, 40);
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
            <strong>${entry.action}</strong>
            <span class="${entry.ok ? "log-status-ok" : "log-status-error"}">${entry.ok ? "ok" : "error"} · ${entry.time}</span>
          </div>
          <div class="log-message">${escapeHtml(entry.message)}</div>
        </article>
      `,
    )
    .join("");
}

function renderDirectory(entries, path) {
  if (entries.length === 0) {
    elements["directory-view"].innerHTML = `<div class="empty-state">${escapeHtml(path)} is empty.</div>`;
    return;
  }

  elements["directory-view"].innerHTML = entries
    .map(
      (entry) => `
        <div class="entry">
          <div class="entry-name">${escapeHtml(entry.name)}</div>
          <div class="entry-kind">${escapeHtml(entry.kind)}</div>
        </div>
      `,
    )
    .join("");
}

function renderBranches(branches, currentBranch) {
  elements["branch-list"].innerHTML = branches
    .map(
      (branch) => `<li class="${branch === currentBranch ? "current" : ""}">${escapeHtml(branch)}</li>`,
    )
    .join("");
}

function renderSnapshots(snapshots) {
  elements["snapshot-list"].innerHTML = snapshots.length
    ? snapshots.map((snapshot) => `<li>${escapeHtml(snapshot)}</li>`).join("")
    : '<li class="empty-pill">none</li>';
}

function syncStats(stats) {
  elements["metric-branch"].textContent = stats.current_branch;
  elements["metric-snapshots"].textContent = String(stats.snapshot_count);
  elements["metric-branches"].textContent = String(stats.branch_count);
  elements["metric-bytes"].textContent = String(stats.current_logical_bytes);
  elements["stats-view"].textContent = formatJson(stats);
}

function buildVisibleTreeNodes(path = "/", depth = 0, nodes = []) {
  const children = listDirSafe(path) ?? [];
  const node = {
    path,
    depth,
    kind: "Directory",
    name: basename(path),
    expanded: state.expandedDirs.has(path),
    childrenCount: children.length,
  };
  nodes.push(node);

  if (!node.expanded) {
    return nodes;
  }

  for (const entry of children) {
    const childPath = joinPath(path, entry.name);
    if (entry.kind === "Directory") {
      buildVisibleTreeNodes(childPath, depth + 1, nodes);
    } else {
      nodes.push({
        path: childPath,
        depth: depth + 1,
        kind: "File",
        name: entry.name,
        expanded: false,
        childrenCount: 0,
      });
    }
  }

  return nodes;
}

function renderTree() {
  state.visibleTreeNodes = buildVisibleTreeNodes();
  elements["tree-selection"].textContent = `Selected: ${state.selectedPath}`;

  elements["tree-view"].innerHTML = state.visibleTreeNodes
    .map((node, index) => {
      const selected = node.path === state.selectedPath;
      const expandable = node.kind === "Directory";
      const chevron = expandable ? (node.expanded ? "▾" : "▸") : "";
      const icon = node.kind === "Directory" ? "📁" : "📄";
      const kindLabel = node.kind === "Directory" ? "dir" : "file";
      const ariaExpanded = expandable ? `aria-expanded="${node.expanded}"` : "";
      return `
        <button
          type="button"
          class="tree-row ${selected ? "selected" : ""}"
          role="treeitem"
          aria-level="${node.depth + 1}"
          ${ariaExpanded}
          aria-selected="${selected}"
          tabindex="${selected ? "0" : "-1"}"
          data-index="${index}"
          data-path="${escapeHtml(node.path)}"
          data-kind="${node.kind}"
        >
          <span class="tree-row-inner">
            <span class="tree-row-indent" style="--depth:${node.depth}"></span>
            <span class="tree-chevron">${chevron}</span>
            <span class="tree-icon" aria-hidden="true">${icon}</span>
            <span class="tree-label">${escapeHtml(node.name)}</span>
            <span class="tree-meta">${kindLabel}</span>
          </span>
        </button>
      `;
    })
    .join("");

  focusPendingTreeNode();
}

function focusPendingTreeNode() {
  const path = state.pendingFocusPath ?? state.selectedPath;
  const row = elements["tree-view"].querySelector(`[data-path="${CSS.escape(path)}"]`);
  if (row) {
    row.focus();
  }
  state.pendingFocusPath = null;
}

function setSelection(path) {
  state.selectedPath = path;
  state.pendingFocusPath = path;

  if (isDirectory(path)) {
    state.currentListPath = path;
    renderDirectory(state.fs.listDir(path), path);
    elements["read-target"].textContent = path;
  } else {
    const contents = state.fs.readText(path);
    elements["file-view"].textContent = contents || "(empty file)";
    elements["read-target"].textContent = path;
    state.currentListPath = parentPath(path);
    renderDirectory(state.fs.listDir(state.currentListPath), state.currentListPath);
  }
}

function toggleDirectory(path, expand = null) {
  if (!isDirectory(path)) {
    return;
  }

  const nextExpanded = expand ?? !state.expandedDirs.has(path);
  if (nextExpanded) {
    state.expandedDirs.add(path);
  } else if (path !== "/") {
    state.expandedDirs.delete(path);
  }
  state.pendingFocusPath = path;
}

function treeNodeByIndex(index) {
  return state.visibleTreeNodes[index] ?? null;
}

function focusTreeIndex(index) {
  const node = treeNodeByIndex(index);
  if (!node) {
    return;
  }
  setSelection(node.path);
  renderTree();
}

function handleTreeKeyboard(event) {
  const row = event.target.closest(".tree-row");
  if (!row) {
    return;
  }

  const index = Number(row.dataset.index);
  const node = treeNodeByIndex(index);
  if (!node) {
    return;
  }

  switch (event.key) {
    case "ArrowDown":
      event.preventDefault();
      focusTreeIndex(Math.min(index + 1, state.visibleTreeNodes.length - 1));
      break;
    case "ArrowUp":
      event.preventDefault();
      focusTreeIndex(Math.max(index - 1, 0));
      break;
    case "ArrowRight":
      event.preventDefault();
      if (node.kind === "Directory" && !node.expanded) {
        toggleDirectory(node.path, true);
        refreshAll();
      } else if (node.kind === "Directory") {
        const nextNode = treeNodeByIndex(index + 1);
        if (nextNode && parentPath(nextNode.path) === node.path) {
          focusTreeIndex(index + 1);
        }
      }
      break;
    case "ArrowLeft":
      event.preventDefault();
      if (node.kind === "Directory" && node.expanded && node.path !== "/") {
        toggleDirectory(node.path, false);
        refreshAll();
      } else if (node.path !== "/") {
        focusTreeIndex(state.visibleTreeNodes.findIndex((item) => item.path === parentPath(node.path)));
      }
      break;
    case "Home":
      event.preventDefault();
      focusTreeIndex(0);
      break;
    case "End":
      event.preventDefault();
      focusTreeIndex(state.visibleTreeNodes.length - 1);
      break;
    case "Enter":
    case " ":
      event.preventDefault();
      setSelection(node.path);
      if (node.kind === "Directory" && event.key === " ") {
        toggleDirectory(node.path);
      }
      refreshAll();
      break;
  }
}

function selectedDirectoryForCreate() {
  if (isDirectory(state.selectedPath)) {
    return state.selectedPath;
  }
  return parentPath(state.selectedPath);
}

async function refreshAll() {
  ensureTreeSelectionIsValid();
  const stats = state.fs.stats();
  syncStats(stats);
  renderBranches(state.fs.branchNames(), state.fs.currentBranch());
  renderSnapshots(state.fs.snapshotNames());
  renderTree();

  try {
    renderDirectory(state.fs.listDir(state.currentListPath), state.currentListPath);
  } catch {
    state.currentListPath = "/";
    renderDirectory(state.fs.listDir("/"), "/");
  }
}

async function withAction(action, fn) {
  try {
    const result = await fn();
    await refreshAll();
    return result;
  } catch (error) {
    pushLog(action, String(error), false);
    throw error;
  }
}

function formValue(form, name) {
  return new FormData(form).get(name)?.toString().trim() ?? "";
}

function attachTreeHandlers() {
  elements["tree-view"].addEventListener("click", async (event) => {
    const row = event.target.closest(".tree-row");
    if (!row) {
      return;
    }
    const path = row.dataset.path;
    const kind = row.dataset.kind;
    setSelection(path);
    if (kind === "Directory") {
      toggleDirectory(path);
    }
    await refreshAll();
  });

  elements["tree-view"].addEventListener("keydown", handleTreeKeyboard);

  document.getElementById("tree-new-file").addEventListener("click", async () => {
    const base = selectedDirectoryForCreate();
    const candidate = prompt("New file path", joinPath(base, "untitled.txt"));
    if (!candidate) {
      return;
    }
    await withAction("new-file", () => {
      state.fs.writeText(candidate, "");
      setSelection(candidate);
      state.expandedDirs.add(parentPath(candidate));
      pushLog("new-file", `created ${candidate}`);
    });
  });

  document.getElementById("tree-new-dir").addEventListener("click", async () => {
    const base = selectedDirectoryForCreate();
    const candidate = prompt("New folder path", joinPath(base, "new-folder"));
    if (!candidate) {
      return;
    }
    await withAction("new-dir", () => {
      state.fs.createDir(candidate);
      setSelection(candidate);
      state.expandedDirs.add(parentPath(candidate));
      pushLog("new-dir", `created ${candidate}`);
    });
  });

  document.getElementById("tree-refresh").addEventListener("click", async () => {
    await refreshAll();
    pushLog("tree-refresh", "refreshed tree view");
  });
}

function attachFormHandlers() {
  document.getElementById("mkdir-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    await withAction("mkdir", () => {
      state.fs.createDir(path);
      setSelection(path);
      state.expandedDirs.add(parentPath(path));
      pushLog("mkdir", `created ${path}`);
    });
  });

  document.getElementById("write-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    const contents = formValue(event.currentTarget, "contents");
    await withAction("write", () => {
      state.fs.writeText(path, contents);
      setSelection(path);
      state.expandedDirs.add(parentPath(path));
      pushLog("write", `wrote ${path}\n${contents}`);
    });
  });

  document.getElementById("read-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    await withAction("read", () => {
      setSelection(path);
      pushLog("read", `read ${path}`);
    });
  });

  document.getElementById("delete-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    await withAction("delete", () => {
      state.fs.delete(path);
      state.selectedPath = parentPath(path);
      state.pendingFocusPath = state.selectedPath;
      pushLog("delete", `deleted ${path}`);
    });
  });

  document.getElementById("snapshot-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const name = formValue(event.currentTarget, "name");
    await withAction("snapshot", () => {
      state.fs.snapshot(name);
      pushLog("snapshot", `captured ${name}`);
    });
  });

  document.getElementById("rollback-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const name = formValue(event.currentTarget, "name");
    await withAction("rollback", () => {
      state.fs.rollback(name);
      pushLog("rollback", `rolled back to ${name}`);
    });
  });

  document.getElementById("clone-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const snapshot = formValue(event.currentTarget, "snapshot");
    const branch = formValue(event.currentTarget, "branch");
    await withAction("clone", () => {
      state.fs.cloneSnapshot(snapshot, branch);
      pushLog("clone", `created branch ${branch} from ${snapshot}`);
    });
  });

  document.getElementById("checkout-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const branch = formValue(event.currentTarget, "branch");
    await withAction("checkout", () => {
      state.fs.checkoutBranch(branch);
      pushLog("checkout", `switched to ${branch}`);
    });
  });

  document.getElementById("list-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path") || "/";
    state.currentListPath = path;
    state.selectedPath = path;
    state.pendingFocusPath = path;
    await withAction("list", () => {
      const entries = state.fs.listDir(path);
      renderDirectory(entries, path);
      pushLog("list", `listed ${path}`);
    });
  });
}

function attachButtons() {
  document.getElementById("seed-demo").addEventListener("click", async () => {
    await withAction("scenario", () => {
      state.fs.writeText("/projects/demo/readme.txt", "main branch draft");
      state.fs.writeText("/projects/demo/plan.md", "1. snapshot\n2. branch\n3. mutate");
      state.fs.snapshot("seed");
      state.fs.cloneSnapshot("seed", "feature-ui");
      state.fs.checkoutBranch("feature-ui");
      state.fs.writeText("/projects/demo/readme.txt", "feature branch draft");
      state.fs.writeText("/projects/demo/branch-only.txt", "only on feature-ui");
      state.expandedDirs.add("/projects");
      state.expandedDirs.add("/projects/demo");
      setSelection("/projects/demo");
      pushLog("scenario", "loaded demo scenario with snapshot seed and branch feature-ui");
    });
  });

  document.getElementById("refresh-all").addEventListener("click", async () => {
    await refreshAll();
    pushLog("refresh", `refreshed views for ${state.currentListPath}`);
  });

  document.getElementById("reset-fs").addEventListener("click", async () => {
    state.fs = await createSnapshotFs();
    state.currentListPath = "/";
    state.selectedPath = "/";
    state.expandedDirs = new Set(["/"]);
    state.pendingFocusPath = "/";
    elements["file-view"].textContent = "Read a file to inspect its contents.";
    elements["read-target"].textContent = "No file selected";
    await refreshAll();
    pushLog("reset", "replaced the in-memory filesystem with a fresh instance");
  });

  document.getElementById("refresh-branches").addEventListener("click", refreshAll);
  document.getElementById("refresh-snapshots").addEventListener("click", refreshAll);
  document.getElementById("refresh-stats").addEventListener("click", refreshAll);
  document.getElementById("clear-log").addEventListener("click", () => {
    state.logEntries = [];
    renderLog();
  });
}

async function boot() {
  state.fs = await createSnapshotFs();
  attachFormHandlers();
  attachButtons();
  attachTreeHandlers();
  renderLog();
  await refreshAll();
  pushLog("boot", "browser demo ready");
}

boot().catch((error) => {
  elements["file-view"].textContent = `Boot failed:\n${String(error)}`;
  pushLog("boot", String(error), false);
});
