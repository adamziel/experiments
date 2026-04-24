import assert from "node:assert/strict";
import test from "node:test";
import { access } from "node:fs/promises";
import { constants as fsConstants } from "node:fs";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const { createSnapshotFs } = require("../js/node/index.cjs");

test("node host wrapper exposes snapshot and branch semantics", () => {
  const host = createSnapshotFs();

  host.createDir("/workspace");
  host.writeText("/workspace/readme.txt", "main-v1");
  host.snapshot("seed");
  host.cloneSnapshot("seed", "feature");

  host.checkoutBranch("feature");
  host.writeText("/workspace/readme.txt", "feature-v2");
  host.writeText("/workspace/branch-only.txt", "hello");

  assert.equal(host.readText("/workspace/readme.txt"), "feature-v2");
  assert.equal(host.currentBranch(), "feature");
  assert.deepEqual(host.snapshotNames(), ["seed"]);
  assert.deepEqual(host.branchNames(), ["feature", "main"]);
  assert.deepEqual(host.branchInfo(), [
    { name: "feature", parent_branch: "main", source_snapshot: "seed" },
    { name: "main", parent_branch: null, source_snapshot: null },
  ]);
  assert.deepEqual(host.snapshotInfo(), [{ name: "seed", source_branch: "main" }]);

  host.checkoutBranch("main");
  assert.equal(host.readText("/workspace/readme.txt"), "main-v1");
  assert.equal(host.exists("/workspace/branch-only.txt"), false);
});

test("node host wrapper reports structured listings and rollback", () => {
  const host = createSnapshotFs();

  host.createDir("/dir");
  host.writeText("/dir/b.txt", "b");
  host.writeText("/dir/a.txt", "a");
  host.snapshot("before");
  host.delete("/dir/a.txt");
  host.rollback("before");

  assert.deepEqual(host.listDir("/dir"), [
    { name: "a.txt", kind: "File" },
    { name: "b.txt", kind: "File" },
  ]);
});

test("node host wrapper can inspect snapshots without mutating branches", () => {
  const host = createSnapshotFs();

  host.writeText("/workspace/readme.txt", "main");
  host.snapshot("seed");
  host.cloneSnapshot("seed", "feature");
  host.checkoutBranch("feature");
  host.writeText("/workspace/readme.txt", "feature");
  host.writeText("/workspace/branch-only.txt", "hello");

  assert.equal(host.readTextInSnapshot("seed", "/workspace/readme.txt"), "main");
  assert.equal(host.existsInSnapshot("seed", "/workspace/branch-only.txt"), false);
  assert.deepEqual(host.listDirInSnapshot("seed", "/workspace"), [{ name: "readme.txt", kind: "File" }]);
  assert.equal(host.readText("/workspace/readme.txt"), "feature");
});

test("browser package artifacts are generated", async () => {
  await access("pkg/web/zfs_wasm.js", fsConstants.R_OK);
  await access("pkg/web/zfs_wasm_bg.wasm", fsConstants.R_OK);
});
