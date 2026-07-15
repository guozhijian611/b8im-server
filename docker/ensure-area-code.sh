#!/bin/sh
# 幂等初始化中国地区编码表：完整时跳过，缺失或不完整时从镜像内 SQL 重建。
set -eu

EXPECTED_ROWS=665552
ARCHIVE_PATH="${AREA_CODE_SQL_ARCHIVE:-/app/db/area_code.sql.gz}"
MARIADB_BIN="${MARIADB_BIN:-mariadb}"
GZIP_BIN="${GZIP_BIN:-gzip}"

require_env() {
  variable="$1"
  value="$2"
  if [ -z "$value" ]; then
    echo "[area-code] required environment variable is missing: $variable" >&2
    exit 1
  fi
}

require_env DB_HOST "${DB_HOST:-}"
require_env DB_NAME "${DB_NAME:-}"
require_env DB_USER "${DB_USER:-}"
require_env DB_PASSWORD "${DB_PASSWORD:-}"

case "$DB_NAME" in
  *[!a-zA-Z0-9_]*)
    echo "[area-code] DB_NAME contains unsupported characters" >&2
    exit 1
    ;;
esac

command -v "$MARIADB_BIN" >/dev/null 2>&1 || {
  echo "[area-code] mariadb client is missing" >&2
  exit 1
}
command -v "$GZIP_BIN" >/dev/null 2>&1 || {
  echo "[area-code] gzip is missing" >&2
  exit 1
}
[ -f "$ARCHIVE_PATH" ] || {
  echo "[area-code] SQL archive is missing: $ARCHIVE_PATH" >&2
  exit 1
}

db_query() {
  MYSQL_PWD="$DB_PASSWORD" "$MARIADB_BIN" \
    --protocol=tcp \
    --host="$DB_HOST" \
    --port="${DB_PORT:-3306}" \
    --user="$DB_USER" \
    --batch \
    --skip-column-names \
    "$DB_NAME" \
    "$@"
}

table_exists="$(db_query --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sm_area_code'")"
current_rows=0
if [ "$table_exists" = "1" ]; then
  current_rows="$(db_query --execute='SELECT COUNT(*) FROM sm_area_code')"
fi

if [ "$current_rows" = "$EXPECTED_ROWS" ]; then
  echo "[area-code] sm_area_code already complete ($EXPECTED_ROWS rows)"
  exit 0
fi

echo "[area-code] rebuilding sm_area_code (current=$current_rows expected=$EXPECTED_ROWS)"
"$GZIP_BIN" -t "$ARCHIVE_PATH"
{
  printf '%s\n' 'SET autocommit=0; SET unique_checks=0; SET foreign_key_checks=0;'
  "$GZIP_BIN" -dc "$ARCHIVE_PATH"
  printf '%s\n' 'COMMIT; SET foreign_key_checks=1; SET unique_checks=1;'
} | db_query --binary-mode=1

imported_rows="$(db_query --execute='SELECT COUNT(*) FROM sm_area_code')"
if [ "$imported_rows" != "$EXPECTED_ROWS" ]; then
  echo "[area-code] row verification failed (actual=$imported_rows expected=$EXPECTED_ROWS)" >&2
  exit 1
fi

echo "[area-code] sm_area_code initialized ($imported_rows rows)"
