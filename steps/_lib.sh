# shellcheck shell=bash
# Shared helpers for steps/*.sh
set -euo pipefail
KIT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$KIT/kit.env"
export MAGENTO_ROOT CUSTOM_PATHS INFRA_PATHS REPORT_DIR TOOLS_DIR M248_VERSION M249_VERSION
cd "$MAGENTO_ROOT"
# git is optional: inside a container /var/www/html is often a volume, not a checkout. Diffs are then reviewed
# on the host (bind-mounted dirs) and the clean-tree gate is skipped with a warning.
HAVE_GIT=0; git rev-parse --show-toplevel >/dev/null 2>&1 && HAVE_GIT=1
REPO_ROOT="${REPO_ROOT:-$([[ $HAVE_GIT == 1 ]] && git rev-parse --show-toplevel || dirname "$MAGENTO_ROOT")}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
mkdir -p "$REPORT_DIR"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
need_clean() {
  [[ $HAVE_GIT == 1 ]] || { echo "WARN: $MAGENTO_ROOT is not a git checkout; cannot verify a clean tree. Commit on the host before running a step." >&2; return 0; }
  git diff --quiet && git diff --cached --quiet || { echo "working tree not clean: commit first, each step must be one reviewable diff" >&2; exit 1; }
}
paths() { for p in $CUSTOM_PATHS; do if [[ -e $p ]]; then printf "%s " "$p"; fi; done; }
meta_version() { php -r '$c=json_decode(file_get_contents("composer.json"),true); echo $c["require"]["magento/product-enterprise-edition"] ?? $c["require"]["magento/product-community-edition"] ?? "";'; }
meta_name() { php -r '$c=json_decode(file_get_contents("composer.json"),true); echo isset($c["require"]["magento/product-enterprise-edition"]) ? "magento/product-enterprise-edition" : "magento/product-community-edition";'; }

# vendor dirs of packages the project added on top of Magento (lint them too: their deprecations throw under Magento as well)
project_vendor_dirs() {
  php -r '
    $c = json_decode(file_get_contents("composer.json"), true);
    $root = json_decode(file_get_contents($argv[1]), true);
    foreach ($c["require"] as $p => $_) if (!isset($root["require"][$p]) && str_contains($p, "/") && !str_starts_with($p, "magento/") && is_dir("vendor/$p")) echo "vendor/$p ";
  ' "$KIT/data/magento2-root-$(meta_version).json" 2>/dev/null || true
}

# keep vendor_orig out of git without touching .gitignore
exclude_vendor_orig() {
  [[ $HAVE_GIT == 1 ]] || return 0
  local ex; ex="$(git rev-parse --git-path info/exclude)"
  local rel; rel="$(git rev-parse --show-prefix)vendor_orig/"
  grep -qxF "/$rel" "$ex" 2>/dev/null || echo "/$rel" >> "$ex"
}

snapshot_vendor() {
  [[ -d vendor/magento/framework ]] || { echo "vendor/ not installed: run '$COMPOSER_BIN install' on the current lock first" >&2; exit 1; }
  say "snapshot vendor -> vendor_orig (for core-forks + upgrade-patch-helper)"
  rm -rf vendor_orig && cp -a vendor vendor_orig
  exclude_vendor_orig
}

composer_update() {
  local target="$1"
  say "composer update (-W) for $target"
  # ext-* are ignored only for lock resolution on this machine; the pipeline's composer install checks the real image
  if ! $COMPOSER_BIN update -W --no-interaction --ignore-platform-req='ext-*' ${COMPOSER_FLAGS:-} 2>&1 | tee "$REPORT_DIR/composer-update-$target.log"; then
    echo "composer update failed. Diagnostics:" >&2
  fi
  local meta; meta="$(meta_name)"
  if ! php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p) if($p["name"]===$argv[2]) exit($p["version"]===$argv[1]?0:1); exit(1);' "$target" "$meta"; then
    $COMPOSER_BIN why-not "$meta" "$target" 2>&1 | tee "$REPORT_DIR/composer-why-not-$target.log" || true
    echo "Fix the blockers above (usually project packages or extensions pinning old laminas/monolog/symfony):" >&2
    echo "  bump them in composer.json, or add them to $KIT/data/overrides-$target.json, then re-run this step." >&2
    echo "  Tip: $COMPOSER_BIN why-not php <target-php e.g. 8.5.0> lists packages capping PHP" >&2
    exit 1
  fi
}

run_uph_hint() {
  if [[ -d "$TOOLS_DIR/uph" ]]; then
    say "upgrade-patch-helper"
    diff -urN vendor_orig/ vendor/ > "$REPORT_DIR/vendor-$1.patch" || true
    echo "vendor diff: $REPORT_DIR/vendor-$1.patch"
    echo "run on an instance with DB (theme resolution):  cp $REPORT_DIR/vendor-$1.patch vendor.patch && php $TOOLS_DIR/uph/bin/patch-helper.php analyse $MAGENTO_ROOT --sort-by-type"
  else
    echo "(optional) bin/tools.sh uph  -> installs convenient/upgrade-patch-helper to check preferences/plugins/template overrides against changed core files"
  fi
}
