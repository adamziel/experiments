# zfs-wasm-demo

An interactive browser demo of a ZFS-flavored snapshotting filesystem compiled to WebAssembly.

The UI supports:

- creating directories
- writing, reading, and deleting files
- creating immutable named snapshots
- cloning snapshots into branches
- checking out branches and rolling back to snapshots
- inspecting directory listings, stats, branch state, and an operation log

Once GitHub Pages is enabled for the repository workflow, the published site is expected at:

`https://adamziel.github.io/experiments/`

## Layout

- `index.html`, `app.js`, `styles.css`: the static browser UI
- `browser-host.js`: thin browser host wrapper over the generated Wasm bindings
- `pkg/`: generated `wasm-bindgen` web bundle and `.wasm` binary

## Refreshing the Wasm bundle

The assets under `pkg/` were generated from the standalone `zfs-wasm` project with:

```bash
npm run build:js
```

Then copied into this experiment directory for static hosting.
