#!/bin/sh
set -eu

DIRECTADMIN_BIN="/usr/local/directadmin/directadmin"
CURL_BIN="/usr/bin/curl"
if [ ! -x "$CURL_BIN" ]; then
  CURL_BIN="/bin/curl"
fi

json_error() {
  printf '{"ok":false,"error":"%s"}\n' "$1"
  exit 1
}

extract_api_url() {
  raw="$1"
  printf '%s\n' "$raw" | sed -n 's#.*\(https\{0,1\}://[^[:space:]]*\).*#\1#p' | head -n 1
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

    api_raw="$("$DIRECTADMIN_BIN" api-url --user="$user" 2>/dev/null || true)"
    api_url="$(extract_api_url "$api_raw")"
    [ -n "$api_url" ] || json_error "api_url_failed"

    body="$("$CURL_BIN" -fsSk \
      --data-urlencode "action=create" \
      --data-urlencode "name=$database" \
      --data-urlencode "user=$db_user" \
      --data-urlencode "passwd=$db_password" \
      --data-urlencode "passwd2=$db_password" \
      "${api_url}/CMD_API_DATABASES" 2>&1 || true)"

    case "$body" in
      *"error=0"*|*"\"error\":\"0\""*)
        printf '{"ok":true}\n'
        ;;
      "")
        json_error "empty_directadmin_response"
        ;;
      *)
        escaped_body=$(printf '%s' "$body" | tr '\n' ' ' | sed 's/"/\\"/g')
        printf '{"ok":false,"error":"%s"}\n' "$escaped_body"
        exit 1
        ;;
    esac
    ;;
  *)
    json_error "unsupported_action"
    ;;
esac
