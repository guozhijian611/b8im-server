# syntax=docker/dockerfile:1.7

FROM php:8.3-cli-alpine AS runtime

ARG APP_ENV=production

ENV APP_ENV=${APP_ENV} \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

RUN apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        libzip \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# b8im-server 在开发工作区中使用 Composer path 依赖。
# docker-bake.hcl 通过 module-sdk named context 提供该目录。
COPY --from=module-sdk / /b8im-module-sdk
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
    && chown -R www-data:www-data runtime storage public/storage/temp

EXPOSE 8787

CMD ["php", "start.php", "start"]
