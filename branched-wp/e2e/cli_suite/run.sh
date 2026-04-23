#!/usr/bin/env bash
# Runs the ForkPress CLI contract test suite against the compiled
# `forkpress` binary.
#
# Usage:
#   bash e2e/cli_suite/run.sh                # run everything
#   bash e2e/cli_suite/run.sh -k TestBranch  # filter by test id
#   FORKPRESS_BIN=/path/to/forkpress bash e2e/cli_suite/run.sh
#
# Requires:
#   - A `forkpress` binary (at FORKPRESS_BIN, ./target/release/forkpress,
#     ./forkpress/target/release/forkpress, or on PATH). Build with:
#       scripts/build-dist.sh && cargo build --release -p forkpress
#   - pytest and requests on PATH (or PYTHONPATH).
#
# Exit codes: pytest's own (0 = all passed; 1 = failures; 5 = nothing collected).

set -euo pipefail

E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SUITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(cd "$E2E_DIR/.." && pwd)"

PYTEST="${PYTEST:-$(command -v pytest || true)}"
if [[ -z "$PYTEST" ]]; then
    if command -v python3 >/dev/null 2>&1; then
        PYTEST="python3 -m pytest"
    else
        echo "ERROR: pytest (or python3 + pytest) required on PATH" >&2
        exit 2
    fi
fi

cd "$BASE_DIR"

DEFAULT_FLAGS=(-v --tb=short -ra --color=yes)
exec $PYTEST "${DEFAULT_FLAGS[@]}" "$@" "$SUITE_DIR"
