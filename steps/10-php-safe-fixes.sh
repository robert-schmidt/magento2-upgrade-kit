#!/usr/bin/env bash
# STEP 10 - run on the CURRENT codebase (2.4.7-p10, PHP 8.3). Ship as its own release before any version bump.
# Applies only rewrites whose output also runs on PHP 8.3:
#   - Rector: explicit nullable params, explicit CSV $escape
#   - scan.php --fix: casts, *_close()/setAccessible() removal, utf8_encode, null offsets, aliases (rules/php.php)
#   - Symfony: execute(): int / isEnabled(): bool on console commands (valid on 6.4, required on 7.4 = 2.4.9)
#   - Monolog handlers are NOT touched here (LogRecord only exists in Monolog 3 = 2.4.8)
#   - lint-driven: "optional parameter before required" left by Rector, bare `return;` in execute(): int
# Then lints with PHP 8.5 and 8.3 and writes what is left to reports/.
source "$(dirname "$0")/_lib.sh"
need_clean
P=$(paths)
"$KIT/bin/tools.sh" >/dev/null

say "rector (safe stage)"
KIT_RECTOR_STAGE=safe "$TOOLS_DIR/rector/vendor/bin/rector" process $P \
  --config "$KIT/tools/rector.php" --no-progress-bar | tail -3

say "pattern fixes"
php "$KIT/bin/scan.php" --fix "$KIT/rules/php.php" $P > "$REPORT_DIR/10-php-rules.txt" || true
php "$KIT/bin/scan.php" --fix --only=m249.symfony.execute-int,m249.symfony.isEnabled-bool "$KIT/rules/m249.php" $P > "$REPORT_DIR/10-symfony.txt" || true
sed -n '/==== summary/,$p' "$REPORT_DIR/10-php-rules.txt"

say "lint 8.5 + lint-driven fixes"
"$KIT/bin/lint.sh" 8.5 "$REPORT_DIR/10-lint-8.5.txt"
php "$KIT/bin/lint-fix.php" "$REPORT_DIR/10-lint-8.5.txt" "$MAGENTO_ROOT" | tee "$REPORT_DIR/10-lint-fix.txt"
"$KIT/bin/lint.sh" 8.5 "$REPORT_DIR/10-lint-8.5-after.txt"
"$KIT/bin/lint.sh" 8.3 "$REPORT_DIR/10-lint-8.3-after.txt"   # must be EMPTY: this release still runs on 8.3

cat <<EOF

Review: git diff --stat ; reports: $REPORT_DIR/10-*.txt
 - 10-lint-8.3-after.txt must be empty (else this release breaks prod on 8.3)
 - 10-lint-8.5-after.txt leftovers = compile-time deprecations that throw under Magento on 8.5
 - 10-php-rules.txt 'deprecated'/'fatal' rows without [autofix] = manual work (runtime-only, lint can't see them)
 - 10-lint-fix.txt 'review:' rows = bare returns changed to 'return 1;'
Then: bin/magento setup:di:compile on PHP 8.3, deploy to staging, smoke test, release.
EOF
