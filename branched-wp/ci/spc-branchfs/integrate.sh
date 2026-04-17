#!/usr/bin/env bash
# Integrate branchfs as a first-class builtin extension into an spc checkout.
#
# Usage:
#   integrate.sh <path-to-static-php-cli>
#
# Idempotent: safe to re-run on a cache hit.
#
# Rather than shipping a .patch file (ext.json is a big JSON doc; a
# line-level diff is fragile when upstream spc bumps), we:
#   1. jq-merge a `branchfs` entry into config/ext.json
#   2. drop Branchfs.php into src/SPC/builder/extension/
#      (spc auto-loads via the #[CustomExt(...)] attribute)
#
# The staging of branchfs.c/.h/config.m4 into source/php-src/ext/branchfs/
# is done by Branchfs.php::patchBeforeBuildconf() at build time, driven by
# the SPC_BRANCHFS_SOURCE env var.

set -euo pipefail

if [ $# -ne 1 ]; then
  echo "usage: $0 <path-to-spc>" >&2
  exit 2
fi

SPC_DIR="$1"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ ! -f "$SPC_DIR/config/ext.json" ]; then
  echo "FATAL: $SPC_DIR does not look like an spc checkout (no config/ext.json)" >&2
  exit 1
fi

# 1) Register branchfs in config/ext.json as a builtin extension that
#    takes --enable-branchfs and depends on the sqlite static library.
#    Using type=builtin tells spc's SourceManager to skip the download
#    lookup (see SourceManager.php line 44).
python3 - "$SPC_DIR/config/ext.json" <<'PY'
import json, sys
path = sys.argv[1]
with open(path) as f:
    data = json.load(f)
data['branchfs'] = {
    "type": "builtin",
    "arg-type": "enable",
    "lib-depends": ["sqlite"],
}
with open(path, 'w') as f:
    json.dump(data, f, indent=4)
    f.write('\n')
print("ext.json: registered 'branchfs'")
PY

# 2) Drop the Builder class. spc discovers it via the #[CustomExt] attr.
install -m 0644 "$HERE/Branchfs.php" "$SPC_DIR/src/SPC/builder/extension/branchfs.php"
echo "src/SPC/builder/extension/branchfs.php: installed"
