#!/usr/bin/env bash
# Backup database dan berkas yang tidak ada di git.
#
# Pemakaian:
#   ./scripts/backup.sh          # atau: make backup
#
# Hasil di ~/backups:
#   db_YYYY-MM-DD_HHMM.dump       pg_dump format custom, sudah terkompresi
#   files_YYYY-MM-DD_HHMM.tar.gz  .env, upload, dan konfigurasi deploy
#
# Dump diperiksa magic bytes "PGDMP" sebelum disimpan, sehingga kegagalan
# `docker compose` tidak meninggalkan berkas 0 byte yang menyamar sebagai backup.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"
STAMP="$(date +%F_%H%M)"

cd "$REPO_ROOT"
mkdir -p "$BACKUP_DIR"

log() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }

# Compose di proyek ini dikunci ke COMPOSE_PROJECT_NAME dari .env di root.
# Berkas compose juga memuat `name:`, tapi -p dipakai agar hasilnya sama
# dengan Makefile tanpa bergantung pada direktori kerja. Nilai dibaca tanpa
# mengeksekusi .env.
compose_project=""
if [ -f .env ]; then
  compose_project="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' .env | tail -n 1 | tr -d '"'\''\r')"
fi

COMPOSE=(docker compose)
[ -n "$compose_project" ] && COMPOSE+=(-p "$compose_project")

# --- Prasyarat ----------------------------------------------------------------
if ! "${COMPOSE[@]}" ps --status running --quiet postgres | grep -q .; then
  log "GAGAL: container postgres tidak berjalan."
  log "Jalankan dulu: docker compose -p ${compose_project:-mini-erp} up -d postgres"
  exit 1
fi

# --- Database -----------------------------------------------------------------
tmp_dump="$(mktemp)"
trap 'rm -f "$tmp_dump"' EXIT

log "Dump database..."
if ! "${COMPOSE[@]}" exec -T postgres sh -c \
  'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$tmp_dump"; then
  log "GAGAL: pg_dump keluar dengan status tidak nol."
  exit 1
fi

if [ "$(head -c 5 "$tmp_dump")" != "PGDMP" ]; then
  log "GAGAL: hasil dump bukan arsip PostgreSQL (magic bytes tidak cocok)."
  exit 1
fi

db_file="$BACKUP_DIR/db_$STAMP.dump"
mv "$tmp_dump" "$db_file"
trap - EXIT
log "Database: $db_file ($(du -h "$db_file" | cut -f1))"

# --- Berkas di luar git -------------------------------------------------------
# Daftar disusun dari path yang benar-benar ada, supaya tar tidak gagal hanya
# karena satu berkas opsional belum dibuat.
files=()
for path in .env backend/.env backend/storage/app \
  docker-compose.yml docker-compose.override.yml docker; do
  [ -e "$path" ] && files+=("$path")
done

if [ ${#files[@]} -eq 0 ]; then
  log "LEWATI: tidak ada berkas di luar git untuk diarsipkan."
else
  files_file="$BACKUP_DIR/files_$STAMP.tar.gz"
  tar -czf "$files_file" "${files[@]}"
  log "Berkas: $files_file ($(du -h "$files_file" | cut -f1))"
  log "Isi: ${files[*]}"
fi

# --- Rotasi -------------------------------------------------------------------
deleted=$(find "$BACKUP_DIR" -maxdepth 1 -type f \
  \( -name 'db_*.dump' -o -name 'files_*.tar.gz' \) \
  -mtime +"$RETENTION_DAYS" -print -delete | wc -l)
[ "$deleted" -gt 0 ] && log "Rotasi: $deleted berkas lebih dari $RETENTION_DAYS hari dihapus."

log "Selesai."
