#!/usr/bin/env bash
# Convenience wrapper: ./sync.sh <a|b> <commit|push|pull|log|status> [extra wp-cli args]
# Uses credentials saved by setup.sh in .creds.env.
set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f .creds.env ]; then
  echo "Run ./setup.sh first." >&2
  exit 1
fi
# shellcheck disable=SC1091
source .creds.env

site="${1:-}"
cmd="${2:-}"
shift 2 || true

case "$site" in
  a) svc=site-a; remote=http://site-b; remote_pw="$APP_B" ;;
  b) svc=site-b; remote=http://site-a; remote_pw="$APP_A" ;;
  *) echo "usage: $0 <a|b> <commit|push|pull|log|status> [extra args]" >&2; exit 1 ;;
esac

run() { docker compose exec -u www-data "$svc" wp --path=/var/www/html "$@"; }

case "$cmd" in
  commit) run sync commit "$@" ;;
  push)   run sync push   --remote="$remote" --user="$ADMIN_USER" --password="$remote_pw" "$@" ;;
  pull)   run sync pull   --remote="$remote" --user="$ADMIN_USER" --password="$remote_pw" "$@" ;;
  log|status) run sync "$cmd" "$@" ;;
  *) echo "unknown command: $cmd" >&2; exit 1 ;;
esac
