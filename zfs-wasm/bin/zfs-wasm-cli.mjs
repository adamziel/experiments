#!/usr/bin/env node

import fs from "node:fs/promises";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const { createSnapshotFs } = require("../js/node/index.cjs");

function usage() {
  console.error("usage: node bin/zfs-wasm-cli.mjs <program.json>");
  process.exit(64);
}

function printResult(value) {
  if (value === undefined) {
    return;
  }
  console.log(JSON.stringify(value, null, 2));
}

function executeOperation(host, operation) {
  switch (operation.op) {
    case "mkdir":
      host.createDir(operation.path);
      return null;
    case "writeFile":
      host.writeFile(operation.path, operation.bytes);
      return null;
    case "writeText":
      host.writeText(operation.path, operation.text);
      return null;
    case "readFile":
      return Array.from(host.readFile(operation.path));
    case "readText":
      return host.readText(operation.path);
    case "delete":
      host.delete(operation.path);
      return null;
    case "exists":
      return host.exists(operation.path);
    case "list":
      return host.listDir(operation.path);
    case "snapshot":
      host.snapshot(operation.name);
      return null;
    case "rollback":
      host.rollback(operation.name);
      return null;
    case "clone":
      host.cloneSnapshot(operation.snapshot, operation.branch);
      return null;
    case "checkout":
      host.checkoutBranch(operation.branch);
      return null;
    case "branchNames":
      return host.branchNames();
    case "snapshotNames":
      return host.snapshotNames();
    case "stats":
      return host.stats();
    default:
      throw new Error(`unsupported op: ${operation.op}`);
  }
}

const [, , programPath] = process.argv;

if (!programPath) {
  usage();
}

const program = JSON.parse(await fs.readFile(programPath, "utf8"));
if (!Array.isArray(program)) {
  throw new Error("program must be a JSON array of operations");
}

const host = createSnapshotFs();
const results = [];

for (const operation of program) {
  results.push({
    op: operation.op,
    result: executeOperation(host, operation),
  });
}

printResult(results);
