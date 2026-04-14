#!/usr/bin/env bash
# Scripted end-to-end demo: make a change on A, sync to B, verify, change on B, sync back.
# Assumes ./setup.sh has already been run.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f .creds.env ]; then
  echo "Run ./setup.sh first." >&2
  exit 1
fi
# shellcheck disable=SC1091
source .creds.env

wp_a() { docker compose exec -u www-data -T site-a wp --path=/var/www/html "$@"; }
wp_b() { docker compose exec -u www-data -T site-b wp --path=/var/www/html "$@"; }

hr() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

hr "Create a post on Site A"
POST_TITLE="Hello from Site A — $(date +%H:%M:%S)"
wp_a post create --post_title="$POST_TITLE" --post_status=publish --post_content="Synced via wp-sync"

hr "Commit on Site A"
wp_a sync commit --message="add post: $POST_TITLE"

hr "Push A → B"
wp_a sync push --remote=http://site-b --user="$ADMIN_USER" --password="$APP_B"

hr "Pull + materialize on B"
wp_b sync pull --remote=http://site-a --user="$ADMIN_USER" --password="$APP_A"

hr "Verify post visible on B"
wp_b post list --field=post_title | grep -F "$POST_TITLE" && echo "  ✓ found"

hr "Edit title on B, commit, push back"
POST_ID=$(wp_b post list --field=ID --s="$POST_TITLE" | head -1)
wp_b post update "$POST_ID" --post_title="$POST_TITLE (edited on B)"
wp_b sync commit --message="edit on B"
wp_b sync push --remote=http://site-a --user="$ADMIN_USER" --password="$APP_A"

hr "Pull on A, verify edited title"
wp_a sync pull --remote=http://site-b --user="$ADMIN_USER" --password="$APP_B"
wp_a post list --field=post_title | grep -F "edited on B" && echo "  ✓ edit propagated"

hr "Commit log on A"
wp_a sync log --max=5

echo
echo "Demo done. Visit http://localhost:8081 and http://localhost:8082 to inspect both sites."
