# zfs-wasm

An interactive browser demo of a ZFS-flavored snapshotting filesystem compiled to WebAssembly.

The UI supports:

- creating directories
- writing, reading, and deleting files
- creating immutable named snapshots
- cloning snapshots into branches
- checking out branches and rolling back to snapshots
- inspecting directory listings, stats, branch state, and an operation log

The published site is available at:

`https://adamziel.github.io/experiments/zfs-wasm/`

## Layout

- `index.html`, `app.js`, `styles.css`: the static browser UI
- `browser-host.js`: thin browser host wrapper over the generated Wasm bindings
- `pkg/`: generated `wasm-bindgen` web bundle and `.wasm` binary
- `build-demo.sh`: refreshes the hosted assets from a local checkout of the standalone `zfs-wasm` project

## Refreshing the Wasm bundle

Use the repo-local build helper:

```bash
./build-demo.sh /path/to/zfs-wasm
```

It will rebuild the standalone project, copy the browser assets into this directory, and rewrite import paths for static hosting under GitHub Pages.
