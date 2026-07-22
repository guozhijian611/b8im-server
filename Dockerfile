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

COPY composer.json composer.lock ./

RUN --mount=type=cache,id=b8im-composer-cache,target=/tmp/composer-cache,sharing=locked \
    --mount=type=secret,id=b8im_ci_read_token,required=true \
    --mount=type=tmpfs,target=/tmp/composer-home \
    test -s /run/secrets/b8im_ci_read_token \
    && COMPOSER_HOME=/tmp/composer-home \
       COMPOSER_AUTH="$(printf '{"github-oauth":{"github.com":"%s"}}' \
           "$(cat /run/secrets/b8im_ci_read_token)")" \
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
