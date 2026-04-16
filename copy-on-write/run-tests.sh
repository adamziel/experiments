#!/usr/bin/env bash
# Run all unit + integration test suites for the COW experiments.
#
# Flow:
#   1. Start a dedicated MariaDB (reuses datadir if already inited).
#   2. Install+seed WordPress against it (idempotent).
#   3. Export the WP DB to SQLite (for solution 2).
#   4. Run each solution's unit tests.
#   5. Run each solution's integration tests in a fresh PHP process
#      (required because solutions 1 & 3 both declare CowClone\CowDatabase).
#   6. Stop MariaDB.
#
# Exits non-zero on any failure. Prints total assertion counts at the end.

set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
HARNESS_DIR="$SCRIPT_DIR/tests-integration/harness"
FAILURES=0
TOTAL_UNIT_PASSED=0
TOTAL_UNIT_FAILED=0
TOTAL_INT_PASSED=0
TOTAL_INT_FAILED=0

# Make sure MariaDB is shut down even if the script dies mid-run.
cleanup() {
    "$HARNESS_DIR/stop-mariadb.sh" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "=============================================="
echo "  Copy-on-Write WordPress DB Cloning"
echo "  Full test suite (unit + integration)"
echo "=============================================="
echo ""

# --- 1. MariaDB up ---
echo ">>> Starting MariaDB..."
if ! "$HARNESS_DIR/start-mariadb.sh"; then
    echo "FAILED to start MariaDB"
    exit 1
fi
echo ""

# --- 2. WordPress install + seed ---
echo ">>> Installing/seeding WordPress..."
if ! php "$HARNESS_DIR/install-wordpress.php"; then
    echo "FAILED to install WordPress"
    exit 1
fi
echo ""

# --- 3. SQLite export for solution 2 ---
echo ">>> Exporting WP DB to SQLite for solution 2..."
if ! php "$HARNESS_DIR/export-to-sqlite.php"; then
    echo "FAILED to export to SQLite"
    exit 1
fi
echo ""

# Helper: runs a test runner script, parses SUMMARY line, updates counters.
run_tests() {
    local label="$1"
    local cmd="$2"
    local kind="$3"   # unit | integration

    echo "--- ${label} ---"
    local out
    out="$(eval "$cmd" 2>&1)"
    local rc=$?
    echo "$out"

    # Extract passed/failed counts. Each runner we care about emits some
    # line containing "Total: N passed, M failed" or "INTEGRATION_SUMMARY
    # passed=N failed=M" or "Passed: N" / "Failed: M" (solution 1 unit
    # runner format).
    local passed=0
    local failed=0
    if [[ "$kind" == "integration" ]]; then
        passed=$(echo "$out" | grep -oE 'INTEGRATION_SUMMARY passed=[0-9]+' | grep -oE '[0-9]+' | tail -1)
        failed=$(echo "$out" | grep -oE 'INTEGRATION_SUMMARY passed=[0-9]+ failed=[0-9]+' | grep -oE 'failed=[0-9]+' | grep -oE '[0-9]+' | tail -1)
    else
        # Unit runners: try both formats.
        if echo "$out" | grep -qE '^Total:.*passed'; then
            passed=$(echo "$out" | grep -oE 'Total: [0-9]+ passed' | grep -oE '[0-9]+')
            failed=$(echo "$out" | grep -oE '[0-9]+ failed' | grep -oE '[0-9]+' | tail -1)
        elif echo "$out" | grep -qE '^Passed: [0-9]+'; then
            passed=$(echo "$out" | grep -oE '^Passed: [0-9]+' | grep -oE '[0-9]+')
            failed=$(echo "$out" | grep -oE '^Failed: [0-9]+' | grep -oE '[0-9]+')
        fi
    fi
    passed=${passed:-0}
    failed=${failed:-0}

    if [[ "$kind" == "integration" ]]; then
        TOTAL_INT_PASSED=$((TOTAL_INT_PASSED + passed))
        TOTAL_INT_FAILED=$((TOTAL_INT_FAILED + failed))
    else
        TOTAL_UNIT_PASSED=$((TOTAL_UNIT_PASSED + passed))
        TOTAL_UNIT_FAILED=$((TOTAL_UNIT_FAILED + failed))
    fi

    if [ $rc -ne 0 ] || [ "$failed" -ne 0 ]; then
        echo ">>> ${label}: FAILED"
        FAILURES=$((FAILURES + 1))
    else
        echo ">>> ${label}: PASSED (${passed} assertions)"
    fi
    echo ""
}

# --- 4. Unit tests ---
run_tests "Solution 1 unit tests" "php $SCRIPT_DIR/solution-1-query-proxy/tests/TestRunner.php" "unit"
run_tests "Solution 2 unit tests" "php $SCRIPT_DIR/solution-2-block-level-cow/tests/TestRunner.php" "unit"
run_tests "Solution 3 unit tests" "php $SCRIPT_DIR/solution-3-table-materialization/tests/TestRunner.php" "unit"

# --- 5. Integration tests ---
run_tests "Solution 1 integration tests" "php $SCRIPT_DIR/tests-integration/solution-1/run.php" "integration"
run_tests "Solution 2 integration tests" "php $SCRIPT_DIR/tests-integration/solution-2/run.php" "integration"
run_tests "Solution 3 integration tests" "php $SCRIPT_DIR/tests-integration/solution-3/run.php" "integration"

# --- 6. MariaDB down (also handled by EXIT trap) ---
"$HARNESS_DIR/stop-mariadb.sh" >/dev/null 2>&1 || true

TOTAL_PASSED=$((TOTAL_UNIT_PASSED + TOTAL_INT_PASSED))
TOTAL_FAILED=$((TOTAL_UNIT_FAILED + TOTAL_INT_FAILED))

echo "=============================================="
echo "  UNIT:        ${TOTAL_UNIT_PASSED} passed  /  ${TOTAL_UNIT_FAILED} failed"
echo "  INTEGRATION: ${TOTAL_INT_PASSED} passed  /  ${TOTAL_INT_FAILED} failed"
echo "  TOTAL:       ${TOTAL_PASSED} assertions, ${TOTAL_FAILED} failed"
if [ $FAILURES -eq 0 ]; then
    echo "  RESULT: ALL SUITES PASSED"
else
    echo "  RESULT: ${FAILURES} SUITE(S) FAILED"
fi
echo "=============================================="

exit $FAILURES
