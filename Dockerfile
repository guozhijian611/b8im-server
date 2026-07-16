# syntax=docker/dockerfile:1.7

FROM hyperf/hyperf:8.3-alpine-v3.22-base@sha256:14f5722ee789e0c6098d97ecc199bde7fc0cde03ed213e711d7d6310a339e622 AS runtime

ARG APP_ENV=production

ENV APP_ENV=${APP_ENV} \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

# PHP 及业务所需扩展由基础镜像预编译提供，应用镜像不编译 PHP 扩展。
RUN apk add --no-cache \
        tzdata \
    && adduser -S -D -H -u 82 -G www-data www-data \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# b8im-server 在开发工作区中使用 Composer path 依赖。
# docker-bake.hcl 通过 named context 提供模块目录。
COPY --from=module-sdk / /b8im-module-sdk
COPY --from=module-i18n / /b8im-module-i18n
COPY --from=module-favorite / /b8im-module-favorite
COPY composer.json composer.lock ./

RUN --mount=type=cache,id=b8im-composer-cache,target=/tmp/composer-cache,sharing=locked \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist

COPY . /app

RUN php docker/check-runtime.php \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p runtime storage public/storage/temp \
    && chown -R www-data:www-data runtime storage public/storage/temp \
    && chmod +x docker/entrypoint.sh docker/ensure-area-code.sh

EXPOSE 8787

# 启动前默认自动迁移并幂等初始化地区编码表。
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php", "start.php", "start"]
