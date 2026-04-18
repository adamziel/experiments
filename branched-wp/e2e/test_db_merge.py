"""
Test suite for 3-way DB merge in branched-wp.

Covers all corner cases specified in the DB merge PRD:
  - Clean merge cases (no conflicts)
  - Conflict detection and resolution strategies
  - Integrity preservation after merge

Run:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_db_merge.py -v

Existing invariant tests must still pass:
    PYTHONPATH=/tmp/pylibs python3 -m pytest e2e/test_invariants.py -v -m "not live"
"""

import os
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

import pytest

# ── Paths ──────────────────────────────────────────────────────────────────────
E2E_DIR  = Path(__file__).parent
BASE_DIR = E2E_DIR.parent
EXT_PATH = BASE_DIR / "ext" / "branchfs.so"
PHP_BIN  = "php"


# ── Low-level helpers ──────────────────────────────────────────────────────────

def branchctl(site_fp: Path, *args, timeout: int = 30) -> subprocess.CompletedProcess:
    return subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "branchctl.php"), *args],
        capture_output=True, text=True, timeout=timeout,
        env={**os.environ, "BRANCHFS_DB": str(site_fp)},
    )


def sqlite_q(site_fp: Path, sql: str, params=()) -> list:
    db = sqlite3.connect(str(site_fp))
    try:
        return db.execute(sql, params).fetchall()
    finally:
        db.close()


def sqlite_exec(site_fp: Path, sql: str, params=()):
    db = sqlite3.connect(str(site_fp))
    try:
        db.execute(sql, params)
        db.commit()
    finally:
        db.close()


def init_site(site_fp: Path):
    r = subprocess.run(
        [PHP_BIN, "-d", f"extension={EXT_PATH}",
         str(BASE_DIR / "scripts" / "init_db.php"), str(site_fp)],
        capture_output=True, text=True, timeout=30,
    )
    if r.returncode != 0:
        raise RuntimeError(f"init_db.php failed: {r.stderr[:500]}")


def setup_main_tables(site_fp: Path):
    """Create realistic WordPress-like tables on main (b1_wp_*)."""
    db = sqlite3.connect(str(site_fp))
    db.executescript("""
        CREATE TABLE IF NOT EXISTS b1_wp_options (
            option_id    INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name  TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT '',
            autoload     TEXT NOT NULL DEFAULT 'yes'
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_b1_wp_options_name
            ON b1_wp_options(option_name);
        INSERT OR IGNORE INTO b1_wp_options (option_name, option_value) VALUES
            ('blogname',      'My Site'),
            ('siteurl',       'http://localhost'),
            ('shared_option', 'original_value');
        CREATE TABLE IF NOT EXISTS b1_wp_posts (
            ID         INTEGER PRIMARY KEY AUTOINCREMENT,
            post_title TEXT NOT NULL DEFAULT '',
            post_type  TEXT NOT NULL DEFAULT 'post'
        );
        INSERT OR IGNORE INTO b1_wp_posts (post_title, post_type) VALUES
            ('Hello World', 'post');
    """)
    db.commit()
    db.close()


# ── Fixtures ──────────────────────────────────────────────────────────────────

@pytest.fixture
def fresh_db():
    """
    Fresh site.fp with main branch + WordPress-like b1_wp_* tables.
    Function-scoped for complete test isolation.
    """
    if not EXT_PATH.exists():
        pytest.skip(f"branchfs.so not found — run 'make' in branched-wp/")

    work = Path(tempfile.mkdtemp())
    site_fp = work / "site.fp"

    try:
        init_site(site_fp)
    except RuntimeError as e:
        pytest.skip(str(e))

    setup_main_tables(site_fp)

    yield site_fp
    shutil.rmtree(work, ignore_errors=True)


@pytest.fixture
def branched_db(fresh_db):
    """fresh_db + a 'feature' branch created via branchctl."""
    site_fp = fresh_db

    r = branchctl(site_fp, "create", "feature")
    assert r.returncode == 0, f"branchctl create failed:\n{r.stdout}\n{r.stderr}"

    rows = sqlite_q(site_fp, "SELECT id FROM branches WHERE name='feature'")
    assert rows, "feature branch not found after create"
    feature_id = rows[0][0]

    return {"site_fp": site_fp, "feature_id": feature_id}


# ── TestDBMergeCleanCases ─────────────────────────────────────────────────────

