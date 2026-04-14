#!/usr/bin/env bash
# One-shot setup: install WP on both sites, activate plugins, mint app passwords, print demo commands.
set -euo pipefail

cd "$(dirname "$0")"

ADMIN_USER="admin"
ADMIN_PASS="admin"
ADMIN_EMAIL="admin@example.test"

wp_a() { docker compose exec -u www-data site-a wp --path=/var/www/html "$@"; }
wp_b() { docker compose exec -u www-data site-b wp --path=/var/www/html "$@"; }

echo "→ Waiting for containers to be up..."
docker compose up -d --build

# Wait for apache on each
for port in 8081 8082; do
  for i in $(seq 1 30); do
    if curl -fsS "http://localhost:${port}/wp-admin/install.php" >/dev/null 2>&1; then break; fi
    sleep 1
  done
done

install_site() {
  local fn="$1" url="$2" title="$3"
  if $fn core is-installed 2>/dev/null; then
    echo "  already installed: $url"; return
  fi
  $fn core install \
    --url="$url" \
    --title="$title" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
  $fn plugin activate sqlite-database-integration || true
  $fn plugin activate wp-sync
}

echo "→ Installing site A (http://localhost:8081)"
install_site wp_a "http://localhost:8081" "Site A"

echo "→ Installing site B (http://localhost:8082)"
install_site wp_b "http://localhost:8082" "Site B"

echo "→ Minting application passwords"
# --porcelain prints just the password.
APP_A=$(wp_a user application-password create "$ADMIN_USER" "wp-sync" --porcelain | tr -d '\r\n ')
APP_B=$(wp_b user application-password create "$ADMIN_USER" "wp-sync" --porcelain | tr -d '\r\n ')

cat <<EOF

=====================================================
  Setup complete.

  Admin UI:
    Site A: http://localhost:8081/wp-admin/   ($ADMIN_USER / $ADMIN_PASS)
    Site B: http://localhost:8082/wp-admin/   ($ADMIN_USER / $ADMIN_PASS)

  Inside the docker network the sites reach each other as http://site-a / http://site-b.
  App passwords (for sync push/pull):
    A: $APP_A
    B: $APP_B

  Try:
    docker compose exec -u www-data site-a wp sync commit --message="initial on A"
    docker compose exec -u www-data site-a wp sync push   --remote=http://site-b --user=$ADMIN_USER --password="$APP_B"
    docker compose exec -u www-data site-b wp sync pull   --remote=http://site-a --user=$ADMIN_USER --password="$APP_A"
    docker compose exec -u www-data site-b wp sync log

  (There's also a convenience wrapper: ./sync.sh a commit -- --message="..." )
=====================================================
EOF

# Stash creds for the convenience wrapper.
cat > .creds.env <<EOF
ADMIN_USER=$ADMIN_USER
APP_A=$APP_A
APP_B=$APP_B
EOF
