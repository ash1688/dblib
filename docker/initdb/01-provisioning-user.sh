#!/bin/bash
# Runs once, during MariaDB's first-time initialisation.
#
# The mariadb image keeps root reachable from localhost only, but the app lives
# in a separate container and needs a privileged provisioning account it can
# reach over the network. Create that account (global privileges + GRANT OPTION,
# since it does CREATE DATABASE / CREATE USER / GRANT for student sandboxes),
# accessible from any host on the compose network ('%').
set -e

ADMIN_USER="${DBLIB_DB_ADMIN_USER:-dblib_admin}"
ADMIN_PASS="${DBLIB_DB_ADMIN_PASS:-dblib_admin_pw}"

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-SQL
    CREATE USER IF NOT EXISTS '${ADMIN_USER}'@'%' IDENTIFIED BY '${ADMIN_PASS}';
    GRANT ALL PRIVILEGES ON *.* TO '${ADMIN_USER}'@'%' WITH GRANT OPTION;
    FLUSH PRIVILEGES;
SQL

echo "dblib: provisioning user '${ADMIN_USER}'@'%' ready."
