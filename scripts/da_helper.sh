#!/bin/sh
set -eu

DIRECTADMIN_BIN="/usr/local/directadmin/directadmin"
CURL_BIN="/usr/bin/curl"
MYSQL_DEFAULTS="/usr/local/directadmin/conf/my.cnf"
MYSQL_CONF="/usr/local/directadmin/conf/mysql.conf"
MYSQL_BIN="/usr/bin/mysql"
if [ ! -x "$CURL_BIN" ]; then
  CURL_BIN="/bin/curl"
fi
if [ ! -x "$MYSQL_BIN" ]; then
  MYSQL_BIN="/bin/mysql"
fi

json_error() {
  printf '{"ok":false,"error":"%s"}\n' "$1"
  exit 1
}

require_valid_user() {
  case "$1" in
    ""|*[!A-Za-z0-9_.-]*)
      json_error "invalid_user"
      ;;
  esac
}

require_valid_name() {
  case "$1" in
    ""|*[!A-Za-z0-9_]*)
      json_error "invalid_name"
      ;;
  esac
}

if [ ! -f "$DIRECTADMIN_BIN" ]; then
  json_error "directadmin_binary_missing"
fi

action="${1:-}"
user="${2:-}"
require_valid_user "$user"
caller="${SUDO_USER:-}"
require_valid_user "$caller"
[ "$caller" = "$user" ] || json_error "caller_user_mismatch"

sql_escape() {
  printf "%s" "$1" | sed "s/'/''/g"
}

case "$action" in
  list-domains)
    domains_file="/usr/local/directadmin/data/users/$user/domains.list"
    if [ ! -r "$domains_file" ]; then
      json_error "domains_file_unreadable"
    fi

    printf '{"ok":true,"domains":['
    first=1
    while IFS= read -r domain; do
      [ -n "$domain" ] || continue
      case "$domain" in
        *[!A-Za-z0-9.-]*)
          continue
          ;;
      esac
      if [ "$first" -eq 0 ]; then
        printf ','
      fi
      first=0
      printf '"%s"' "$domain"
    done < "$domains_file"
    printf ']}\n'
    ;;
  create-database)
    database="${3:-}"
    db_user="${4:-}"
    db_password="${5:-}"
    require_valid_name "$database"
    require_valid_name "$db_user"
    [ -n "$db_password" ] || json_error "empty_password"
    [ -r "$MYSQL_DEFAULTS" ] || json_error "mysql_defaults_unreadable"
    [ -x "$MYSQL_BIN" ] || json_error "mysql_binary_missing"

    db_sql_name="$(sql_escape "$database")"
    db_sql_user="$(sql_escape "$db_user")"
    db_sql_pass="$(sql_escape "$db_password")"

    sql="CREATE DATABASE IF NOT EXISTS \`$database\`;
CREATE USER IF NOT EXISTS '$db_sql_user'@'localhost' IDENTIFIED BY '$db_sql_pass';
GRANT ALL PRIVILEGES ON \`$database\`.* TO '$db_sql_user'@'localhost';
FLUSH PRIVILEGES;"

    body="$("$MYSQL_BIN" --defaults-extra-file="$MYSQL_DEFAULTS" -NBe "$sql" 2>&1 || true)"
    if [ -n "$body" ]; then
      escaped_body=$(printf '%s' "$body" | tr '\n' ' ' | sed 's/"/\\"/g')
      printf '{"ok":false,"error":"%s"}\n' "$escaped_body"
      exit 1
    fi

    printf '{"ok":true,"db_name":"%s","db_user":"%s"}\n' "$database" "$db_user"
    ;;
  *)
    json_error "unsupported_action"
    ;;
esac
