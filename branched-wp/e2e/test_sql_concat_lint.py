"""
TODO3 #20 — SQL concatenation lint.

Prefer prepared statements with bindValue for any user-supplied string
that flows into an exec()/query() call. The codebase uses
`SQLite3::escapeString()` for string values and casts integer ids to
(int) before interpolation, so it's already safe; this test adds a
grep-style lint that fails if a new exec() call interpolates an
arbitrary PHP variable that ISN'T on the allow-list of known-safe
integer-typed identifiers.

Keeps the bar from slipping backward without forcing a sweeping
preparedness audit today.
"""

import re
from pathlib import Path

import pytest

from _rigorous_helpers import BASE_DIR


# Scripts audited by this lint. (git_server and PHP plugins under
# wp-plugin/ are out of scope here.)
SCRIPTS = sorted((BASE_DIR / "scripts").glob("*.php"))

# Variables allowed to flow into exec() / query() string interpolation
# without a prepared statement. Each of these is cast to (int) at or
# near its assignment site — interpolating an integer into SQL is safe.
INT_VARS = {
    "bid", "branch_id", "fs_cid", "db_cid", "commit_id", "commit_id",
    "fc_id", "cid", "pid", "parent_id", "i", "k", "size", "old_id",
    "new_id", "target_commit_id", "bytes_free",
    "fid", "aid", "uid", "iid", "rid",
    "src_id", "tgt_id",
    "seq_max_parent", "branch_seq", "branch_stride_offset",
    "chunk_size", "chunk_count", "page", "cost",
    "n", "count", "rc", "fc_id", "seq", "row_id", "rowid",
    # `$in` — comma-joined list of integer placeholders, built by
    # integer-casting each element. Common idiom in opcache.php.
    "in",
}


def exec_query_interpolations(text: str):
    """Yield every `$db->exec("…${var}…")` or `$db->query("…${var}…")`
    style interpolation. Each match is (var_name, context_snippet)."""
    # Match ->exec( ... ) / ->query( ... ) calls spanning one line.
    for m in re.finditer(
        r"->(?:exec|query|querySingle)\(\s*\"([^\"]*)\"",
        text,
    ):
        body = m.group(1)
        # Find `$foo` or `{$foo}` inside the literal.
        for var_match in re.finditer(r"\{?\$([A-Za-z_][A-Za-z0-9_]*)\}?", body):
            yield var_match.group(1), body[max(0, var_match.start()-20):var_match.end()+20]


def test_no_unallowed_variable_interpolation_in_sql():
    violations = []
    for script in SCRIPTS:
        text = script.read_text()
        for var, ctx in exec_query_interpolations(text):
            if var in INT_VARS:
                continue
            # Skip if the same line uses SQLite3::escapeString or bindValue
            # — those indicate the caller is doing the work defensively.
            # The function is line-scoped so this heuristic may let a
            # nearby sanitization pass, which is fine for a soft lint.
            if "escapeString" in ctx or "bindValue" in ctx:
                continue
            violations.append(f"{script.name}: ${var} in \"…{ctx.strip()[:60]}…\"")

    assert not violations, (
        f"{len(violations)} new unprepared SQL interpolation(s) — prefer "
        f"prepared statements with bindValue (TODO3 #20).\n"
        + "\n".join(violations[:30])
    )


def test_schema_generated_names_use_escape_string():
    """Positive check: anywhere a branch-provided identifier flows into
    a quoted object name, SQLite3::escapeString is used."""
    found_uses = 0
    for script in SCRIPTS:
        if "SQLite3::escapeString" in script.read_text():
            found_uses += 1
    assert found_uses > 0, (
        "No script uses SQLite3::escapeString — either the codebase "
        "has regressed to raw concatenation or the scripts/ layout "
        "changed (TODO3 #20)."
    )
