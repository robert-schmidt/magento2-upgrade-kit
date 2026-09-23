#!/usr/bin/env bash
# STEP 20 - 2.4.7-p10 -> 2.4.8-pN, PHP 8.3 -> 8.4, MySQL 8.0 -> 8.4.
# Precondition: step 10 released; `composer install` done on the current lock; clean git tree.
# Changes composer.json/lock, config.php, custom code (safe autofixes), Dockerfile/.docker. Commit after review.
source "$(dirname "$0")/_lib.sh"
need_clean
[[ "$(meta_version)" == 2.4.7* ]] || { echo "expected 2.4.7-x, found $(meta_version)" >&2; exit 1; }
P=$(paths)
"$KIT/bin/tools.sh" >/dev/null

snapshot_vendor

say "composer.json -> $M248_VERSION / PHP ~8.4.0"
php "$KIT/bin/composer-sync.php" composer.json "$M248_VERSION" "~8.4.0" | tee "$REPORT_DIR/20-composer-sync.txt"
composer_update "$M248_VERSION"

say "app/etc/config.php"
php "$KIT/bin/config-php.php" .

say "infra: PHP 8.4"
php "$KIT/bin/infra.php" "$REPO_ROOT" 8.4 2.4.8 | tee "$REPORT_DIR/20-infra.txt" | tail -3

say "code: Rector php84 set (runtime is 8.4 from now on) + 2.4.8 autofixes"
KIT_RECTOR_STAGE=php84 "$TOOLS_DIR/rector/vendor/bin/rector" process $P --config "$KIT/tools/rector.php" --no-progress-bar | tail -3 || true
php "$KIT/bin/scan.php" --fix "$KIT/rules/m248.php" $P > "$REPORT_DIR/20-scan-m248.txt" || true
php "$KIT/bin/scan.php" --fix "$KIT/rules/php.php" $P > "$REPORT_DIR/20-scan-php.txt" || true
sed -n '/==== summary/,$p' "$REPORT_DIR/20-scan-m248.txt"

say "core forks + customized core files vs new vendor"
php "$KIT/bin/core-forks.php" . "$REPORT_DIR/20-forks" || true

say "lint on PHP 8.4 (custom code + project-only vendor packages)"
CUSTOM_PATHS="$P $(project_vendor_dirs)" "$KIT/bin/lint.sh" 8.4 "$REPORT_DIR/20-lint-8.4.txt"
php "$KIT/bin/lint-fix.php" "$REPORT_DIR/20-lint-8.4.txt" "$MAGENTO_ROOT"

run_uph_hint 248

cat <<EOF

NEXT (manual, in this order):
 1. Review: git diff ; $REPORT_DIR/20-*.txt  (fatal = breaks for sure, review = check by hand)
    - 20-forks/SUMMARY.txt: copy reviewed *.merged over the fork files
    - Monolog: m248.monolog.record-write items need \$record = \$record->with(...), not \$record['x'] = ...
 2. In a PHP 8.4 container with the new vendor:
      bin/magento setup:di:compile            # signature mismatches vs new core show up here
      bin/magento setup:upgrade --keep-generated  (against a PITR copy of prod on MySQL 8.4, see README)
 3. Paste back any error you get; rules get extended.
EOF
