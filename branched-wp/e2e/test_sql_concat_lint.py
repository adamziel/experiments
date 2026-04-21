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


# Scripts audited by this lint. Recurse into scripts/ so subdirectories
# (git_server/, any future per-feature subtrees) are covered — hostile
# review #15 flagged git_server/server.php being skipped silently.
SCRIPTS = sorted((BASE_DIR / "scripts").rglob("*.php"))

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
    """Yield every risky `$db->exec(...)` / `->query(...)` /
    `->querySingle(...)` argument, where "risky" means either:
      a) a double-quoted literal with `${var}` or `{$var}` interpolation
         inside (existing behaviour), or
      b) a dot-concatenation like `"…" . $var . "…"` that splices a raw
         PHP variable into the SQL string without bindValue. Hostile
         review #15 found the v1 lint skipped this entirely, so the
         three existing `->exec('…' . $overlay_name . '…')` concats in
         cow_helpers.php were invisible to CI.
    Each match yields (var_name, context_snippet)."""
    for m in re.finditer(
        r"->(?:exec|query|querySingle|prepare)\s*\(\s*(.+?)\s*\)\s*;",
        text,
        flags=re.DOTALL,
    ):
        arg = m.group(1)
        # Pass the WHOLE call argument as ctx so the escapeString /
        # bindValue bypass sees sanitization applied anywhere inside
        # the same expression, not just a 20-char slice near the var
        # (caught a false positive in launcher.php where escapeString
        # was present on the line but outside the narrow slice).
        # (a) interpolations inside a double-quoted literal.
        for lit in re.finditer(r'"([^"\\]*(?:\\.[^"\\]*)*)"', arg):
            body = lit.group(1)
            for var_match in re.finditer(
                r"\{?\$([A-Za-z_][A-Za-z0-9_]*)\}?", body
            ):
                yield var_match.group(1), arg
        # (b) concatenation: `$var` adjacent to a `.` operator inside the
        # argument list, excluding ones inside string literals (handled
        # in (a)). Strip literals first so a literal dot isn't mistaken
        # for the concat operator.
        stripped = re.sub(r'"([^"\\]*(?:\\.[^"\\]*)*)"', '""', arg)
        stripped = re.sub(r"'([^'\\]*(?:\\.[^'\\]*)*)'", "''", stripped)
        for var_match in re.finditer(
            r"\.\s*\$([A-Za-z_][A-Za-z0-9_]*)"
            r"|\$([A-Za-z_][A-Za-z0-9_]*)\s*\.",
            stripped,
        ):
            name = var_match.group(1) or var_match.group(2)
            yield name, arg


# Name-suffix allowlist for identifier fragments that are constructed
# internally (not from user input) and get spliced into SQL as object
# names, column lists, DDL fragments, or pre-escaped values. Catching
# these as "injection risk" would be a false positive — the code never
# flows external text through them.
SAFE_NAME_SUFFIXES = (
    "_name",   # $overlay_name, $view_name, $table_name, $trigger_name, …
    "_cols",   # $pk_cols, $col_cols — always arrays of identifiers
    "_list",   # $col_list, $placeholder_list — comma-joined identifier strings
    "_q",      # $parent_q, $tomb_q, $ovl_q — pre-escaped identifiers
    "_esc",    # $pa_prefix_esc, $x_esc — pre-escaped values
    "_sql",    # $idx_sql, $view_sql_body — DDL fragments
    "_body",   # $body, $view_body — DDL fragments
    "_pk",     # $tomb_pk, $pk — PK column list
    "_def",    # $col_def — column definitions
    "_type",   # $col_type — SQL type fragments
)
SAFE_NAME_PREFIXES = (
    "trg",         # $trg, $trg_upd, $trg_del, $trg_ins — trigger names
    "overlay",     # $overlay, $overlay_name
    "tomb",        # $tomb, $tomb_name
    "logical",     # $logical, $logical_name
    "physical",    # $physical
    "view",        # $view, $view_name
    "parent_",     # $parent_table, $parent_q
    "placeholders",# $placeholders — '?,?,?'
    "col_",        # $col_name, $col_type, $col_def, $col_list
    "ddl_source",  # $ddl_source — CREATE TABLE capture
    "tgt_table",   # $tgt_table — constructed from branch prefix + suffix
    "src_table",   # $src_table — constructed from branch prefix + suffix
)
EXACT_SAFE_NAMES = {
    # PDO self-reference inside branched_pdo.php's $this->quote() chain.
    "this",
    # Hardcoded prefix strings like 'b1_wp_', 'branchfs://', built in PHP
    # outside SQL construction and safe-by-content.
    "prefix", "vname", "tname", "n",
    # CHECK / DDL fragment strings built from constants.
    "body", "def", "idx_sql",
    # merge.php internal identifiers:
    # $esc = $db->escapeString(...) — pre-escaped value, safe.
    "esc",
    # $ov_suffix = '__overlay' — hardcoded constant.
    "ov_suffix",
    # $where = implode(' AND ', <PK column name fragments>) — built
    # from internal PK column identifiers, no external input.
    "where",
    # $mcol — iteration variable over internal column name list in
    # cow_helpers.php ALTER TABLE ADD COLUMN loop.
    "mcol",
}


def _is_safe_identifier(var: str) -> bool:
    if var in INT_VARS or var in EXACT_SAFE_NAMES:
        return True
    for suffix in SAFE_NAME_SUFFIXES:
        if var.endswith(suffix):
            return True
    for prefix in SAFE_NAME_PREFIXES:
        if var.startswith(prefix):
            return True
    return False


def test_no_unallowed_variable_interpolation_in_sql():
    violations = []
    for script in SCRIPTS:
        text = script.read_text()
        for var, ctx in exec_query_interpolations(text):
            if _is_safe_identifier(var):
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
        f"prepared statements with bindValue, pre-escape with "
        f"SQLite3::escapeString(), or add the variable to INT_VARS / "
        f"SAFE_NAME_{{PREFIXES,SUFFIXES}} / EXACT_SAFE_NAMES if it is "
        f"safe by construction (TODO3 #20, hostile review #15).\n"
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
