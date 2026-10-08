#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
archive=$(bash "$root/bin/build-zip")
[[ -f $archive ]] || { echo 'No archive produced' >&2; exit 1; }
listing=$(unzip -Z1 "$archive")
# Shipping entry present.
grep -qx 'rendar-prepublish-checks/rendar-prepublish-checks.php' <<<"$listing" || { echo 'Missing plugin entry in zip' >&2; exit 1; }
# Development scaffolding must never be packaged.
if grep -qE 'rendar-prepublish-checks/(bin|tests|docs)/' <<<"$listing"; then
  echo 'Non-shipped path present in zip' >&2; exit 1
fi
echo 'build-zip: excludes bin/tests/docs and includes the plugin'
