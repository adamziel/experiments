# Building `gitpress` from source

This document walks through producing a release-style `gitpress` tarball
on your own machine. You want this if:

1. The platform you're on isn't covered by a pre-built release (today:
   only `linux-x86_64` has a pre-built artifact — macOS builds are
   reproducible from here while the CI pipeline for the full matrix
   remains unfinished).
2. You want to audit the build against the source you see in the repo.
3. You're iterating on `ext/branchfs.c`, the gitpress launcher, or the
   spc integration, and want to smoke-test end-to-end locally.

The same pipeline runs in CI — see
`.github/workflows/branched-wp-release.yml` for the per-target matrix
version. What you're doing here is the single-platform, one-host-at-a-time
version of that.

## TL;DR for macOS

```bash
# From this repo's root, on a Mac with Homebrew already installed.
brew install php composer git jq rust dolt      # ~2-3 min
cd branched-wp
bash BUILDING.sh                                 # ~20-40 min on cold cache
# ↳ produces: dist/gitpress-<version>-macos-<arch>.tar.gz
#             dist/SHA256SUMS
```

The rest of this doc explains what that script does and how to run the
pieces by hand if it breaks.

## Supported host platforms

| Host | Target this builds | Status |
| --- | --- | --- |
| Linux x86_64 (any distro with a working toolchain) | `linux-x86_64` | Shipped |
| macOS x86_64 (Intel) | `macos-x86_64` | Reproducible from source |
| macOS aarch64 (Apple Silicon) | `macos-aarch64` | Reproducible from source |
| Linux aarch64 | `linux-aarch64` | Should work; untested |
| Windows | — | Not supported. See `LIMITATIONS.md`. |

You always build for the host you're on — there's no cross-compile step.
`static-php-cli` produces a native binary for the machine it runs on.

## What the build actually does

