#!/bin/sh
# Entrypoint dev server untuk container `node`.
#
# Memasang dependensi hanya saat volume node_modules masih kosong, lalu
# menjalankan Vite. Dengan begitu restart container tidak mengulang npm install.
set -e

cd /app

if [ ! -d node_modules/vite ]; then
    if [ -f package-lock.json ]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
fi

exec npm run dev -- --host 0.0.0.0 --port 5173
