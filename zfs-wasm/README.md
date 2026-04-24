# zfs-wasm

This directory contains the full Rust -> Wasm build pipeline and the GitHub Pages demo for a small snapshotting filesystem with ZFS-flavored semantics.

Live demo:

`https://adamziel.github.io/experiments/zfs-wasm/`

## Build and test

```bash
npm run build:js
npm test
```

That builds:

- `pkg/node/*` for the Node host wrapper
- `pkg/web/*` for browser consumption

## Refresh the hosted demo

```bash
npm run build:demo
```

That rebuilds the Wasm bundle locally, then refreshes the Pages-hosted files at the root of this directory. GitHub Pages runs the same script on deploy, so the live site is built from the checked-in Rust/Wasm sources in this directory:

- `index.html`, `app.js`, `styles.css`
- `browser-host.js`
- `pkg/zfs_wasm*`

## Layout

- `src/`: Rust snapshot filesystem implementation compiled to Wasm
- `tests/`: Rust and JS tests
- `scripts/build-js-packages.sh`: `wasm-bindgen` packaging for Node and browser targets
- `demo/`: source browser demo used for local development
- `index.html`, `app.js`, `styles.css`, `browser-host.js`, `pkg/`: static Pages-hosted build output
