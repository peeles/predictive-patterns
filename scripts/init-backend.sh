#!/usr/bin/env sh
set -e

APP_DIR="/var/www/html"

# If a bind-mount exists at /var/www/html and it doesn't contain a composer.json,
# create a fresh Laravel app into it.
if [ ! -f "$APP_DIR/composer.json" ]; then
  echo "No composer.json found in $APP_DIR — creating a new Laravel app..."
  composer create-project laravel/laravel "$APP_DIR"
  echo "Laravel skeleton created."
fi

# Overlay our custom code (controllers, routes, MCP, etc.) if provided
if [ -d "$APP_DIR/custom-overlay" ]; then
  echo "Applying custom overlay..."
  rsync -a --exclude vendor --exclude node_modules "$APP_DIR/custom-overlay/" "$APP_DIR/"
fi

# Install extra packages if missing
if ! grep -q '"laravel/horizon"' "$APP_DIR/composer.json"; then
  composer require laravel/horizon laravel/octane --no-interaction --no-progress
fi

# Generate key if empty
php -r "file_exists('$APP_DIR/.env') || copy('$APP_DIR/.env.example', '$APP_DIR/.env');"
php artisan key:generate || true
