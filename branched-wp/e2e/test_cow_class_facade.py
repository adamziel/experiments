"""
TODO3 #17 — class-level facade over cow_* procedural helpers.

The `Cow` class in scripts/cow_helpers.php forwards to the existing
procedural functions. No behaviour change; the class exists so new
callers (tests, future extractions) can use a tidier entry point.
"""

import os
import shutil
import subprocess
from pathlib import Path

import pytest

from _rigorous_helpers import (
    BASE_DIR,
    EXT_PATH,
    PHP_BIN,
    make_fresh_site,
)


COW = BASE_DIR / "scripts" / "cow_helpers.php"


def test_cow_class_defined():
    src = COW.read_text()
    assert "class Cow" in src, "Cow class missing from cow_helpers.php"
    for method in [
        "is_view", "is_table", "extract_pk_cols", "table_columns",
        "single_col_unique_columns", "install_parent_triggers",
        "create_branch_table", "recreate_views_for_table",
        "migrate_legacy_branch",
    ]:
        assert f"function {method}" in src, (
            f"Cow::{method} missing — class facade incomplete (TODO3 #17)"
        )


def test_cow_class_forwards_to_procedural(tmp_path):
    """Cow::is_view / Cow::is_table should match cow_is_view / cow_is_table
    for known fixtures."""
    work, site_fp = make_fresh_site("cow_cls_")
    try:
        php = (
            "require_once '" + str(COW) + "';"
            "$db = new SQLite3($argv[1]);"
            "echo 'tbl:' . (int)Cow::is_table($db, 'b1_wp_options') . ' ';"
            "echo 'view:' . (int)Cow::is_view($db, 'b1_wp_options');"
        )
        r = subprocess.run(
            [PHP_BIN, "-d", f"extension={EXT_PATH}", "-r", php, str(site_fp)],
            capture_output=True, text=True, timeout=15,
        )
        assert r.returncode == 0, r.stderr
        assert "tbl:1" in r.stdout
        assert "view:0" in r.stdout  # b1_wp_options is a real table on main
    finally:
        shutil.rmtree(work, ignore_errors=True)
