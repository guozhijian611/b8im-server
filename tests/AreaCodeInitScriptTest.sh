#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/b8im-area-code-test.XXXXXX")"
cleanup() {
  rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

cat >"$TEMP_DIR/php" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >"${AREA_CODE_TEST_ARGS:?}"
EOF
chmod +x "$TEMP_DIR/php"

export AREA_CODE_TEST_ARGS="$TEMP_DIR/args"
PHP_BIN="$TEMP_DIR/php" "$ROOT_DIR/docker/ensure-area-code.sh"
grep -Eq '/docker/ensure-area-code.php$' "$AREA_CODE_TEST_ARGS"
php -l "$ROOT_DIR/docker/ensure-area-code.php" >/dev/null

echo 'area code initialization script tests passed'
