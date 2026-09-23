#!/usr/bin/env bash
# Installs analysis tools into $TOOLS_DIR (isolated composer projects; never touches the Magento composer.json).
#   bin/tools.sh            -> rector + phpcompatibility
#   bin/tools.sh uph        -> also convenient/upgrade-patch-helper (vendor diff vs your overrides)
set -euo pipefail
source "$(dirname "$0")/../kit.env"
mkdir -p "$TOOLS_DIR"
C="composer --no-interaction --quiet"

if [[ ! -x "$TOOLS_DIR/rector/vendor/bin/rector" ]]; then
  mkdir -p "$TOOLS_DIR/rector" && (cd "$TOOLS_DIR/rector" && echo '{}' > composer.json && $C require rector/rector:^2.6)
fi
if [[ ! -x "$TOOLS_DIR/phpcompat/vendor/bin/phpcs" ]]; then
  mkdir -p "$TOOLS_DIR/phpcompat" && (cd "$TOOLS_DIR/phpcompat" \
    && echo '{"config":{"allow-plugins":{"dealerdirect/phpcodesniffer-composer-installer":true}}}' > composer.json \
    && $C require "phpcompatibility/php-compatibility:10.0.0-alpha2" dealerdirect/phpcodesniffer-composer-installer)
fi
if [[ "${1:-}" == "uph" && ! -d "$TOOLS_DIR/uph" ]]; then
  # maintained fork (PHP <=8.5, tested on 2.4.9); original ampersand repo stopped at PHP 8.2
  git clone --depth 1 https://github.com/convenient/convenient-magento2-upgrade-patch-helper "$TOOLS_DIR/uph"
  (cd "$TOOLS_DIR/uph" && $C install)
fi
"$TOOLS_DIR/rector/vendor/bin/rector" --version
"$TOOLS_DIR/phpcompat/vendor/bin/phpcs" --version
