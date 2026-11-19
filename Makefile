SHELL := /bin/sh
COMPOSE := docker compose

.DEFAULT_GOAL := help

.PHONY: help demo up down build install migrate seed demo-reset test lint pint stan fe-lint fe-build logs sh psql smoke

help:
	@echo "Mini ERP"
	@echo ""
	@echo "  make demo        Nyalakan semua layanan, migrasi, dan seed data demo"
	@echo "  make up          Nyalakan semua layanan (tanpa migrasi)"
	@echo "  make down        Hentikan dan hapus container"
	@echo "  make migrate     Jalankan migrasi database"
	@echo "  make seed        Isi database dengan data demo"
	@echo "  make demo-reset  Bangun ulang dataset demo dari nol"
	@echo "  make test        Jalankan seluruh test backend"
	@echo "  make lint        Pint, Larastan, ESLint, dan typecheck"
	@echo "  make pint        Perbaiki format kode PHP"
	@echo "  make stan        Jalankan Larastan level 6"
	@echo "  make fe-build    Build frontend produksi"
	@echo "  make smoke       Tiru job CI docker-smoke: postgres+app+nginx saja, tanpa bind mount"
	@echo "  make logs        Ikuti log semua layanan"
	@echo "  make sh          Masuk ke shell container app"
	@echo "  make psql        Masuk ke psql database"

demo:
	$(COMPOSE) up -d --build --wait --wait-timeout 300
	$(COMPOSE) exec -T app php artisan migrate --force --seed
	@echo ""
	@echo "Mini ERP siap:"
	@echo "  API      http://localhost:8000/api/v1/health"
	@echo "  OpenAPI  http://localhost:8000/docs/api"
	@echo "  Frontend http://localhost:5173"

up:
	$(COMPOSE) up -d --build --wait --wait-timeout 300

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build

install:
	$(COMPOSE) exec -T app composer install --no-interaction --prefer-dist

migrate:
	$(COMPOSE) exec -T app php artisan migrate --force

seed:
	$(COMPOSE) exec -T app php artisan db:seed --force

demo-reset:
	$(COMPOSE) exec -T app php artisan demo:reset --force

test:
	$(COMPOSE) exec -T app ./vendor/bin/pest

pint:
	$(COMPOSE) exec -T app ./vendor/bin/pint

stan:
	$(COMPOSE) exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

lint:
	$(COMPOSE) exec -T app ./vendor/bin/pint --test
	$(COMPOSE) exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(COMPOSE) exec -T node npm run lint
	$(COMPOSE) exec -T node npm run typecheck

fe-lint:
	$(COMPOSE) exec -T node npm run lint

fe-build:
	$(COMPOSE) exec -T node npm run build

# Meniru job CI `docker-smoke`: hanya docker-compose.yml (tanpa bind mount),
# hanya layanan yang dibutuhkan API, lalu migrasi, cek health, dan bersihkan.
# Container `node` sengaja tidak dinyalakan; frontend diverifikasi oleh lint,
# typecheck, dan build.
SMOKE_COMPOSE := $(COMPOSE) -f docker-compose.yml
SMOKE_SERVICES := postgres app nginx

smoke:
	$(SMOKE_COMPOSE) build
	$(SMOKE_COMPOSE) up -d --wait --wait-timeout 180 $(SMOKE_SERVICES)
	$(SMOKE_COMPOSE) exec -T app php artisan migrate --force --no-interaction
	@curl -fsS http://127.0.0.1:$${APP_PORT:-8000}/api/v1/health
	@echo ""
	$(SMOKE_COMPOSE) down -v --remove-orphans

logs:
	$(COMPOSE) logs -f

sh:
	$(COMPOSE) exec app sh

psql:
	$(COMPOSE) exec postgres psql -U $${DB_USERNAME:-mini_erp} -d $${DB_DATABASE:-mini_erp}
