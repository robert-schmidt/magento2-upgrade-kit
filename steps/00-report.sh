#!/usr/bin/env bash
# STEP 00 - detection only, changes nothing. Run first on the up-to-date codebase to size the work,
# and again any time. Output: reports/00-*.txt
source "$(dirname "$0")/_lib.sh"
P=$(paths)
"$KIT/bin/tools.sh" >/dev/null

say "Magento $(meta_version) | custom paths: $P"

say "PHP compile-time deprecations (lint)"
"$KIT/bin/lint.sh" 8.4 "$REPORT_DIR/00-lint-8.4.txt"
"$KIT/bin/lint.sh" 8.5 "$REPORT_DIR/00-lint-8.5.txt"

say "pattern scans"
for r in php m248 m249; do
  php "$KIT/bin/scan.php" "$KIT/rules/$r.php" $P > "$REPORT_DIR/00-scan-$r.txt" || true
  echo "-- $r"; sed -n '/==== summary/,$p' "$REPORT_DIR/00-scan-$r.txt" | tail -n +2
done

say "PHPCompatibility 8.4-8.5 (second opinion, catches runtime-only calls lint can't see)"
"$TOOLS_DIR/phpcompat/vendor/bin/phpcs" -q --standard=PHPCompatibility --runtime-set testVersion 8.4-8.5 \
  --extensions=php,phtml --parallel=8 --exclude=PHPCompatibility.Miscellaneous.RemovedAlternativePHPTags \
  --report=summary --report-file="$REPORT_DIR/00-phpcompat-summary.txt" \
  --report-full="$REPORT_DIR/00-phpcompat-full.txt" $P || true
tail -5 "$REPORT_DIR/00-phpcompat-summary.txt"

say "composer.json changes each version bump would make"
php "$KIT/bin/composer-sync.php" composer.json "$M248_VERSION" "~8.4.0" --dry-run > "$REPORT_DIR/00-composer-$M248_VERSION.txt" || true
php "$KIT/bin/composer-sync.php" composer.json "$M249_VERSION" "~8.5.0" --dry-run > "$REPORT_DIR/00-composer-$M249_VERSION.txt" || true
grep -E 'project pin|CORE FORKS' -A0 "$REPORT_DIR/00-composer-$M248_VERSION.txt" | head -20

say "infra changes (dry run)"
php "$KIT/bin/infra.php" "$REPO_ROOT" 8.5 2.4.9 --dry-run > "$REPORT_DIR/00-infra.txt" || true
tail -2 "$REPORT_DIR/00-infra.txt"

echo; echo "Reports in $REPORT_DIR. DB checks: sql/preflight-mysql84.sql against a PITR copy of prod."
