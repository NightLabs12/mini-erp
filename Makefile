SHELL := /bin/sh

# Variabel lokal (port, jumlah worker test) dibaca dari .env yang tidak masuk
# repo. Default di bawah menjaga `make` tetap jalan di CI dan mesin baru.
-include .env
COMPOSE_PROJECT_NAME ?= minierp-dev
APP_PORT ?= 8100
FRONTEND_PORT ?= 5173
POSTGRES_PORT ?= 5433
SEQUENCE_CONCURRENCY ?= 50
export COMPOSE_PROJECT_NAME APP_PORT FRONTEND_PORT POSTGRES_PORT SEQUENCE_CONCURRENCY

# `-p` mengunci proyek Compose: perintah make tidak pernah menyentuh container,
# network, atau volume milik Coolify, Minecraft, atau aplikasi lain di VPS.
COMPOSE := docker compose -p $(COMPOSE_PROJECT_NAME)
BACKEND_SERVICES := postgres app nginx
NICE := nice -n 10
NODE_INSTALL := if [ ! -d node_modules/eslint ]; then npm ci --no-audit --no-fund; fi

.DEFAULT_GOAL := help

.PHONY: help demo up down build install migrate seed demo-reset test lint lint-php pint stan \
	fe-up fe-lint fe-typecheck fe-build logs sh psql smoke

help:
	@echo "Mini ERP"
	@echo ""
	@echo "  make demo        Nyalakan postgres+app+nginx, migrasi, dan seed data demo"
	@echo "  make up          Nyalakan layanan backend (tanpa migrasi)"
	@echo "  make down        Hentikan dan hapus container proyek ini"
	@echo "  make migrate     Jalankan migrasi database"
	@echo "  make seed        Isi database dengan data demo"
	@echo "  make demo-reset  Bangun ulang dataset demo dari nol"
	@echo "  make test        Jalankan seluruh test backend di container"
	@echo "  make lint        Pint --test, Larastan, ESLint, dan typecheck"
	@echo "  make pint        Perbaiki format kode PHP"
	@echo "  make stan        Jalankan Larastan level 6 (memory limit 1G)"
	@echo "  make fe-up       Nyalakan Vite dev server (profil frontend, opsional)"
	@echo "  make fe-build    Build frontend produksi"
	@echo "  make smoke       Tiru job CI docker-smoke: postgres+app+nginx saja, tanpa bind mount"
	@echo "  make logs        Ikuti log semua layanan"
	@echo "  make sh          Masuk ke shell container app"
	@echo "  make psql        Masuk ke psql database"

demo:
	$(NICE) $(COMPOSE) up -d --build --wait --wait-timeout 300 $(BACKEND_SERVICES)
	$(NICE) $(COMPOSE) exec -T app php artisan migrate --force --seed
	@echo ""
	@echo "Mini ERP siap:"
	@echo "  API      http://localhost:$(APP_PORT)/api/v1/health"
	@echo "  OpenAPI  http://localhost:$(APP_PORT)/docs/api"
	@echo "  Frontend opsional: make fe-up (http://localhost:$(FRONTEND_PORT))"

up:
	$(NICE) $(COMPOSE) up -d --build --wait --wait-timeout 300 $(BACKEND_SERVICES)

down:
	$(COMPOSE) --profile frontend down

build:
	$(NICE) $(COMPOSE) build

install:
	$(NICE) $(COMPOSE) exec -T app composer install --no-interaction --prefer-dist

migrate:
	$(NICE) $(COMPOSE) exec -T app php artisan migrate --force

seed:
	$(NICE) $(COMPOSE) exec -T app php artisan db:seed --force

demo-reset:
	$(NICE) $(COMPOSE) exec -T app php artisan demo:reset --force

test:
	$(NICE) $(COMPOSE) exec -T app nice -n 10 ./vendor/bin/pest

pint:
	$(NICE) $(COMPOSE) exec -T app ./vendor/bin/pint

stan:
	$(NICE) $(COMPOSE) exec -T app nice -n 10 ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

lint: lint-php fe-lint fe-typecheck

lint-php:
	$(COMPOSE) exec -T app ./vendor/bin/pint --test
	$(NICE) $(COMPOSE) exec -T app nice -n 10 ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

fe-up:
	$(NICE) $(COMPOSE) --profile frontend up -d --wait node

fe-lint:
	$(COMPOSE) --profile frontend run --rm --no-deps node sh -c '$(NODE_INSTALL); npm run lint'

fe-typecheck:
	$(COMPOSE) --profile frontend run --rm --no-deps node sh -c '$(NODE_INSTALL); npm run typecheck'

fe-build:
	$(NICE) $(COMPOSE) --profile frontend run --rm --no-deps node sh -c '$(NODE_INSTALL); npm run build'

# Meniru job CI `docker-smoke`: hanya docker-compose.yml (tanpa bind mount),
# hanya layanan yang dibutuhkan API, lalu migrasi, cek health, dan bersihkan.
# Container `node` sengaja tidak dinyalakan; frontend diverifikasi oleh lint,
# typecheck, dan build.
SMOKE_COMPOSE := $(COMPOSE) -f docker-compose.yml
SMOKE_SERVICES := postgres app nginx

smoke:
	$(NICE) $(SMOKE_COMPOSE) build
	$(NICE) $(SMOKE_COMPOSE) up -d --wait --wait-timeout 180 $(SMOKE_SERVICES)
	$(SMOKE_COMPOSE) exec -T app php artisan migrate --force --no-interaction
	@curl -fsS http://127.0.0.1:$(APP_PORT)/api/v1/health
	@echo ""
	$(SMOKE_COMPOSE) down -v --remove-orphans

logs:
	$(COMPOSE) logs -f

sh:
	$(COMPOSE) exec app sh

psql:
	$(COMPOSE) exec postgres psql -U $${DB_USERNAME:-mini_erp} -d $${DB_DATABASE:-mini_erp}
