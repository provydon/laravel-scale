#!/bin/sh
set -e

# On any exit due to error, print a hint (set -e will have already printed the failing command's output).
trap 'if [ $? -ne 0 ]; then echo "--- Entrypoint failed. Check logs above. Common causes: database not reachable (open port 3306 or 5432), DB user has no access to the database, or missing env vars (APP_KEY, DB_*). ---"; fi' EXIT

cd /app

# Build .env from .env.example + env vars (Render injects env)
if [ ! -f .env.example ]; then
  echo "Error: .env.example is required. Add it to your repo."
  exit 1
fi
while IFS= read -r line; do
  case "$line" in
    ''|\#*) continue ;;
  esac
  var=${line%%=*}
  def=${line#*=}
  case "$var" in
    RENDER_*|KUBERNETES_*|HOSTNAME|PATH) continue ;;
  esac
  if [ "$var" = "APP_KEY" ]; then
    continue
  fi
  val=$(eval "echo \${$var}")
  [ -n "$val" ] || val=$def
  printf '%s=%s\n' "$var" "$val"
done < .env.example > .env

DEPLOYMENT_TYPE=${DEPLOYMENT_TYPE:-web}

if [ "$DEPLOYMENT_TYPE" = "worker" ]; then
    echo "Starting worker deployment..."
    php artisan config:clear
    php artisan cache:clear || true
    php artisan config:cache
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord-worker.conf
else
    echo "Starting web deployment..."
    php artisan migrate --force || {
        echo "ERROR: Migration failed. Check that your database is reachable from this container (port 3306 for MySQL, 5432 for PostgreSQL) and that DB_USERNAME has access to DB_DATABASE."
        exit 1
    }
    php artisan config:clear
    php artisan cache:clear
    php artisan config:cache
    php artisan optimize
    php artisan storage:link || true
    echo "yes" | php artisan octane:install --server=frankenphp 2>/dev/null || true
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord-web.conf
fi
