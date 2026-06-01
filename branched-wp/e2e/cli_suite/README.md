# ForkPress CLI contract test suite

A focused pytest suite that exercises the `forkpress` binary — every
verb, with expected outputs — so you can swap the versioning backend
underneath and regain confidence via one test run.

**Targets the compiled binary, not the PHP scripts.** Every test
spawns the real `forkpress` executable. If the binary isn't present,
the suite fails fast rather than running anything fake.

**Does not introspect internals.** No direct SQLite reads, no
stream-wrapper file writes. Tests only observe stdout, stderr, exit
codes, and HTTP responses — exactly what a user sees.

## What's covered

| File | Contract |
| --- | --- |
| `test_cli.py` | Every `forkpress` / `branchctl` subcommand, with argument validation. |
| `test_http.py` | The served site: homepage, `/wp-json/`, `/wp-login.php`, branch subdomain routing, branch isolation, merge propagation through HTTP. |

Command coverage in `test_cli.py`:

- `forkpress init` (create, refuse overwrite, admin-password flag)
- `forkpress branch list / create / show / delete` (reserved names,
  duplicate rejection, invalid-name rejection)
- `forkpress branch commit / log` (empty-commit handling, initial
  snapshot visible)
- `forkpress branch status / diff`
- `forkpress branch rollback / reset` (refuse-when-nothing-to-do,
  refuse unknown commit hashes, succeed on a known hash)
- `forkpress branch merge` (flag validation, identical-branches no-op)
- `forkpress branch gc` (dry-run + real)
- `forkpress branch audit` (trail after create + delete)
- `forkpress user add / list / remove / verify / auth-enabled`
- `forkpress backup` (writes a copy, refuses overwrite)
- `forkpress export` + `forkpress import` (round-trip preserving every
  branch)

Flow coverage in `test_http.py`:

- Main homepage returns WordPress HTML
- `/wp-json/` returns JSON with site metadata
- `/wp-login.php` serves a login form
- Unknown branch subdomain still returns a well-formed HTTP response
- Created branch's subdomain routes to that branch
- Title change on a branch is not visible on `main`
- `forkpress branch merge <br> --into main` propagates the branch's
  change to `main`

## Binary discovery

First match wins:

1. `$FORKPRESS_BIN`
2. `./target/release/forkpress`
3. `./forkpress/target/release/forkpress`
4. `forkpress` on `$PATH`

Build it with:

```bash
scripts/build-dist.sh && cargo build --release -p forkpress
```

## Running

```bash
# Full suite:
bash e2e/cli_suite/run.sh

# Point at a specific binary (e.g. an alternative backend's build):
FORKPRESS_BIN=/path/to/my-forkpress bash e2e/cli_suite/run.sh

# Filter by test class or method:
bash e2e/cli_suite/run.sh -k TestMerge

# Stop at first failure:
bash e2e/cli_suite/run.sh -x
```

`requests` is required for the HTTP tests (`pip install requests`).

## Plugging in a different versioning backend

The suite treats the binary as a black box. To validate a new backend:

1. Produce a binary whose CLI surface matches `forkpress` — same verbs,
   same exit codes, output containing the same markers the tests check
   for.
2. Run the suite against it: `FORKPRESS_BIN=./my-backend bash run.sh`.
3. Every test that passes is a contract your backend honours.
   Every test that fails tells you where the contract broke.

Tests are intentionally lenient about message wording (e.g. merge
output is accepted as "merge complete", "applied: 0 rows", or "up to
date") but strict about exit codes, file creation, and HTTP status
codes — the load-bearing contract.
