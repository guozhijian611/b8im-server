#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/b8im-area-code-test.XXXXXX")"
cleanup() {
  rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

printf '%s\n' 'CREATE TABLE sm_area_code (id INT);' | gzip >"$TEMP_DIR/area_code.sql.gz"
printf '0\n' >"$TEMP_DIR/rows"
printf '0\n' >"$TEMP_DIR/imports"

cat >"$TEMP_DIR/mariadb" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
arguments="$*"
if [[ "$arguments" == *"information_schema.tables"* ]]; then
  if [[ "$(cat "$FAKE_ROWS")" == "0" ]]; then printf '0\n'; else printf '1\n'; fi
elif [[ "$arguments" == *"SELECT COUNT(*) FROM sm_area_code"* ]]; then
  cat "$FAKE_ROWS"
else
  cat >/dev/null
  printf '665552\n' >"$FAKE_ROWS"
  imports="$(cat "$FAKE_IMPORTS")"
  printf '%s\n' "$((imports + 1))" >"$FAKE_IMPORTS"
fi
EOF
chmod +x "$TEMP_DIR/mariadb"

export DB_HOST=mysql DB_PORT=3306 DB_NAME=nb8im DB_USER=root DB_PASSWORD=test
export AREA_CODE_SQL_ARCHIVE="$TEMP_DIR/area_code.sql.gz"
export MARIADB_BIN="$TEMP_DIR/mariadb"
export FAKE_ROWS="$TEMP_DIR/rows" FAKE_IMPORTS="$TEMP_DIR/imports"

"$ROOT_DIR/docker/ensure-area-code.sh" >"$TEMP_DIR/first.out"
grep -Fq 'sm_area_code initialized (665552 rows)' "$TEMP_DIR/first.out"
[[ "$(cat "$TEMP_DIR/imports")" == "1" ]]

"$ROOT_DIR/docker/ensure-area-code.sh" >"$TEMP_DIR/second.out"
grep -Fq 'sm_area_code already complete (665552 rows)' "$TEMP_DIR/second.out"
[[ "$(cat "$TEMP_DIR/imports")" == "1" ]]

echo 'area code initialization script tests passed'
