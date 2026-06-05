#!/bin/sh
set -eu

PLUGIN_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
SUDOERS_PATH="/etc/sudoers.d/wp-oneclick-installer"

mkdir -p "$PLUGIN_ROOT/data/users"
chmod 1777 "$PLUGIN_ROOT/data/users"

find "$PLUGIN_ROOT/user" -type f -exec chmod 755 {} \;
find "$PLUGIN_ROOT/scripts" -type f -exec chmod 755 {} \;

cat > "$SUDOERS_PATH" <<EOF
# Allow DirectAdmin users to run the WP OneClick helper without a password.
ALL ALL=(root) NOPASSWD: $PLUGIN_ROOT/scripts/da_helper.sh
Defaults!$PLUGIN_ROOT/scripts/da_helper.sh !requiretty
EOF
chmod 440 "$SUDOERS_PATH"

exit 0
