#!/bin/sh
set -e

cd /var/www/html

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Menulis satu kunci ke .env tanpa peduli isi nilainya (aman untuk karakter
# khusus pada password). Baris lama dibuang, nilai baru ditambahkan di akhir.
set_env() {
    key=$1
    value=$2

    if [ -z "$value" ]; then
        return 0
    fi

    grep -v "^${key}=" .env > .env.tmp || true
    printf '%s=%s\n' "$key" "$value" >> .env.tmp
    mv .env.tmp .env
}

# docker-compose adalah sumber kebenaran untuk koneksi database. Nilai ini
# ditulis eksplisit agar tidak bergantung pada perilaku clear_env php-fpm.
set_env APP_ENV "${APP_ENV:-}"
set_env APP_DEBUG "${APP_DEBUG:-}"
set_env DB_CONNECTION "${DB_CONNECTION:-}"
set_env DB_HOST "${DB_HOST:-}"
set_env DB_PORT "${DB_PORT:-}"
set_env DB_DATABASE "${DB_DATABASE:-}"
set_env DB_USERNAME "${DB_USERNAME:-}"
set_env DB_PASSWORD "${DB_PASSWORD:-}"
set_env DEMO_MODE "${DEMO_MODE:-}"

# Image sudah membawa vendor. Blok ini hanya bekerja saat pengembangan lokal
# memakai bind mount, yang menutupi vendor hasil build.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

# php-fpm menjalankan worker sebagai www-data. Pada bind mount Linux, berkas
# milik user host sehingga worker tidak bisa menulis log dan cache.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
fi

exec "$@"
