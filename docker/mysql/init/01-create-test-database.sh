#!/bin/sh
# Creates the dedicated database used by the PHPUnit suite (db-ninja-03).
set -e

: "${MYSQL_DATABASE:?MYSQL_DATABASE is required}"
: "${MYSQL_USER:?MYSQL_USER is required}"

TEST_DATABASE="${MYSQL_DATABASE}_test"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${TEST_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "[init] created test database ${TEST_DATABASE}"
