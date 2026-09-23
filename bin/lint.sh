#!/usr/bin/env bash
# Lint custom code with a given PHP version; keeps only deprecations/errors (compile-time ones).
#   bin/lint.sh <php-version e.g. 8.3|8.4|8.5> [out-file]
# Uses $PHP_BIN if set (must BE that version), otherwise docker image php:<ver>-cli.
# One php process per file (a compile fatal must not hide the rest), run in parallel.
set -euo pipefail
source "$(dirname "$0")/../kit.env"
VER="${1:?php version}"; OUT="${2:-$REPORT_DIR/lint-$VER.txt}"
mkdir -p "$(dirname "$OUT")"
cd "$MAGENTO_ROOT"

# shellcheck disable=SC2016
SCRIPT='find "$@" -type f \( -name "*.php" -o -name "*.phtml" \) -not -path "*/Test/*" -not -path "*/tests/*" -print0 2>/dev/null \
  | xargs -0 -n1 -P"$(nproc 2>/dev/null || sysctl -n hw.ncpu)" sh -c '"'"'o=$("$PHPX" -d error_reporting=E_ALL -d display_errors=stderr -d log_errors=0 -l "$1" 2>&1); printf "%s\n" "$o"'"'"' _ \
  | grep -E "^(PHP )?(Deprecated|Fatal error|Parse error|Warning):" | sed -E "s/^PHP //" || true'

EXISTING=(); for p in $CUSTOM_PATHS; do if [[ -e $p ]]; then EXISTING+=("$p"); fi; done
if [[ -n "${PHP_BIN:-}" ]]; then
  PHPX="$PHP_BIN" bash -c "$SCRIPT" _ "${EXISTING[@]}"
else
  docker run --rm -v "$PWD:/app" -w /app -e PHPX=php "php:${VER}-cli" bash -c "$SCRIPT" _ "${EXISTING[@]}"
fi | sort -u > "$OUT"

echo "PHP $VER lint: $(grep -c . "$OUT" || true) issues -> $OUT" >&2
# summary by message type (class/method/file stripped)
sed -E 's/^(Deprecated|Fatal error|Parse error|Warning): +([^ ]+\(\): )?/\1: /; s/\$[A-Za-z_0-9]+/$x/g; s/ in [^ ]+ on line [0-9]+$//' "$OUT" | cut -c1-110 | sort | uniq -c | sort -rn >&2 || true
