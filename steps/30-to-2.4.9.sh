#!/usr/bin/env bash
# STEP 30 - 2.4.8-pN -> 2.4.9, PHP 8.4 -> 8.5. Same shape as step 20.
# Precondition: step 20 deployed and stable; `composer install` done; clean git tree.
source "$(dirname "$0")/_lib.sh"
need_clean
[[ "$(meta_version)" == 2.4.8* ]] || { echo "expected 2.4.8-x, found $(meta_version)" >&2; exit 1; }
P=$(paths)
"$KIT/bin/tools.sh" >/dev/null

snapshot_vendor

say "composer.json -> $M249_VERSION / PHP ~8.5.0"
php "$KIT/bin/composer-sync.php" composer.json "$M249_VERSION" "~8.5.0" | tee "$REPORT_DIR/30-composer-sync.txt"
composer_update "$M249_VERSION"

say "app/etc/config.php"
php "$KIT/bin/config-php.php" .

say "infra: PHP 8.5 + 2.4.9 (opcache pkg, PDO attr ints in env.php, cache backend alias)"
php "$KIT/bin/infra.php" "$REPO_ROOT" 8.5 2.4.9 | tee "$REPORT_DIR/30-infra.txt" | tail -3

say "code: Rector php85 set + 2.4.9 autofixes"
KIT_RECTOR_STAGE=php85 "$TOOLS_DIR/rector/vendor/bin/rector" process $P --config "$KIT/tools/rector.php" --no-progress-bar | tail -3 || true
php "$KIT/bin/scan.php" --fix "$KIT/rules/m249.php" $P > "$REPORT_DIR/30-scan-m249.txt" || true
php "$KIT/bin/scan.php" --fix "$KIT/rules/php.php" $P > "$REPORT_DIR/30-scan-php.txt" || true
sed -n '/==== summary/,$p' "$REPORT_DIR/30-scan-m249.txt"

say "core forks + customized core files vs new vendor"
php "$KIT/bin/core-forks.php" . "$REPORT_DIR/30-forks" || true

say "lint on PHP 8.5 (custom code + project-only vendor packages)"
CUSTOM_PATHS="$P $(project_vendor_dirs)" "$KIT/bin/lint.sh" 8.5 "$REPORT_DIR/30-lint-8.5.txt"
php "$KIT/bin/lint-fix.php" "$REPORT_DIR/30-lint-8.5.txt" "$MAGENTO_ROOT"

run_uph_hint 249

cat <<EOF

NEXT (manual):
 1. Review git diff + $REPORT_DIR/30-*.txt. Laminas/Zend_Cache/oauth hits are fatal once vendor no longer ships them:
      grep the new vendor:  ls vendor/laminas ; ls vendor/magento/zend-cache 2>/dev/null
 2. PHP 8.5 container: bin/magento setup:di:compile && bin/magento setup:upgrade --keep-generated
 3. After deploy verify: cache really in Redis (var/cache/symfony must stay empty, magento/magento2#41213),
    DB SSL works (env.php driver_options), admin tax rate / design config / PDF print pages (known 8.5 null-offset bugs).
EOF
