<?php
/**
 * TODO3 #12 audit trail + first slice of TODO3 #16 split.
 *
 * Audit-log helpers extracted from branchctl.php so the main file can
 * focus on command dispatch. As more subcommands follow this pattern
 * (planned next: scripts/branchctl/cmd_commit.php, cmd_merge.php, …),
 * branchctl.php shrinks toward a pure router.
 */

if (!function_exists('audit_log_write')) {

/** TODO3 #12 — write a row into audit_log.
 *
 *  Best-effort: if the audit_log table isn't present yet (migration in
 *  flight) or the INSERT fails for any reason, return silently. Audit
 *  is a bookkeeping feature — a logging failure must never abort an
 *  otherwise-valid user command. */
function audit_log_write(SQLite3 $db, string $action, ?string $target = null,
                         ?array $details = null): void {
    $actor = getenv('FORKPRESS_ACTOR');
    if ($actor === false || $actor === '') {
        $actor = function_exists('get_current_user') ? get_current_user() : '';
    }
    if ($actor === '' || $actor === false) $actor = 'anonymous';
    try {
        $s = @$db->prepare(
            "INSERT INTO audit_log (actor, action, target, details) "
          . "VALUES (:a, :act, :t, :d)"
        );
        if (!$s) return;
        $s->bindValue(':a',   $actor,  SQLITE3_TEXT);
        $s->bindValue(':act', $action, SQLITE3_TEXT);
        if ($target === null) {
            $s->bindValue(':t', null, SQLITE3_NULL);
        } else {
            $s->bindValue(':t', $target, SQLITE3_TEXT);
        }
        $s->bindValue(':d',
            $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            $details === null ? SQLITE3_NULL : SQLITE3_TEXT);
        @$s->execute();
    } catch (\Throwable $_) {
        /* swallow */
    }
}

}
