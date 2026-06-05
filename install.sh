#!/bin/sh
set -eu

PLUGIN_NAME="wp_oneclick_installer"
SRC_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
TARGET_DIR="/usr/local/directadmin/plugins/$PLUGIN_NAME"
ARCHIVE_PATH="${SRC_DIR}/${PLUGIN_NAME}.tar.gz"

usage() {
  echo "Usage:"
  echo "  $0 package"
  echo "  $0 install"
  echo "  $0 uninstall"
}

package_plugin() {
  tar -czf "$ARCHIVE_PATH" \
    --exclude="./${PLUGIN_NAME}.tar.gz" \
    --exclude="./.git" \
    -C "$SRC_DIR" .
  echo "Created package: $ARCHIVE_PATH"
}

install_plugin() {
  mkdir -p "$TARGET_DIR"
  rsync -a --delete "$SRC_DIR"/ "$TARGET_DIR"/
  sh "$TARGET_DIR/scripts/install.sh"
  echo "Installed plugin to $TARGET_DIR"
}

uninstall_plugin() {
  rm -rf "$TARGET_DIR"
  echo "Removed $TARGET_DIR"
}

case "${1:-}" in
  package) package_plugin ;;
  install) install_plugin ;;
  uninstall) uninstall_plugin ;;
  *) usage; exit 1 ;;
esac
