#!/bin/sh
# Server 容器入口：默认启动前自动迁移并初始化必需基础数据。
# 关闭自动迁移：RUN_MIGRATIONS_ON_START=0
# 关闭地区数据初始化：RUN_AREA_CODE_INIT_ON_START=0
set -eu

cd /app

if [ "${RUN_MIGRATIONS_ON_START:-1}" = "1" ]; then
  echo "[entrypoint] running phinx migrate..."
  php webman phinx:migrate --no-interaction
  echo "[entrypoint] phinx migrate done"
else
  echo "[entrypoint] RUN_MIGRATIONS_ON_START=0, skip migrate"
fi

if [ "${RUN_AREA_CODE_INIT_ON_START:-1}" = "1" ]; then
  /app/docker/ensure-area-code.sh
else
  echo "[entrypoint] RUN_AREA_CODE_INIT_ON_START=0, skip area code initialization"
fi

if [ "$#" -eq 0 ]; then
  set -- php start.php start
fi

exec "$@"
