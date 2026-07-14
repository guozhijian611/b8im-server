#!/bin/sh
# Server 容器入口：默认启动前自动执行 Phinx 迁移，再启动 webman。
# 关闭自动迁移：RUN_MIGRATIONS_ON_START=0
set -eu

cd /app

if [ "${RUN_MIGRATIONS_ON_START:-1}" = "1" ]; then
  echo "[entrypoint] running phinx migrate..."
  php webman phinx:migrate --no-interaction
  echo "[entrypoint] phinx migrate done"
else
  echo "[entrypoint] RUN_MIGRATIONS_ON_START=0, skip migrate"
fi

if [ "$#" -eq 0 ]; then
  set -- php start.php start
fi

exec "$@"
