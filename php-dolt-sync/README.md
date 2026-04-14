# php-dolt-sync — content-addressed DB sync for WordPress + SQLite

Dolt-style storage for a WordPress site's SQLite database, with HTTP push/pull
between two sites. Single-shot prototype implemented by an adversarial
implementer/verifier loop — see [`SPEC.md`](./SPEC.md) for the original brief.

## Quick start (Docker)

```bash
cd docker && ./setup.sh
```

Brings up two WordPress sites (`localhost:8081`, `localhost:8082`) with the
plugin activated on each. See `docker/README.md` for the full demo.

## Layout

```
plugin/wp-sync/           # the plugin — drop into wp-content/plugins/
  wp-sync.php             # plugin main (headers, bootstrap, WP-CLI registration)
  src/
    Encoding/             # canonical CBOR encoder + hash
    Store/                # Chunk, ChunkStore interface, SQLite + HTTP backends, RefStore
    Tree/                 # TableTree (bottom-up sorted B-tree, fanout=64), TreeDiff
    Snapshot/             # Snapshotter, Materializer, RowNormalizer,
                          # SerializedPhpHandler, DirtyTracker, SchemaInspector
    Sync/                 # SyncEngine (copyReachable, isFastForward),
                          # PushCommand, FetchCommand
    WordPress/            # SyncPlugin (REST routes), WpCliCommands
docker/                   # two-site docker-compose demo
tests/                    # zero-dep test suite (98 checks, all layers)
```

## Run tests

```
php tests/run.php
```

`98 passed / 0 failed`. Covers all 6 layers, in-process HTTP transport,
round-trip push/pull between simulated sites, and the named failure modes from
the spec.

## Manual install on an existing WordPress + SQLite site

1. Install the [sqlite-database-integration](https://wordpress.org/plugins/sqlite-database-integration/) plugin.
2. Copy `plugin/wp-sync/` into `wp-content/plugins/wp-sync/` and activate it.
3. Create an Application Password for a user with `manage_options`.
4. `wp sync commit --message="initial" && wp sync push --remote=https://b.test --user=bob --password=XXX`

## Gaps vs. full definition of done

- **Real PHPUnit wiring**: tests use a bespoke zero-dep harness (`tests/run.php`).
- **End-to-end over real HTTP**: verified via the `InProcessTransport` harness
  which drives the exact same JSON protocol the plugin implements — the docker
  demo is what exercises real HTTP between real sites.
- **Content-defined chunking / Prolly trees**: out of scope per spec; fixed
  fanout=64.
- **Auto-increment ID remapping**: out of scope per spec; the two sites must
  share a base state.
