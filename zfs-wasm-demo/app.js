import { createSnapshotFs } from "./browser-host.js";

const state = {
  fs: null,
  currentListPath: "/",
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

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
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

async function refreshAll() {
  const stats = state.fs.stats();
  syncStats(stats);
  renderBranches(state.fs.branchNames(), state.fs.currentBranch());
  renderSnapshots(state.fs.snapshotNames());

  try {
    renderDirectory(state.fs.listDir(state.currentListPath), state.currentListPath);
  } catch (error) {
    renderDirectory([], state.currentListPath);
    pushLog("refresh", String(error), false);
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

function attachFormHandlers() {
  document.getElementById("mkdir-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    await withAction("mkdir", () => {
      state.fs.createDir(path);
      pushLog("mkdir", `created ${path}`);
    });
  });

  document.getElementById("write-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    const contents = formValue(event.currentTarget, "contents");
    await withAction("write", () => {
      state.fs.writeText(path, contents);
      pushLog("write", `wrote ${path}\n${contents}`);
    });
  });

  document.getElementById("read-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    await withAction("read", () => {
      const contents = state.fs.readText(path);
      elements["file-view"].textContent = contents || "(empty file)";
      elements["read-target"].textContent = path;
      pushLog("read", `read ${path}`);
    });
  });

  document.getElementById("delete-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const path = formValue(event.currentTarget, "path");
    await withAction("delete", () => {
      state.fs.delete(path);
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
      state.fs.createDir("/projects");
      state.fs.createDir("/projects/demo");
      state.fs.writeText("/projects/demo/readme.txt", "main branch draft");
      state.fs.writeText("/projects/demo/plan.md", "1. snapshot\n2. branch\n3. mutate");
      state.fs.snapshot("seed");
      state.fs.cloneSnapshot("seed", "feature-ui");
      state.fs.checkoutBranch("feature-ui");
      state.fs.writeText("/projects/demo/readme.txt", "feature branch draft");
      state.fs.writeText("/projects/demo/branch-only.txt", "only on feature-ui");
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
  renderLog();
  await refreshAll();
  pushLog("boot", "browser demo ready");
}

boot().catch((error) => {
  elements["file-view"].textContent = `Boot failed:\n${String(error)}`;
  pushLog("boot", String(error), false);
});
