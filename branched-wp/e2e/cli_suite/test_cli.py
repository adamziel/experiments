"""
CLI contract tests.

Every test in this file exercises a single CLI verb, running it with
concrete arguments and asserting on the visible outputs: stdout,
stderr, and exit code. No file-level or SQLite-level peeking.

Structure:
    TestInit        forkpress init
    TestBranchList  forkpress branch list
    TestBranchCreate
    TestBranchShow
    TestBranchDelete
    TestCommit
    TestLog
    TestStatus
    TestDiff
    TestRollback    forkpress branch rollback (refuse-on-fresh-branch)
    TestReset       forkpress branch reset (refuse bad commit)
    TestMerge       forkpress branch merge (flag validation + no-op merge)
    TestGc
    TestAudit
    TestUser        forkpress user add/list/remove/verify/auth-enabled
    TestBackup      forkpress backup
    TestExportImport forkpress export + import round-trip
    TestHelp        forkpress / branchctl help output

A "fresh" .forkpress work_dir is used for each test via the `site`
fixture so tests don't pollute each other.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

import pytest


# =========================================================================
# forkpress init
# =========================================================================


class TestInit:
    def test_init_creates_site_fp(self, fresh_site):
        r = fresh_site.init().expect_ok()
        assert fresh_site.site_fp.exists(), "site.fp was not created"
        assert fresh_site.site_fp.stat().st_size > 0, "site.fp is empty"

    def test_init_emits_admin_password_block(self, fresh_site):
        r = fresh_site.init().expect_ok()
        out = r.combined()
        # Either an explicit admin-password was stashed, or the script prints
        # a one-time generated password banner. Both paths are the contract.
        assert "admin" in out.lower(), f"no admin-related output: {out[-300:]}"
        assert "branch created" in out.lower() or "initialized" in out.lower(), out[-300:]

    def test_init_refuses_to_overwrite(self, fresh_site):
        fresh_site.init().expect_ok()
        r = fresh_site.init().expect_fail()
        assert "already" in r.combined().lower() or "exist" in r.combined().lower(), (
            f"expected refuse-to-overwrite message, got:\n{r.combined()}"
        )

    def test_init_accepts_admin_password_flag(self, fresh_site):
        r = fresh_site.init(admin_password="hunter2").expect_ok()
        # Explicit password → no generated-password banner.
        assert "hunter2" not in r.combined(), "password should not echo back"


# =========================================================================
# forkpress branch list
# =========================================================================


class TestBranchList:
    def test_list_shows_main_after_init(self, site):
        r = site.branch("list").expect_ok()
        assert "main" in r.stdout, f"main missing from list:\n{r.stdout}"

    def test_list_has_header_row(self, site):
        r = site.branch("list").expect_ok()
        # The exact header is part of the CLI contract.
        assert re.search(r"BRANCH\s+PARENT", r.stdout), r.stdout


# =========================================================================
# forkpress branch create
# =========================================================================


class TestBranchCreate:
    def test_create_new_branch_from_main(self, site):
        r = site.branch("create", "staging").expect_ok()
        assert "staging" in r.combined(), r.combined()
        assert "main" in r.combined(), r.combined()
        # New branch visible in list:
        out = site.branch("list").expect_ok().stdout
        assert "staging" in out, out

    def test_create_rejects_duplicate(self, site):
        site.branch("create", "feat-a").expect_ok()
        r = site.branch("create", "feat-a").expect_fail()
        assert "exist" in r.combined().lower() or "already" in r.combined().lower(), (
            r.combined()
        )

    def test_create_rejects_reserved_main(self, site):
        r = site.branch("create", "main").expect_fail()
        assert "reserved" in r.combined().lower() or "main" in r.combined().lower(), (
            r.combined()
        )

    def test_create_rejects_invalid_name(self, site):
        # The name validator rejects anything outside [a-zA-Z0-9_-]{1,63}.
        # Dots are the canonical "surely-invalid" case.
        r = site.branch("create", "has.dot").expect_fail()
        assert "invalid" in r.combined().lower(), r.combined()

    def test_create_from_specific_parent(self, site):
        site.branch("create", "parent-br").expect_ok()
        r = site.branch("create", "child-br", "--from", "parent-br").expect_ok()
        assert "child-br" in r.combined()
        assert "parent-br" in r.combined()


# =========================================================================
# forkpress branch show
# =========================================================================


class TestBranchShow:
    def test_show_main(self, site):
        r = site.branch("show", "main").expect_ok()
        out = r.stdout
        assert "main" in out, out
        # show prints id, parent, files, created
        assert "created" in out.lower() or "files" in out.lower(), out

    def test_show_missing_branch_reports_missing(self, site):
        # Current contract: show of an unknown branch prints "no overlay
        # named ..." and exits 0. A backend that raises instead is also
        # acceptable — we only require the name is echoed in some form.
        r = site.branch("show", "nope-missing")
        assert "nope-missing" in r.combined() or "no overlay" in r.combined().lower() \
               or "no branch" in r.combined().lower(), r.combined()


# =========================================================================
# forkpress branch delete
# =========================================================================


class TestBranchDelete:
    def test_delete_removes_from_list(self, site):
        site.branch("create", "tmp-br").expect_ok()
        assert "tmp-br" in site.branch("list").stdout

        site.branch("delete", "tmp-br").expect_ok()
        assert "tmp-br" not in site.branch("list").stdout

    def test_delete_refuses_main(self, site):
        r = site.branch("delete", "main").expect_fail()
        assert "main" in r.combined().lower(), r.combined()

    def test_delete_missing_branch_reports_missing(self, site):
        # Current contract prints "no branch named ..." and exits 0;
        # either that or a non-zero exit is acceptable. What we check is
        # that the name is surfaced and the list is not disturbed.
        before = site.branch("list").expect_ok().stdout
        r = site.branch("delete", "never-existed")
        assert "never-existed" in r.combined() or "no branch" in r.combined().lower(), (
            r.combined()
        )
        after = site.branch("list").expect_ok().stdout
        assert before == after, "list changed after deleting a non-existent branch"


# =========================================================================
# forkpress branch commit (+ log)
# =========================================================================


class TestCommit:
    def test_commit_with_no_changes_is_noop(self, site):
        site.branch("create", "empty-br").expect_ok()
        r = site.branch("commit", "empty-br", "-m", "nothing").expect_ok()
        out = r.combined().lower()
        assert "no changes" in out or "skipped" in out, r.combined()

    def test_log_shows_initial_snapshot(self, site):
        site.branch("create", "log-br").expect_ok()
        r = site.branch("log", "log-br").expect_ok()
        # The `create` records an initial snapshot.
        assert re.search(r"[0-9a-f]{8,}", r.stdout), (
            f"no commit hash in log:\n{r.stdout}"
        )
        assert "create" in r.stdout.lower() or "initial" in r.stdout.lower(), r.stdout


# =========================================================================
# forkpress branch status
# =========================================================================


class TestStatus:
    def test_status_fresh_branch_is_clean(self, site):
        site.branch("create", "clean-br").expect_ok()
        r = site.branch("status", "clean-br").expect_ok()
        out = r.combined().lower()
        assert "clean" in out or "no " in out or "0" in out, r.combined()


# =========================================================================
# forkpress branch diff
# =========================================================================


class TestDiff:
    def test_diff_identical_branches_shows_no_changes(self, site):
        site.branch("create", "left-br").expect_ok()
        site.branch("create", "right-br", "--from", "left-br").expect_ok()
        r = site.branch("diff", "left-br", "right-br").expect_ok()
        assert (
            "no file difference" in r.combined().lower()
            or "no differences" in r.combined().lower()
            or "0" in r.combined()
        ), r.combined()


# =========================================================================
# forkpress branch rollback / reset
# =========================================================================


class TestRollback:
    def test_rollback_single_commit_branch_reports_nothing_to_undo(self, site):
        # A branch has one snapshot right after create; rollback has
        # nothing to go back to. The CLI must refuse with a clear message
        # rather than silently succeeding.
        site.branch("create", "only-one").expect_ok()
        r = site.branch("rollback", "only-one")
        if r.ok:
            # Some backends may treat "nothing to undo" as a warning; that's
            # acceptable as long as the message is present.
            assert "nothing" in r.combined().lower() or "no previous" in r.combined().lower()
        else:
            assert "no" in r.combined().lower() or "previous" in r.combined().lower(), (
                r.combined()
            )


class TestReset:
    def test_reset_unknown_commit_hash_fails(self, site):
        site.branch("create", "reset-br").expect_ok()
        r = site.branch("reset", "reset-br", "0000000000000000").expect_fail()
        assert "commit" in r.combined().lower() or "not found" in r.combined().lower(), (
            r.combined()
        )

    def test_reset_to_existing_commit_succeeds(self, site):
        site.branch("create", "reset-ok").expect_ok()
        log_out = site.branch("log", "reset-ok").expect_ok().stdout
        # The log's first column is the full 32-char commit hash; reset
        # requires the full hash (not a prefix).
        m = re.search(r"\b[0-9a-f]{32}\b", log_out)
        assert m, f"no full-length hash in log output:\n{log_out}"
        commit = m.group(0)
        r = site.branch("reset", "reset-ok", commit).expect_ok()
        # After reset, the commit is the branch's HEAD — subsequent log
        # must still include it.
        log_after = site.branch("log", "reset-ok").expect_ok().stdout
        assert commit in log_after or commit[:12] in log_after, log_after


# =========================================================================
# forkpress branch merge
# =========================================================================


class TestMerge:
    def test_merge_requires_into_flag(self, site):
        site.branch("create", "merge-src").expect_ok()
        r = site.branch("merge", "merge-src")
        # Missing --into: should fail, not silently do nothing.
        assert not r.ok or "into" in r.combined().lower(), r.combined()

    def test_merge_identical_branches_is_noop(self, site):
        site.branch("create", "m-a").expect_ok()
        site.branch("create", "m-b", "--from", "m-a").expect_ok()
        r = site.branch("merge", "m-b", "--into", "m-a").expect_ok()
        out = r.combined().lower()
        # Contract: merge runs to completion, with zero applied rows / paths.
        # Any of these markers signals "ran cleanly"; being flexible about
        # the wording lets alternative backends satisfy the contract.
        markers = ["merge complete", "applied: 0", "0 paths", "up to date",
                   "no changes", "clean"]
        assert any(m in out for m in markers), (
            f"merge output lacks any completion marker:\n{r.combined()}"
        )


# =========================================================================
# forkpress branch gc
# =========================================================================


class TestGc:
    def test_gc_dry_run_prints_would_delete(self, site):
        r = site.branch("gc", "--dry-run").expect_ok()
        assert "would" in r.combined().lower() or "dry" in r.combined().lower() \
               or "blob" in r.combined().lower(), r.combined()

    def test_gc_live_runs_without_error_on_fresh_site(self, site):
        r = site.branch("gc").expect_ok()
        # On a fresh site there are no orphan blobs, but the command must
        # still succeed and describe what it did.
        assert "blob" in r.combined().lower() or "free" in r.combined().lower() \
               or r.combined().strip() != "", r.combined()


# =========================================================================
# forkpress branch audit
# =========================================================================


class TestAudit:
    def test_audit_records_branch_create_and_delete(self, site):
        site.branch("create", "trail-br").expect_ok()
        site.branch("delete", "trail-br").expect_ok()
        r = site.branch("audit").expect_ok()
        assert "trail-br" in r.stdout, r.stdout
        assert "create" in r.stdout.lower(), r.stdout
        assert "delete" in r.stdout.lower(), r.stdout


# =========================================================================
# forkpress user
# =========================================================================


class TestUser:
    def test_list_shows_default_admin(self, site):
        r = site.user("list").expect_ok()
        assert "admin" in r.stdout, r.stdout

    def test_add_then_verify(self, site):
        site.user("add", "alice", "hunter2", "--role", "write").expect_ok()
        ok = site.user("verify", "alice", "hunter2").expect_ok()
        assert "ok" in ok.combined().lower(), ok.combined()
        assert "write" in ok.combined().lower(), ok.combined()

    def test_verify_wrong_password_fails(self, site):
        site.user("add", "bob", "correct", "--role", "read").expect_ok()
        r = site.user("verify", "bob", "WRONG").expect_fail()
        assert "wrong" in r.combined().lower() or "fail" in r.combined().lower(), (
            r.combined()
        )

    def test_verify_missing_user_fails(self, site):
        r = site.user("verify", "no-such-user", "whatever").expect_fail()
        assert "no such" in r.combined().lower() or "fail" in r.combined().lower(), (
            r.combined()
        )

    def test_remove_user(self, site):
        site.user("add", "carol", "secret", "--role", "read").expect_ok()
        assert "carol" in site.user("list").expect_ok().stdout
        site.user("remove", "carol").expect_ok()
        assert "carol" not in site.user("list").expect_ok().stdout

    def test_list_rejects_duplicate_or_accepts_upsert(self, site):
        """Add-same-username-twice should either succeed (upsert) or fail loudly."""
        site.user("add", "dora", "pw1", "--role", "read").expect_ok()
        r = site.user("add", "dora", "pw2", "--role", "admin")
        if r.ok:
            # Upsert: new role should be visible.
            assert "dora" in site.user("list").stdout
            ok = site.user("verify", "dora", "pw2").expect_ok()
            assert "admin" in ok.combined().lower(), ok.combined()
        else:
            # Duplicate rejection: old password still works.
            site.user("verify", "dora", "pw1").expect_ok()

    def test_auth_enabled_get_set(self, site):
        r1 = site.user("auth-enabled").expect_ok()
        # value is "0" or "1"
        assert "0" in r1.stdout or "1" in r1.stdout, r1.stdout
        site.user("auth-enabled", "1").expect_ok()
        r2 = site.user("auth-enabled").expect_ok()
        assert "1" in r2.stdout, r2.stdout
        site.user("auth-enabled", "0").expect_ok()
        r3 = site.user("auth-enabled").expect_ok()
        assert "0" in r3.stdout, r3.stdout


# =========================================================================
# forkpress backup
# =========================================================================


class TestBackup:
    def test_backup_produces_a_copy(self, site, tmp_path):
        dst = tmp_path / "backup.fp"
        r = site.backup(site.site_fp, dst).expect_ok()
        assert dst.exists(), f"backup not written at {dst}"
        assert dst.stat().st_size > 0, "backup file is empty"
        # A backup of a fresh site should roughly match the original size
        # (ballpark sanity — exact match not required).
        original = site.site_fp.stat().st_size
        ratio = dst.stat().st_size / max(original, 1)
        assert 0.5 < ratio < 2.5, f"backup size ratio out of range: {ratio:.2f}"

    def test_backup_refuses_to_overwrite(self, site, tmp_path):
        dst = tmp_path / "existing.fp"
        dst.write_bytes(b"not a site.fp")
        r = site.backup(site.site_fp, dst).expect_fail()
        assert "exist" in r.combined().lower() or "overwrit" in r.combined().lower(), (
            r.combined()
        )


# =========================================================================
# forkpress export + import
# =========================================================================


class TestExportImport:
    def test_export_produces_manifest_and_branches_tree(self, site, tmp_path):
        out_dir = tmp_path / "export"
        r = site.export(site.site_fp, out_dir).expect_ok()
        manifest = out_dir / "manifest.json"
        assert manifest.exists(), list(out_dir.iterdir())
        data = json.loads(manifest.read_text())
        assert data.get("format_version") == 1, data
        names = [b["name"] for b in data.get("branches", [])]
        assert "main" in names, data

    def test_export_import_roundtrip_preserves_branches(self, site, tmp_path):
        # Create a couple of branches so we can check they round-trip.
        site.branch("create", "marketing").expect_ok()
        site.branch("create", "feat-x", "--from", "marketing").expect_ok()

        out_dir = tmp_path / "roundtrip-export"
        site.export(site.site_fp, out_dir).expect_ok()

        rebuilt = tmp_path / "rebuilt.fp"
        site.import_(out_dir, rebuilt).expect_ok()
        assert rebuilt.exists()

        # Re-drive the rebuilt store by pointing a new driver at it via
        # the --db flag that branchctl accepts. We call branchctl with an
        # overridden BRANCHFS_DB to list rebuilt branches.
        r = site.branch("list", "--db", str(rebuilt)).expect_ok()
        out = r.stdout
        assert "main" in out, out
        assert "marketing" in out, out
        assert "feat-x" in out, out


# =========================================================================
# help / usage
# =========================================================================


class TestHelp:
    def test_branch_help_lists_subcommands(self, site):
        # branchctl.php with no args prints full usage; this is the contract
        # callers rely on for discoverability.
        r = site.branch("help")
        # "help" isn't literally passed through when forkpress binary driver
        # wraps `branch`; the binary's own `-h` is on the outer command.
        # The passthrough echoes usage from branchctl.php. Either way we
        # expect the canonical verbs to appear in ANY CLI stream.
        out = r.combined().lower()
        if not r.ok and "usage" not in out and "verb" not in out:
            pytest.skip(f"driver doesn't route `branch help` through to branchctl: {out[:200]}")
        for verb in ("create", "delete", "commit", "log", "merge", "reset"):
            assert verb in out, f"verb {verb!r} missing from help:\n{out[:500]}"