class TestDBMergeCleanCases:

    def test_branch_new_option_merged_to_main(self, branched_db):
        """Branch adds option, main unchanged → option appears on main after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("new_feature_opt", "feature_val"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("new_feature_opt",))
        assert rows and rows[0][0] == "feature_val"

    def test_branch_new_post_merged_to_main(self, branched_db):
        """Branch adds post, main unchanged → post appears on main after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_posts (post_title, post_type) VALUES (?, ?)",
            ("New Branch Post", "post"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT post_title FROM b1_wp_posts WHERE post_title=?",
            ("New Branch Post",))
        assert rows, "post from branch should appear on main after merge"

    def test_main_new_option_kept_after_merge(self, branched_db):
        """Main adds option after fork → not lost when feature merges in.

        Feature only modifies an existing row (no new AUTOINCREMENT rows), so
        there is no PK collision between independently-added rows.  The focus
        is verifying that main's independently-added row survives the merge.
        """
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Main adds a new option (gets next AUTOINCREMENT id)
        sqlite_exec(site_fp,
            "INSERT INTO b1_wp_options (option_name, option_value) VALUES (?, ?)",
            ("main_only_opt", "main_val"))

        # Feature updates an existing row (no new-row AUTOINCREMENT conflict)
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? WHERE option_name=?",
            ("Feature Site", "blogname"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # Main's independently-added option must survive the merge
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("main_only_opt",))
        assert rows and rows[0][0] == "main_val", "main's option must survive merge"

        # Feature's update must also be applied
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("blogname",))
        assert rows and rows[0][0] == "Feature Site"

    def test_branch_delete_propagates_to_main(self, branched_db):
        """Branch deletes row, main unchanged → deleted on main after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Verify shared_option exists on main
        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("shared_option",))
        assert rows, "shared_option should exist on main before merge"

        # Feature deletes shared_option
        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name=?",
            ("shared_option",))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("shared_option",))
        assert not rows, "shared_option should have been deleted from main"

    def test_both_delete_same_row(self, branched_db):
        """Both branches delete the same row → clean merge (no conflict)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name=?",
            ("shared_option",))
        sqlite_exec(site_fp,
            "DELETE FROM b1_wp_options WHERE option_name=?",
            ("shared_option",))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("shared_option",))
        assert not rows, "row deleted by both should stay gone"

    def test_different_rows_both_modified(self, branched_db):
        """Branch changes A, main changes B → both changes kept after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? WHERE option_name=?",
            ("Feature Site", "blogname"))
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? WHERE option_name=?",
            ("http://main.example.com", "siteurl"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("blogname",))
        assert rows and rows[0][0] == "Feature Site", "feature's blogname change must be on main"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("siteurl",))
        assert rows and rows[0][0] == "http://main.example.com", "main's siteurl change must survive"

    def test_new_table_on_branch_merged_to_main(self, branched_db):
        """Branch has new plugin table → table created on main after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        db = sqlite3.connect(str(site_fp))
        db.executescript(f"""
            CREATE TABLE IF NOT EXISTS b{fid}_wp_custom_plugin (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                data TEXT NOT NULL DEFAULT ''
            );
            INSERT INTO b{fid}_wp_custom_plugin (data) VALUES ('row1'), ('row2');
        """)
        db.commit()
        db.close()

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT name FROM sqlite_master WHERE type='table' AND name='b1_wp_custom_plugin'")
        assert rows, "b1_wp_custom_plugin should exist on main after merge"

        rows = sqlite_q(site_fp, "SELECT data FROM b1_wp_custom_plugin ORDER BY id")
        assert [r[0] for r in rows] == ["row1", "row2"]

    def test_merge_is_idempotent(self, branched_db):
        """Merging the same branch twice → second merge is a no-op."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("idem_opt", "idem_val"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"first merge failed:\n{r.stdout}\n{r.stderr}"

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"second merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("idem_opt",))
        assert rows and rows[0][0] == "idem_val"


# ── TestDBMergeConflicts ──────────────────────────────────────────────────────

class TestDBMergeConflicts:

    def test_conflict_same_option_modified_both_sides(self, branched_db):
        """Both change blogname → CONFLICT detected with abort strategy (default)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? WHERE option_name=?",
            ("Feature Name", "blogname"))
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? WHERE option_name=?",
            ("Main Name", "blogname"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, f"expected exit 2 for conflict, got {r.returncode}"
        assert "CONFLICT" in r.stdout.upper(), \
            f"expected CONFLICT in output:\n{r.stdout}"

    def test_conflict_strategy_ours_keeps_target(self, branched_db):
        """Same conflict, --strategy=ours → main's value kept."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? WHERE option_name=?",
            ("Feature Name", "blogname"))
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? WHERE option_name=?",
            ("Main Name", "blogname"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--strategy", "ours")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("blogname",))
        assert rows and rows[0][0] == "Main Name", \
            f"ours strategy should keep main's value, got {rows}"

    def test_conflict_strategy_theirs_takes_source(self, branched_db):
        """Same conflict, --strategy=theirs → branch's value wins."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? WHERE option_name=?",
            ("Feature Name", "blogname"))
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? WHERE option_name=?",
            ("Main Name", "blogname"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main",
                      "--strategy", "theirs")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("blogname",))
        assert rows and rows[0][0] == "Feature Name", \
            f"theirs strategy should take feature's value, got {rows}"

    def test_conflict_source_deleted_target_modified(self, branched_db):
        """Branch deletes row, main modified it → CONFLICT."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"DELETE FROM b{fid}_wp_options WHERE option_name=?",
            ("shared_option",))
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? WHERE option_name=?",
            ("modified_on_main", "shared_option"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, \
            f"expected exit 2 for delete/modify conflict, got {r.returncode}"

    def test_conflict_both_insert_same_pk(self, branched_db):
        """Both insert row with same PK, different content → CONFLICT."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Pick an option_id that doesn't exist yet in either table
        rows = sqlite_q(site_fp, "SELECT MAX(option_id) FROM b1_wp_options")
        next_id = (rows[0][0] or 0) + 50

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_id, option_name, option_value) VALUES (?, ?, ?)",
            (next_id, "collision_feat", "feature_collision"))
        sqlite_exec(site_fp,
            "INSERT INTO b1_wp_options (option_id, option_name, option_value) VALUES (?, ?, ?)",
            (next_id, "collision_main", "main_collision"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, \
            f"expected exit 2 for same-pk conflict, got {r.returncode}"

    def test_abort_makes_no_changes(self, branched_db):
        """--strategy=abort must leave target COMPLETELY unchanged on DB conflict."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Create a conflict: both branches modify the same option
        sqlite_exec(site_fp,
            f"UPDATE b{fid}_wp_options SET option_value=? WHERE option_name=?",
            ("Feature Name", "blogname"))
        sqlite_exec(site_fp,
            "UPDATE b1_wp_options SET option_value=? WHERE option_name=?",
            ("Main Name", "blogname"))

        # Also add a clean (non-conflicting) option on feature that should NOT be applied
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("should_not_appear", "never"))

        # Snapshot main's table before the attempted merge
        before = sqlite_q(site_fp,
            "SELECT option_id, option_name, option_value FROM b1_wp_options ORDER BY option_id")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 2, f"expected exit 2, got {r.returncode}"

        after = sqlite_q(site_fp,
            "SELECT option_id, option_name, option_value FROM b1_wp_options ORDER BY option_id")
        assert before == after, \
            f"main table changed after aborted merge!\nbefore: {before}\nafter: {after}"


# ── TestDBMergeIntegrity ──────────────────────────────────────────────────────

class TestDBMergeIntegrity:

    def test_unique_constraints_preserved_after_merge(self, branched_db):
        """Target table still enforces UNIQUE on option_name after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("integrity_test", "val1"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        db = sqlite3.connect(str(site_fp))
        try:
            with pytest.raises(sqlite3.IntegrityError):
                db.execute(
                    "INSERT INTO b1_wp_options (option_name, option_value) VALUES (?, ?)",
                    ("integrity_test", "duplicate"))
                db.commit()
        finally:
            db.close()

    def test_autoincrement_not_broken_after_merge(self, branched_db):
        """Can INSERT new rows after merge (AUTOINCREMENT sequence intact)."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("autoincrement_test", "val"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        db = sqlite3.connect(str(site_fp))
        try:
            db.execute(
                "INSERT INTO b1_wp_options (option_name, option_value) VALUES (?, ?)",
                ("post_merge_insert", "newval"))
            db.commit()
        finally:
            db.close()

        rows = sqlite_q(site_fp,
            "SELECT option_value FROM b1_wp_options WHERE option_name=?",
            ("post_merge_insert",))
        assert rows and rows[0][0] == "newval"

    def test_indexes_survive_merge(self, branched_db):
        """Indexes on target table are intact after merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("idx_test_opt", "val"))

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        # UNIQUE index on option_name should still exist
        rows = sqlite_q(site_fp,
            "SELECT name FROM sqlite_master "
            "WHERE type='index' AND tbl_name='b1_wp_options' AND sql IS NOT NULL")
        assert rows, "indexes on b1_wp_options should still exist after merge"

    def test_source_unchanged_after_merge(self, branched_db):
        """Source branch tables are NOT modified by the merge."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) VALUES (?, ?)",
            ("source_check", "source_val"))

        before = sqlite_q(site_fp,
            f"SELECT option_id, option_name, option_value "
            f"FROM b{fid}_wp_options ORDER BY option_id")

        r = branchctl(site_fp, "merge", "feature", "--into", "main")
        assert r.returncode == 0, f"merge failed:\n{r.stdout}\n{r.stderr}"

        after = sqlite_q(site_fp,
            f"SELECT option_id, option_name, option_value "
            f"FROM b{fid}_wp_options ORDER BY option_id")
        assert before == after, "source tables were modified by merge"

    def test_ancestor_snapshot_matches_fork_state(self, branched_db):
        """db_snapshots captured correctly at branch-create time."""
        site_fp = branched_db["site_fp"]
        fid = branched_db["feature_id"]

        # Query snapshot rows for the feature branch's options table
        snap_rows = sqlite_q(site_fp,
            "SELECT row_json FROM db_snapshots WHERE branch_id=? AND table_name=?",
            (fid, f"b{fid}_wp_options"))

        assert snap_rows, "db_snapshots must have rows for feature branch wp_options"

        # At this point no changes have been made to feature's table,
        # so snapshot count must match table row count.
        actual_rows = sqlite_q(site_fp, f"SELECT * FROM b{fid}_wp_options")
        assert len(snap_rows) == len(actual_rows), \
            f"snapshot has {len(snap_rows)} rows but table has {len(actual_rows)}"

        # Verify known option_names are in the snapshot
        snap_json_all = " ".join(r[0] for r in snap_rows)
        assert "blogname" in snap_json_all
        assert "siteurl" in snap_json_all
        assert "shared_option" in snap_json_all
