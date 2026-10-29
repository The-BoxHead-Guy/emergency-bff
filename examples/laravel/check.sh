#!/usr/bin/env bash
set -euo pipefail

# Lints the reference files. This does not boot Laravel.
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

command -v php >/dev/null || { echo "php is required" >&2; exit 1; }

find "$DIR/app" "$DIR/routes" -name '*.php' -print0 \
  | xargs -0 -n1 php -l

echo "Laravel reference files are syntactically valid."
echo "Copy them into an existing Laravel application; see README.md."
