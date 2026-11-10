#!/bin/sh
# Entrypoint for the Invoice Ninja local Docker environment.
set -e

cd /var/www/html

# Laravel and some maintenance routines expect a writable .env file. All runtime
# configuration is supplied through container environment variables, and Dotenv
# does not overwrite existing variables, so an empty file is sufficient.
[ -f .env ] || : > .env

mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Only the web container runs migrations, workers and the scheduler wait for it.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "[entrypoint] waiting for the database to accept connections..."

    tries=0
    until mysqladmin ping \
            -h"${DB_HOST:-db}" \
            -P"${DB_PORT:-3306}" \
            -u"${DB_USERNAME:-ninja}" \
            -p"${DB_PASSWORD:-}" \
            --silent >/dev/null 2>&1; do
        tries=$((tries + 1))

        if [ "$tries" -ge 60 ]; then
            echo "[entrypoint] database not reachable after 120s, attempting migration anyway"
            break
        fi

        sleep 2
    done

    echo "[entrypoint] running migrations..."
    php artisan migrate --force

    # This repository ships the Flutter admin bundle, not the React admin bundle.
    # When FORCE_FLUTTER_ADMIN is enabled, make sure no account points at the
    # missing React shell. Runs on every start so it also self heals accounts
    # that were created with the React default.
    if [ "${FORCE_FLUTTER_ADMIN:-0}" = "1" ]; then
        MYSQL_PWD="$DB_PASSWORD" mysql \
            -h "${DB_HOST:-db}" \
            -P "${DB_PORT:-3306}" \
            -u "${DB_USERNAME:-ninja}" \
            "${DB_DATABASE:-ninja}" \
            -e "UPDATE accounts SET set_react_as_default_ap = 0" >/dev/null 2>&1 || true
    fi

    # Reference data (languages, currencies, countries, timezones, designs) is
    # not shipped in the schema dump, so seed it once on a fresh database. The
    # seeder is idempotent and returns early when the data already exists.
    if [ "${RUN_SEEDER:-1}" = "1" ]; then
        tz_count=$(MYSQL_PWD="$DB_PASSWORD" mysql -N -s \
            -h "${DB_HOST:-db}" \
            -P "${DB_PORT:-3306}" \
            -u "${DB_USERNAME:-ninja}" \
            "${DB_DATABASE:-ninja}" \
            -e "SELECT COUNT(*) FROM timezones" 2>/dev/null || echo 0)

        if [ "${tz_count:-0}" = "0" ]; then
            echo "[entrypoint] seeding reference data..."
            php artisan db:seed --force || true
            # Reference data is cached forever and may have been cached empty.
            php artisan cache:clear || true
        fi
    fi

    php artisan storage:link || true
    php artisan config:clear || true
    php artisan route:clear || true
    php artisan view:clear || true
fi

echo "[entrypoint] starting: $*"
exec "$@"
