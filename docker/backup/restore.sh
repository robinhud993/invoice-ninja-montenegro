#!/bin/bash
#
# Invoice Ninja restore helper.
#
# Restores one database dump per configured database and, optionally, the
# application storage. The configuration archive is never applied automatically:
# it is printed so it can be reviewed and deployed deliberately.
#
# Usage (run with root so the storage volume is writable):
#   docker compose --env-file .env.docker --profile backup run --rm --user root backup restore latest
#
# Set FORCE=1 to skip the confirmation prompt.
#
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-ninja}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_DATABASE="${DB_DATABASE:-ninja}"
BACKUP_DATABASES="${BACKUP_DATABASES:-$DB_DATABASE}"

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_STORAGE_PATH="${BACKUP_STORAGE_PATH:-/app-storage}"
BACKUP_INCLUDE_STORAGE="${BACKUP_INCLUDE_STORAGE:-1}"

log() {
    printf '%s [restore] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

target="${1:-latest}"

if [ "$target" = "latest" ]; then
    if [ -L "$BACKUP_DIR/latest" ]; then
        target="$(basename "$(readlink -f "$BACKUP_DIR/latest")")"
    elif [ -f "$BACKUP_DIR/LATEST" ]; then
        target="$(cat "$BACKUP_DIR/LATEST")"
    else
        echo "No 'latest' marker found in $BACKUP_DIR. Pass a timestamp explicitly." >&2
        exit 1
    fi
fi

dir="$BACKUP_DIR/$target"

if [ ! -d "$dir" ]; then
    echo "Backup not found: $dir" >&2
    exit 1
fi

log "restore source: $dir"
log "databases to restore: $BACKUP_DATABASES"
log "storage restore: $BACKUP_INCLUDE_STORAGE"

if [ "${FORCE:-0}" != "1" ]; then
    printf "This will overwrite the current databases and stop all activity. Type 'yes' to continue: "
    read -r answer
    [ "$answer" = "yes" ] || { echo "Aborted."; exit 1; }
fi

for db in $BACKUP_DATABASES; do
    dump="$dir/db-${db}.sql.gz"

    if [ ! -f "$dump" ]; then
        log "no dump for $db, skipping"
        continue
    fi

    log "restoring database: $db"
    gunzip -c "$dump" | MYSQL_PWD="$DB_PASSWORD" mysql \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME"
done

if [ "$BACKUP_INCLUDE_STORAGE" = "1" ] && [ -f "$dir/storage.tar.gz" ]; then
    log "restoring application storage into $BACKUP_STORAGE_PATH"
    mkdir -p "$BACKUP_STORAGE_PATH"
    tar -xzf "$dir/storage.tar.gz" -C "$BACKUP_STORAGE_PATH"
fi

log "database and storage restore complete"
log "configuration is NOT restored automatically, review it with: tar -tzf $dir/config.tar.gz"
log "then recreate the stack with: docker compose --env-file .env.docker up -d --force-recreate"
