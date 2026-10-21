#!/usr/bin/env bash
# Runs once, when the sail-mysql volume is first initialised (official mysql image entrypoint).
# phpunit.xml pins host mysql, schema inbils_test and user inbils, so all three must exist here
# even though the Sail app itself connects as $MYSQL_USER (DB_USERNAME) to the main schema.
# `artisan test --parallel` additionally needs the worker schemas inbils_test_test_<N> (Laravel's {database}_test_{token}):
# the runner creates them itself, so only their privilege is granted here and nothing else (AGENTS.md, Test Database Rule).
set -e

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`inbils_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'inbils'@'%' IDENTIFIED BY 'inbils_local_change_me';
    GRANT ALL PRIVILEGES ON \`inbils_test\`.* TO 'inbils'@'%';
    GRANT ALL PRIVILEGES ON \`inbils\_test\_test\_%\`.* TO 'inbils'@'%';
    GRANT ALL PRIVILEGES ON \`inbils_test\`.* TO '$MYSQL_USER'@'%';
EOSQL
