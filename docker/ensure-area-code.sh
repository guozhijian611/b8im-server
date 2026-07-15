#!/bin/sh
# 幂等初始化中国地区编码表：完整时跳过，缺失或不完整时从镜像内 SQL 重建。
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"

command -v "$PHP_BIN" >/dev/null 2>&1 || {
  echo "[area-code] php CLI is missing" >&2
  exit 1
}

exec "$PHP_BIN" "$SCRIPT_DIR/ensure-area-code.php"
