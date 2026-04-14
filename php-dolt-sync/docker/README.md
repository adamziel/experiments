# docker demo — two WordPress + SQLite sites syncing via wp-sync

## What you get

- `site-a` — WordPress on `http://localhost:8081`
- `site-b` — WordPress on `http://localhost:8082`
- Both sites run the official `sqlite-database-integration` plugin and the `wp-sync` plugin from `../plugin/wp-sync`.
- Inside the docker network the sites reach each other at `http://site-a` / `http://site-b` (used by push/pull).

## Requirements

- Docker with the `compose` subcommand (Docker Desktop, or docker-ce 20.10+ with docker-compose-plugin).

## One-command setup

```bash
cd docker
./setup.sh
```

This builds the image, starts both containers, installs WordPress on each, activates the plugins, and mints an application password per site. Credentials are printed at the end and stashed in `.creds.env` for the convenience wrapper.

Login for both: `admin / admin`.

## Scripted demo

```bash
./demo.sh
```

Creates a post on A, commits, pushes to B, pulls + materializes on B, edits it on B, pushes back, verifies on A. Takes ~15 seconds.

## Try it manually

The `sync.sh` wrapper hides the repetitive `docker compose exec -u www-data …` boilerplate:

```bash
# Make a change on site A (e.g. edit a post in wp-admin), then:
./sync.sh a commit -- --message="initial A"
./sync.sh a push

# Pull+materialize on site B:
./sync.sh b pull
./sync.sh b log

# Change something on B, push back:
./sync.sh b commit -- --message="edits on B"
./sync.sh b push
./sync.sh a pull
```

Anything after `--` is forwarded to `wp sync …`. Equivalent long form:

```bash
docker compose exec -u www-data site-a wp sync commit --message="initial A"
docker compose exec -u www-data site-a wp sync push \
  --remote=http://site-b --user=admin --password="$APP_B"
```

## Inspect state

```bash
# Chunk/ref DB for site A:
docker compose exec site-a sqlite3 /var/www/html/wpsync-data/chunks.sqlite '.tables'

# WP SQLite DB for site A:
docker compose exec site-a sqlite3 /var/www/html/wp-content/database/.ht.sqlite \
  "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl','home');"
```

## Tear down

```bash
docker compose down -v    # -v wipes the named volumes (fresh WP on next up)
```

## Layout

```
docker/
  Dockerfile        # php:8.2-apache + WP core + sqlite-database-integration + wp-cli
  docker-compose.yml
  wp-config.php     # SQLite-aware, reads WP_SITEURL from env
  entrypoint.sh
  setup.sh          # installs WP on both sites, activates plugins, mints app passwords
  sync.sh           # ./sync.sh <a|b> <commit|push|pull|log|status>
  demo.sh           # scripted end-to-end A→B→A demo
```

## Notes

- The `wp-sync` plugin is bind-mounted read-only from `../plugin/wp-sync`, so edits on the host are reflected immediately on both sites — handy for iteration.
- Each site's content-addressed chunk/ref store lives in its own named volume (`site-[ab]-wpsync`, path `/var/www/html/wpsync-data/chunks.sqlite`), separate from the WordPress DB.
- `WP_SITEURL` / `WP_HOME` are set to `http://localhost:808[12]` so browser access works, while the REST endpoints used by push/pull are served under the same domain. When one container pushes to the other using `http://site-b`, WP accepts the request because it's still the same WP install — the remote URL is only used to locate endpoints, not enforced against `siteurl`.
