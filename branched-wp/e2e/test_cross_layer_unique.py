"""
TODO3 #10 — cross-layer UNIQUE enforcement.

A branch's INSTEAD OF INSERT trigger used to insert straight into the
overlay without checking whether the UNIQUE-constrained column would
collide with an inherited parent row. The view's UNION ALL then
returned two rows with the same "unique" value — e.g. `wp_options`
with two `option_name='siteurl'` entries — which breaks WordPress
contracts.

Post-TODO3, the INSTEAD OF triggers RAISE(ABORT, …) when NEW's value
for any single-column UNIQUE constraint matches a non-tombstoned,
non-overlayed parent row. The SQLite error surfaces as a
SQLITE_CONSTRAINT_UNIQUE with a "cross-layer collision" message.
"""

import os
import shutil
import sqlite3
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    branchctl,
    create_branch,
    make_fresh_site,
    sqlite_q,
    sqlite_exec,
)


def test_insert_with_inherited_unique_value_is_rejected(tmp_path):
    """Inserting into the branch view with a UNIQUE column matching an
    inherited parent row must be rejected."""
    work, site_fp = make_fresh_site("uniq_ins_")
    try:
        # Seed main with a row.
        sqlite_exec(site_fp,
            "INSERT OR REPLACE INTO b1_wp_options (option_name, option_value) "
            "VALUES ('sitename', 'main_site')")
        fid = create_branch(site_fp, "feature")

        # Now try inserting an overlay row with the SAME option_name. The
        # pre-TODO3 code silently accepted this, leaving the view with two
        # rows for option_name='sitename'.
        db = sqlite3.connect(str(site_fp))
        try:
            with pytest.raises(sqlite3.IntegrityError) as exc:
                db.execute(
                    f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                    "VALUES ('sitename', 'branch_site')"
                )
                db.commit()
            # SQLite conveys the RAISE message; include the diagnostic marker.
            assert "cross-layer" in str(exc.value).lower() \
                or "unique" in str(exc.value).lower(), (
                    f"expected a UNIQUE-constraint error; got: {exc.value}"
                )
        finally:
            db.close()

        # View must still be single-valued for sitename (overlay insert was rejected).
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='sitename'")
        assert len(rows) == 1, (
            f"view should show exactly one row for a UNIQUE key; got {rows}"
        )
        assert rows[0][0] == "main_site", (
            f"inherited main's value should still be visible; got {rows}"
        )
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_insert_after_tombstone_is_allowed(tmp_path):
    """If the branch has deleted (tombstoned) the inherited row first,
    then inserts a new row with the same UNIQUE value, that's OK."""
    work, site_fp = make_fresh_site("uniq_tomb_")
    try:
        sqlite_exec(site_fp,
            "INSERT OR REPLACE INTO b1_wp_options (option_name, option_value) "
            "VALUES ('sitename', 'main_site')")
        fid = create_branch(site_fp, "feature")

        db = sqlite3.connect(str(site_fp))
        try:
            # Delete on the branch (tombstones the inherited row).
            db.execute(f"DELETE FROM b{fid}_wp_options WHERE option_name='sitename'")
            db.commit()
            # Now insert with the same unique value — should succeed.
            db.execute(
                f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
                "VALUES ('sitename', 'branch_site')"
            )
            db.commit()
        finally:
            db.close()

        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='sitename'")
        assert len(rows) == 1, f"{rows}"
        assert rows[0][0] == "branch_site", rows
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_insert_new_unique_value_still_works(tmp_path):
    """Inserting a row with a UNIQUE value that does NOT exist on the
    parent must succeed — the guard shouldn't over-reject."""
    work, site_fp = make_fresh_site("uniq_ok_")
    try:
        fid = create_branch(site_fp, "feature")
        sqlite_exec(site_fp,
            f"INSERT INTO b{fid}_wp_options (option_name, option_value) "
            "VALUES ('brand_new_key', 'new_val')")
        rows = sqlite_q(site_fp,
            f"SELECT option_value FROM b{fid}_wp_options WHERE option_name='brand_new_key'")
        assert rows and rows[0][0] == "new_val"
    finally:
        shutil.rmtree(work, ignore_errors=True)


def test_source_guard_for_unique_helper():
    """The cow_single_col_unique_columns helper must exist — the trigger
    emitter depends on it for the UNIQUE guard."""
    src = (BASE_DIR / "scripts" / "cow_helpers.php").read_text()
    assert "function cow_single_col_unique_columns" in src, (
        "cow_single_col_unique_columns missing — cross-layer UNIQUE "
        "guard (TODO3 #10) can't be emitted without discovering the "
        "columns."
    )
    assert "SELECT RAISE(ABORT" in src, (
        "cow_trigger_sql must emit RAISE(ABORT, …) for cross-layer "
        "UNIQUE collisions (TODO3 #10)."
    )
