#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --no-interaction --force
fi

echo "Waiting for MySQL..."
i=0
until php artisan migrate --force; do
  i=$((i + 1))
  if [ "$i" -ge 30 ]; then
    echo "MySQL did not become ready in time."
    exit 1
  fi
  sleep 2
done

php artisan db:seed --force

exec php artisan serve --host=0.0.0.0 --port=8000
