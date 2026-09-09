#!/bin/sh
set -e

# DB credentials are read from the environment by PHP itself (getenv), so
# passwords with special characters can never break shell quoting.
echo "[entrypoint] Waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306} ..."
ready=0
for i in $(seq 1 60); do
  if php /var/www/html/scripts/dbcheck.php 2>/dev/null; then
    ready=1
    break
  fi
  sleep 3
done

if [ "${ready}" != "1" ]; then
  echo "[entrypoint] ERROR: could not reach MySQL at ${DB_HOST:-db}:${DB_PORT:-3306} after 180s." >&2
  echo "[entrypoint] Set these env vars on Render (Service > Environment):" >&2
  echo "[entrypoint]   DB_HOST  -> your MySQL provider's host (e.g. moneywise-db-aivencloud.com)" >&2
  echo "[entrypoint]   DB_PORT  -> provider's port (often NOT 3306, e.g. 11117)" >&2
  echo "[entrypoint]   DB_NAME  -> database name" >&2
  echo "[entrypoint]   DB_USER  -> database user" >&2
  echo "[entrypoint]   DB_PASS  -
  > database password" >&2
  echo "[entrypoint]   DB_SSL   -> '1' if your provider requires SSL (Aiven, PlanetScale, etc.)" >&2
  echo "[entrypoint] NOTE: Render has no built-in 'db' host — docker-compose is only for local use." >&2
  exit 1
fi
echo "[entrypoint] MySQL is up."

echo "[entrypoint] Running install.php (idempotent)..."
php /var/www/html/install.php || { echo "[entrypoint] install.php failed." >&2; exit 1; }

# Render injects a PORT env var (default 10000) and routes traffic to it.
# Make Apache listen on that port so Render health checks succeed.
PORT="${PORT:-80}"
echo "[entrypoint] Binding Apache to port ${PORT} ..."
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/^<VirtualHost \\*:80>$/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "[entrypoint] Starting Apache..."
exec apache2-foreground
