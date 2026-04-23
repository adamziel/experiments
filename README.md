# Experiments

A collection of self-contained experimental projects.

| Experiment | Description |
|---|---|
| [zfs-wasm-demo](./zfs-wasm-demo) | A browser-hosted WebAssembly snapshot filesystem demo with copy-on-write branching, rollback, and a live interactive UI. |
| [mysql-binary-log](./mysql-binary-log) | A PHP program that connects to MySQL as a replication replica, receives the raw binary log event stream, and outputs one JSON line per event to stdout. |
| [php-dolt-sync](./php-dolt-sync) | Dolt-style content-addressed database sync for WordPress + SQLite, with HTTP push/pull between two sites. |
| [php-wasmtime-ext](./php-wasmtime-ext) | A native PHP extension that integrates the Wasmtime WebAssembly runtime, providing first-class PHP objects for compilation, instantiation, function calls, memory access, and WASI support. |
| [sqlite-markdown](./sqlite-markdown) | A loadable SQLite extension that stores `wp_posts`-style content as Markdown files with structured front matter, exposed through writable `wp_posts` and `wp_postmeta`-like virtual tables. |