1. Clone a pinned revision of
   [`static-php-cli`](https://github.com/crazywhalecc/static-php-cli)
   ("spc") into `branched-wp/.spc/static-php-cli/`.
2. Run `ci/spc-branchfs/integrate.sh` against that checkout. This
   teaches spc about `branchfs` as a first-class built-in extension
   (registers it in `config/ext.json` as `type: "builtin"` and drops a
   one-file `Extension` subclass into `src/SPC/builder/extension/`).
3. Run spc's `doctor` / `download` / `build` flow. spc downloads PHP
   source + every extension's dependency (SQLite, libxml, zlib, …) and
   builds a fully statically-linked `php` binary with branchfs and the
   standard WP extension set compiled in.
4. Download the matching Dolt binary for the host platform.
5. Stage `runtime/{bin/php, bin/dolt}` with those two binaries. No
   `lib/branchfs.so` — branchfs is inside the `php` binary.
6. Run `GITPRESS_RUNTIME_DIR=$PWD/runtime cargo build --release -p gitpress
   --target <host-triple>` to produce the launcher. The launcher embeds
   the runtime tarball via `include_bytes!`, so the resulting binary is
   ~100 MB but fully self-contained.
7. Package the launcher + a README into a tarball and emit a
   `SHA256SUMS` file next to it.

## Prerequisites

### All hosts

- `git` (any modern version)
- `rustup` + a Rust stable toolchain (≥ 1.80). Install via
  <https://rustup.rs>. After install, run:
  ```bash
  rustup target add x86_64-unknown-linux-musl   # on Linux x86_64
  rustup target add aarch64-unknown-linux-musl  # on Linux aarch64
  # macOS: the default host target is fine; no `rustup target add` needed.
  ```
- `jq` (for the integrate.sh script)
- `curl` + `tar`
- `python3` (integrate.sh uses it to merge one JSON entry)
- A working C compiler + autotools + `pkg-config` (for spc's builds)
- **PHP 8.2+** and **composer** on the host — needed *only* to run the
  spc CLI. The release PHP binary is built from source by spc; your
  host PHP is just the interpreter that runs spc's PHP-based build
  driver.
- **Dolt** — `curl -sL https://github.com/dolthub/dolt/releases/latest/download/install.sh | bash`
  is the one-liner. On macOS, `brew install dolt` also works.

### macOS-specific

```bash
# Xcode command-line tools (compiler + headers)
xcode-select --install

# Everything else via Homebrew
brew install php composer git jq rust dolt pkg-config autoconf automake libtool
```

spc's `./bin/spc doctor --auto-fix` will auto-install anything missing
via Homebrew on macOS — safe to run even if you skip the manual
install above.

### Linux-specific

On Debian/Ubuntu:
```bash
sudo apt-get install -y build-essential autoconf automake libtool pkg-config \
                        php-cli composer git curl jq python3
# Rust + Dolt as above.
```

On NixOS, wrap the whole build in a `nix-shell -p ...` with the above
packages. spc's `doctor --auto-fix` will fail to install anything
system-wide (NixOS doesn't do that), but as long as the compiler +
autotools are present in the shell, spc's own build steps don't need
anything else.

## Step-by-step build

Run all commands from `branched-wp/` (this directory).

### 1. Clone spc at the pinned ref

```bash
SPC_REF="2.4.0"   # keep in sync with .github/workflows/branched-wp-release.yml
mkdir -p .spc
git clone --depth 1 --branch "$SPC_REF" \
  https://github.com/crazywhalecc/static-php-cli.git .spc/static-php-cli
(cd .spc/static-php-cli && composer install --no-dev --no-interaction)
```

### 2. Register branchfs with spc

```bash
bash ci/spc-branchfs/integrate.sh .spc/static-php-cli
```

This is idempotent — safe to re-run. It adds one entry to spc's
`config/ext.json` and installs one PHP class file. Nothing outside
`.spc/static-php-cli/` is touched.

### 3. Build static PHP + extensions

```bash
cd .spc/static-php-cli

# Set by Branchfs.php::patchBeforeBuildconf() to locate branchfs.c/.h/config.m4
export SPC_BRANCHFS_SOURCE="$(pwd)/../../ext"

# Fetches all source tarballs (PHP, extensions, deps). Cached under
# .spc/static-php-cli/downloads/ — re-running is fast.
./bin/spc doctor --auto-fix
./bin/spc download \
  --with-php=8.2 \
  --for-extensions="branchfs,mbstring,mysqli,pdo_mysql,sqlite3,pdo_sqlite,phar,tokenizer,fileinfo,filter,session"

# The slow step — 15-40 minutes on a cold cache. Subsequent builds are
# ~2 min (incremental compile via ccache if present).
./bin/spc build \
  "branchfs,mbstring,mysqli,pdo_mysql,sqlite3,pdo_sqlite,phar,tokenizer,fileinfo,filter,session" \
  --build-cli

cd ../..   # back to branched-wp/
```

Sanity-check the resulting PHP:

```bash
./.spc/static-php-cli/buildroot/bin/php --version
./.spc/static-php-cli/buildroot/bin/php -m | grep -i branchfs
./.spc/static-php-cli/buildroot/bin/php -r \
  'var_dump(extension_loaded("branchfs"));'     # → bool(true), NO -d extension= flag
```

If `branchfs` isn't in `-m`, the integrate.sh step didn't take — re-run
step 2 and try step 3 again. Open an issue against the repo if it
persistently fails.

### 4. Fetch the matching Dolt binary

```bash
# Linux x86_64:
curl -fsSL https://github.com/dolthub/dolt/releases/latest/download/dolt-linux-amd64.tar.gz \
  -o /tmp/dolt.tgz
# macOS aarch64:
# curl -fsSL https://github.com/dolthub/dolt/releases/latest/download/dolt-darwin-arm64.tar.gz \
#   -o /tmp/dolt.tgz
# macOS x86_64:
# curl -fsSL https://github.com/dolthub/dolt/releases/latest/download/dolt-darwin-amd64.tar.gz \
#   -o /tmp/dolt.tgz

mkdir -p /tmp/dolt-unpack
tar -xzf /tmp/dolt.tgz -C /tmp/dolt-unpack
# Archive contains <archive>/bin/dolt — copy it out.
```

### 5. Stage runtime/

```bash
mkdir -p runtime/bin runtime/lib
cp .spc/static-php-cli/buildroot/bin/php runtime/bin/php
chmod +x runtime/bin/php
# Paste the right dolt path for your platform here:
cp /tmp/dolt-unpack/*/bin/dolt runtime/bin/dolt
chmod +x runtime/bin/dolt
# No runtime/lib/branchfs.so — branchfs is compiled into php.
```

### 6. Build gitpress

```bash
# On Linux (static musl):
export RUST_TARGET="$(uname -m)-unknown-linux-musl"
# On macOS (native):
# export RUST_TARGET="$(uname -m)-apple-darwin"

GITPRESS_RUNTIME_DIR="$PWD/runtime" \
  cargo build --release -p gitpress --target "$RUST_TARGET"
```

### 7. Package the release tarball

```bash
VERSION="0.1.0-rc1"       # or whatever you're cutting
ARCH="$(uname -m)"
# Map host OS to the release naming convention:
case "$(uname -s)" in
  Linux)  OS=linux ;;
  Darwin) OS=macos ;;
  *) echo "unsupported host"; exit 1 ;;
esac
NAME="gitpress-${VERSION}-${OS}-${ARCH}"

STAGE="/tmp/${NAME}"
rm -rf "$STAGE"
mkdir -p "$STAGE"
cp "target/${RUST_TARGET}/release/gitpress" "$STAGE/gitpress"
chmod +x "$STAGE/gitpress"
cat > "$STAGE/README.txt" <<EOF
gitpress ${VERSION} — ${OS}-${ARCH} (statically linked)

Single self-contained binary. Extracts its embedded runtime (static PHP
+ Dolt + WordPress + branchfs + SQLite integration plugin) to
~/.gitpress/runtime/ on first run.

Usage:
  ./gitpress start                  # launch dev stack on :18080
  ./gitpress branch create <name>   # create a preview branch
  ./gitpress branch list
  ./gitpress --help

Homepage: http://localhost:18080/ (Host: wp.localhost)
Admin:    http://localhost:18080/wp-login.php  (admin / admin)

Full docs: https://github.com/adamziel/experiments/tree/main/branched-wp
EOF

mkdir -p dist
tar -C /tmp -czf "dist/${NAME}.tar.gz" "$NAME"
(cd dist && shasum -a 256 "${NAME}.tar.gz" > "SHA256SUMS.${NAME}.txt")

echo "Built: dist/${NAME}.tar.gz"
ls -lh "dist/${NAME}.tar.gz"
```

### 8. Verify (optional but recommended)

Extract the tarball somewhere clean and smoke-test:

```bash
mkdir -p /tmp/verify && cd /tmp/verify
tar xzf "/path/to/dist/${NAME}.tar.gz"
./${NAME}/gitpress --help
./${NAME}/gitpress start &
# Wait ~30s for the runtime to extract on first run, then:
curl -sf -H "Host: wp.localhost" http://127.0.0.1:18080/ | grep -oE '<title>[^<]+</title>'
# Expect: <title>Branched WP Dev</title>
kill %1
```

## Disk + time budget

- Cold `spc build`: 15–40 min, depending on CPU. 4–8 GB of peak disk
  under `.spc/static-php-cli/{downloads,source,buildroot}`.
- Cargo build: ~1 min cold, ~10 s incremental.
- Total: **~20–45 min cold, ~3 min warm**.
- Resulting tarball: **~100 MB**. The `gitpress` binary embeds the whole
  runtime via `include_bytes!`; the tarball is just the binary +
  README.

After a successful build you can safely delete
`.spc/static-php-cli/source/` and `.spc/static-php-cli/downloads/`
(~2–3 GB) if disk is tight. Keep `.spc/static-php-cli/buildroot/` if
you want warm-cache rebuilds.

## Troubleshooting

### spc `doctor` fails on NixOS

NixOS can't install packages system-wide via `apt`/`brew`. Run the
build inside a `nix-shell` that already has the toolchain:

```bash
nix-shell -p gcc autoconf automake libtool pkg-config php82 composer git curl jq python3
```

Then the `doctor --auto-fix` step will still try to run but will only
patch things that work on NixOS (e.g. it won't try `apt install`).

### `branchfs` missing from `php -m`

Either `integrate.sh` didn't run against the right spc checkout, or
the SPC_BRANCHFS_SOURCE env var wasn't set when you ran `spc build`.
Both are required. Re-run step 2, then re-run step 3 with the env var
exported.

### macOS: linker errors about missing `-liconv`, `-lresolv`, etc.

spc's build matrix usually handles these; if you hit one, run
`./bin/spc doctor --auto-fix` again to pull in whatever it's missing.

### Disk fills up during build

Between attempts, run:
```bash
rm -rf .spc/static-php-cli/source .spc/static-php-cli/downloads
```
to reclaim ~2–3 GB. `buildroot/` has the final binary; don't touch it
unless you want a full rebuild.

## Shipping your build

Once `dist/${NAME}.tar.gz` exists:

1. Upload it as an asset on an existing GitHub Release (typically the
   release for the tag that matches `VERSION`):
   ```bash
   gh release upload "v${VERSION}" \
     "dist/${NAME}.tar.gz" "dist/SHA256SUMS.${NAME}.txt" \
     --repo adamziel/experiments
   ```
2. Append your `SHA256SUMS.${NAME}.txt` contents to the aggregated
   `SHA256SUMS` file on the Release if one exists, or upload it
   alongside as its own file.
3. Post a comment on the originating PR (or the Release discussion)
   with the platform you built for, the spc ref, and the git SHA —
   otherwise no one can reproduce your artifact.
