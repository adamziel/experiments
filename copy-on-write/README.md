# copy-on-write

Three experimental approaches to cloning a WordPress database **lazily**:
read from the remote site on demand, keep writes local. Goal is to launch
a mirror of a 100 GB site in ~0 seconds, without ever dumping the whole DB.

The existing options (`mysqldump`, [reprint](https://github.com/adamziel/reprint))
require downloading the entire database upfront. That's slow and wasteful if
you only ever touch a tiny fraction of the data.

## Three distinct architectures

| # | Approach | Granularity | Remote shape |
|---|---|---|---|
| [1](./solution-1-query-proxy/) | Query-level proxy with row overlay | Row | Live MySQL |
| [2](./solution-2-block-level-cow/) | SQLite page-level COW | 4 KB page | SQLite file (HTTP range) |
| [3](./solution-3-table-materialization/) | Table-level lazy materialization | Table | Live MySQL |

Each solution has its own README with architecture notes, trade-offs, and
honest limitations. They are deliberately different — not three variations
of the same idea.

### When to use which

- **300-query WP page render over a 100 GB DB** → solution 2 wins. Hot rows
  cluster on a small number of B-tree pages; you fetch ~20–100 pages once and
  then everything is local-cache hits.
- **Simple read-mostly cloning without touching the storage layer** → solution 1.
- **A few tables are almost always fully scanned (e.g. `wp_options` autoload)** →
  solution 3, possibly combined with solution 2 for the rest.

## Tests

There are two layers:

- **Unit tests** (436 assertions) — mock the remote; exercise each solution's
  logic directly.
- **Real-WP integration tests** (173 assertions) — spin up MariaDB, install
  WordPress 6.5 into it, seed ~5k posts / 50 users / 20k postmeta / 605
  options, and point each solution at that real DB as the "remote".

Integration assertions prove:

- Cold startup is <100 ms and transfers <1 MB regardless of DB size.
- Local INSERT/UPDATE/DELETE are invisible to the remote
  (verified via `SHOW GLOBAL STATUS LIKE 'Com_insert'` snapshots).
- A simulated ~300-query front-page render produces zero remote writes.
- Reads after local writes return merged results.

### Running locally

```sh
./run-tests.sh
```

Requires PHP 8.2+ with `pdo_mysql`, `pdo_sqlite`, `mysqli` extensions, plus
`mariadbd` and `mariadb-install-db` on `$PATH`.

### Running against an external MariaDB (e.g. CI)

```sh
COW_MARIADB_HOST=127.0.0.1 \
COW_MARIADB_PORT=3306 \
COW_MARIADB_USER=root \
COW_MARIADB_PASSWORD= \
COW_MARIADB_DBNAME=wptest \
./run-tests.sh
```

The harness skips spinning up its own server when `COW_MARIADB_HOST` is set.
This is how the GitHub Actions workflow runs the tests against a MariaDB
service container.

## Layout

```
copy-on-write/
├── run-tests.sh                       # full suite: unit + integration
├── solution-1-query-proxy/            # row-level query proxy
├── solution-2-block-level-cow/        # SQLite page-level COW
├── solution-3-table-materialization/  # table-level lazy materialization
└── tests-integration/
    ├── harness/                       # MariaDB + WP install scripts
    ├── lib/                           # real PDO-backed remote connectors
    ├── solution-1/
    ├── solution-2/
    └── solution-3/
```

## Status

Experimental — not production-ready. The goal is to validate the designs and
their performance claims with real WordPress data, not to ship them.
