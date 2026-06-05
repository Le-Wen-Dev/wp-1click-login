#!/bin/sh
set -eu

PLUGIN_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

mkdir -p "$PLUGIN_ROOT/data/users"
chmod 1777 "$PLUGIN_ROOT/data/users"

find "$PLUGIN_ROOT/user" -type f -exec chmod 755 {} \;
find "$PLUGIN_ROOT/scripts" -type f -exec chmod 755 {} \;

exit 0
