#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
php tests/run-all.php
