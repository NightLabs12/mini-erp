# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Tahap dasar: PHP 8.3 dengan ekstensi yang dibutuhkan aplikasi.
# Dipakai bersama oleh tahap vendor dan runtime, sehingga Composer memverifikasi
# requirement terhadap platform yang benar-benar sama dengan runtime.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        git \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl zip gd opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-mini-erp.ini

# ---------------------------------------------------------------------------
# Tahap vendor: dependensi Composer dipisah agar layer ini di-cache selama
# composer.json dan composer.lock tidak berubah.
# ---------------------------------------------------------------------------
FROM base AS vendor

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./

RUN composer install \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --no-scripts \
        --no-autoloader

COPY backend/ ./

RUN composer dump-autoload --optimize --no-scripts

# ---------------------------------------------------------------------------
# Tahap runtime: kode dan vendor disalin ke image, sehingga container bisa
# berjalan tanpa bind mount. Pengembangan lokal menambahkan bind mount lewat
# docker-compose.override.yml.
# ---------------------------------------------------------------------------
FROM base

COPY docker/php/entrypoint.sh /usr/local/bin/mini-erp-entrypoint
RUN chmod +x /usr/local/bin/mini-erp-entrypoint

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwX storage bootstrap/cache

ENTRYPOINT ["mini-erp-entrypoint"]
CMD ["php-fpm"]
