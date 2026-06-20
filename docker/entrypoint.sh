#!/bin/sh
# dblib container startup: materialise config, wait for the DB, generate the
# encryption key (once), apply the metadata schema, then hand off to Apache.
#
# The CLIs run as root (so the DBLIB_* environment reaches them intact), but the
# secrets dir is pre-created and owned by www-data: root can write the key into
# it, and because the dir is www-data's and the key is world-readable (0644),
# Apache (www-data) can read it on every request that decrypts a credential.
set -e

APP=/var/www/html
KEY_FILE="${DBLIB_KEY_FILE:-/var/dblib-secrets/credential.key}"
KEY_DIR=$(dirname "$KEY_FILE")

# 1. First boot with no mounted config? Drop the env-driven Docker config in.
if [ ! -f "$APP/config/config.php" ]; then
  cp "$APP/config/config.docker.php" "$APP/config/config.php"
  chown www-data:www-data "$APP/config/config.php"
fi

# 2. Secrets dir owned by www-data so it can traverse in to read the key. genkey
#    sees the dir already exists and just writes the key file inside it.
mkdir -p "$KEY_DIR"
chown www-data:www-data "$KEY_DIR"
chmod 0700 "$KEY_DIR"

# 3. Wait for the database (and its provisioning user from docker/initdb).
echo "dblib: waiting for database ${DBLIB_DB_HOST:-db}:${DBLIB_DB_PORT:-3306}..."
until php /usr/local/share/wait-for-db.php 2>/dev/null; do
  sleep 2
done
echo "dblib: database is up."

# 4. Generate the credential-encryption key once (genkey refuses to overwrite,
#    so on later boots this is a no-op and the non-zero exit is expected).
php "$APP/cli/genkey.php" || true

# 5. Apply the metadata schema (+ optional first teacher). Idempotent — safe on
#    every boot.
if [ -n "$DBLIB_TEACHER_EMAIL" ] && [ -n "$DBLIB_TEACHER_PASSWORD" ]; then
  php "$APP/cli/migrate.php" --teacher="$DBLIB_TEACHER_EMAIL" --password="$DBLIB_TEACHER_PASSWORD" --name="${DBLIB_TEACHER_NAME:-Teacher}"
else
  php "$APP/cli/migrate.php"
fi

echo "dblib: ready — starting Apache."
exec "$@"
