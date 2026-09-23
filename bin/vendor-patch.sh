#!/usr/bin/env bash
# Generates a cweagans/composer-patches patch that applies the kit's PHP 8.4/8.5-safe fixes
# (explicit nullable, CSV escape, casts, *_close(), ...) to a THIRD-PARTY vendor package you can't upgrade
# (typically abandoned packages). Under Magento even vendor deprecations throw, so this is sometimes the only way.
#
#   bin/vendor-patch.sh <vendor/package> [<vendor/package> ...]
# Writes $PATCH_DIR/<vendor>-<package>-php8x.patch and prints the composer.json "extra.patches" entries.
set -euo pipefail
source "$(dirname "$0")/../kit.env"
cd "$MAGENTO_ROOT"
PATCH_DIR="${PATCH_DIR:-patches/composer}"
"$KIT_DIR/bin/tools.sh" >/dev/null
mkdir -p "$PATCH_DIR"
entries=()
for pkg in "$@"; do
  [[ -d "vendor/$pkg" ]] || { echo "vendor/$pkg not installed" >&2; exit 1; }
  tmp="$(mktemp -d)"
  cp -a "vendor/$pkg" "$tmp/a"; cp -a "vendor/$pkg" "$tmp/b"
  KIT_RECTOR_STAGE=safe "$TOOLS_DIR/rector/vendor/bin/rector" process "$tmp/b" --config "$KIT_DIR/tools/rector.php" --no-progress-bar >/dev/null
  php "$KIT_DIR/bin/scan.php" --fix "$KIT_DIR/rules/php.php" "$tmp/b" >/dev/null || true
  MAGENTO_ROOT="$tmp" CUSTOM_PATHS=b "$KIT_DIR/bin/lint.sh" "${LINT_PHP:-8.5}" "$tmp/lint.txt" 2>/dev/null
  php "$KIT_DIR/bin/lint-fix.php" "$tmp/lint.txt" "$tmp" >/dev/null
  rm -f "$tmp/lint.txt"
  out="$PATCH_DIR/$(echo "$pkg" | tr '/' '-')-php8x.patch"
  (cd "$tmp" && git diff --no-index --no-prefix --src-prefix=a/ --dst-prefix=b/ a b || true) \
    | sed -E 's#^(---|\+\+\+) (a|b)/(a|b)/#\1 \2/#; s#^diff --git a/a/(.*) b/b/(.*)#diff --git a/\1 b/\2#' > "$out"
  rm -rf "$tmp"
  if [[ -s "$out" ]]; then
    echo "$pkg: $(grep -c '^+++ ' "$out") files -> $out"
    entries+=("        \"$pkg\": { \"PHP 8.4/8.5 deprecation fixes (upgrade-kit)\": \"$out\" }")
  else
    rm -f "$out"; echo "$pkg: nothing to fix"
  fi
done
if ((${#entries[@]})); then
  echo; echo "Add to composer.json (requires cweagans/composer-patches; set \"composer-exit-on-patch-failure\": true):"
  echo '    "extra": { "patches": {'
  for i in "${!entries[@]}"; do printf '%s%s\n' "${entries[$i]}" "$([[ $i -lt $((${#entries[@]} - 1)) ]] && echo ,)"; done
  echo '    } }'
fi
