#!/bin/bash
#
# Invoice Ninja backup service.
#
# Produces a self contained, timestamped backup directory containing:
#   - db-<name>.sql.gz   one gzip compressed mysqldump per database
#   - storage.tar.gz     the application storage (uploads, documents, logs)
#   - config.tar.gz      the deployment configuration (compose, Dockerfile, .env)
#   - SHA256SUMS         checksums for every artifact
#   - manifest.txt       metadata about the run
#
# Modes:
#   backup.sh once        run a single backup and exit
#   backup.sh schedule    run continuously with BACKUP_INTERVAL seconds between runs
#   backup.sh list        list existing backups
#   backup.sh restore ... delegate to restore.sh
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
BACKUP_CONFIG_PATH="${BACKUP_CONFIG_PATH:-/config}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
BACKUP_INTERVAL="${BACKUP_INTERVAL:-86400}"
BACKUP_INCLUDE_STORAGE="${BACKUP_INCLUDE_STORAGE:-1}"
BACKUP_INCLUDE_CONFIG="${BACKUP_INCLUDE_CONFIG:-1}"
APP_IMAGE="${APP_IMAGE:-unknown}"

log() {
    printf '%s [backup] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

dump_databases() {
    local dir="$1"

    for db in $BACKUP_DATABASES; do
        log "dumping database: $db"
        MYSQL_PWD="$DB_PASSWORD" mysqldump \
            --host="$DB_HOST" \
            --port="$DB_PORT" \
            --user="$DB_USERNAME" \
            --single-transaction \
            --quick \
            --routines \
            --triggers \
            --events \
            --no-tablespaces \
            --add-drop-database \
            --databases "$db" \
            | gzip -9 > "$dir/db-${db}.sql.gz"
    done
}

archive_storage() {
    local dir="$1"

    if [ "$BACKUP_INCLUDE_STORAGE" != "1" ]; then
        log "storage archive disabled"
        return 0
    fi

    log "archiving application storage: $BACKUP_STORAGE_PATH"

    # The application keeps writing while the archive is built, so a file can
    # change mid read. GNU tar returns 1 for that warning and only 2 and above
    # for a fatal error, so accept 0 and 1.
    local status=0
    tar --warning=no-file-changed --ignore-failed-read \
        -czf "$dir/storage.tar.gz" -C "$BACKUP_STORAGE_PATH" . || status=$?

    if [ "$status" -gt 1 ]; then
        log "storage archive failed with status $status"
        return "$status"
    fi

    return 0
}

archive_config() {
    local dir="$1"

    if [ "$BACKUP_INCLUDE_CONFIG" != "1" ]; then
        log "config archive disabled"
        return 0
    fi

    log "archiving configuration: $BACKUP_CONFIG_PATH"

    local status=0
    tar --warning=no-file-changed --ignore-failed-read \
        -czf "$dir/config.tar.gz" -C "$BACKUP_CONFIG_PATH" . || status=$?

    if [ "$status" -gt 1 ]; then
        log "config archive failed with status $status"
        return "$status"
    fi

    return 0
}

write_manifest() {
    local dir="$1"
    local timestamp="$2"

    (
        cd "$dir"
        sha256sum ./*.gz > SHA256SUMS
    )

    {
        echo "timestamp=$timestamp"
        echo "created_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "host=$(cat /etc/hostname 2>/dev/null || echo unknown)"
        echo "databases=$BACKUP_DATABASES"
        echo "retention_days=$BACKUP_RETENTION_DAYS"
        echo "storage_included=$BACKUP_INCLUDE_STORAGE"
        echo "config_included=$BACKUP_INCLUDE_CONFIG"
        echo "app_image=$APP_IMAGE"
        echo "artifacts:"
        ls -1 "$dir"
    } > "$dir/manifest.txt"
}

prune_old_backups() {
    if [ "$BACKUP_RETENTION_DAYS" -le 0 ]; then
        return 0
    fi

    log "pruning backups older than ${BACKUP_RETENTION_DAYS} days"

    # Prune by the timestamp encoded in the directory name. Filesystem mtimes
    # are not used because they are unreliable on Windows bind mounts, where a
    # naive find -mtime can delete every backup.
    local now cutoff name iso dir_time
    now="$(date -u +%s)"
    cutoff=$(( now - BACKUP_RETENTION_DAYS * 86400 ))

    for dir in "$BACKUP_DIR"/*; do
        [ -d "$dir" ] || continue
        name="$(basename "$dir")"

        case "$name" in
            [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]) ;;
            *) continue ;;
        esac

        iso="${name:0:4}-${name:4:2}-${name:6:2}T${name:9:2}:${name:11:2}:${name:13:2}Z"
        dir_time="$(date -u -d "$iso" +%s 2>/dev/null || echo '')"
        [ -n "$dir_time" ] || continue

        if [ "$dir_time" -lt "$cutoff" ]; then
            log "pruning $name"
            rm -rf "$dir"
        fi
    done
}

run_backup() {
    local timestamp
    timestamp="$(date -u +%Y%m%d-%H%M%S)"

    local dir="$BACKUP_DIR/$timestamp"

    mkdir -p "$dir"
    log "starting backup $timestamp"

    dump_databases "$dir"
    archive_storage "$dir"
    archive_config "$dir"
    write_manifest "$dir" "$timestamp"

    # A plain marker file is always written. The 'latest' symlink is convenient
    # on Linux, but on a case insensitive filesystem (Windows bind mounts) it
    # collides with LATEST, so only create it when the two names differ.
    printf '%s\n' "$timestamp" > "$BACKUP_DIR/LATEST"
    if [ ! -e "$BACKUP_DIR/latest" ] || ! [ "$BACKUP_DIR/LATEST" -ef "$BACKUP_DIR/latest" ]; then
        ln -sfn "$timestamp" "$BACKUP_DIR/latest" 2>/dev/null || true
    fi

    prune_old_backups

    log "backup complete: $dir"
}

case "${1:-schedule}" in
    once)
        run_backup
        ;;
    schedule)
        if [ "$BACKUP_INTERVAL" -le 0 ]; then
            BACKUP_INTERVAL=86400
        fi

        while true; do
            run_backup || log "backup run failed, will retry after ${BACKUP_INTERVAL}s"
            log "sleeping ${BACKUP_INTERVAL}s until the next run"
            sleep "$BACKUP_INTERVAL"
        done
        ;;
    list)
        ls -1 "$BACKUP_DIR" 2>/dev/null | sort
        ;;
    restore)
        shift
        exec /usr/local/bin/restore.sh "$@"
        ;;
    *)
        echo "usage: backup.sh [once|schedule|list|restore <timestamp|latest>]" >&2
        exit 2
        ;;
esac
