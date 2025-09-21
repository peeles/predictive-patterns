#!/usr/bin/env sh
set -e

APP_DIR="/var/www/html"

# If a bind-mount exists at /var/www/html and it doesn't contain a composer.json,
# create a fresh Laravel app into it.
if [ ! -f "$APP_DIR/composer.json" ]; then
  echo "No composer.json found in $APP_DIR — creating a new Laravel app..."
  TMP_ROOT="$(mktemp -d)"
    cleanup() {
      if [ -n "$TMP_ROOT" ] && [ -d "$TMP_ROOT" ]; then
        rm -rf "$TMP_ROOT"
      fi
    }
    trap cleanup EXIT

    TARGET_DIR="$TMP_ROOT/laravel-app"
    composer create-project laravel/laravel "$TARGET_DIR"

    mkdir -p "$APP_DIR"
    rsync -a --exclude 'custom-overlay' "$TARGET_DIR/" "$APP_DIR/"
    echo "Laravel skeleton staged and copied into $APP_DIR."
fi

# Overlay our custom code (controllers, routes, MCP, etc.) if provided
if [ -d "$APP_DIR/custom-overlay" ]; then
  echo "Applying custom overlay..."
  rsync -a --exclude vendor --exclude node_modules "$APP_DIR/custom-overlay/" "$APP_DIR/"
fi

cd "$APP_DIR"

# Ensure Composer dependencies are installed before running any Artisan commands
if [ -f "$APP_DIR/composer.json" ] && [ ! -d "$APP_DIR/vendor" ]; then
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Install extra packages if missing
if ! grep -q '"laravel/horizon"' "$APP_DIR/composer.json"; then
  composer require laravel/horizon laravel/octane --no-interaction --no-progress
fi

# Install RoadRunner binary for Octane if it hasn't been downloaded yet
if [ -f "$APP_DIR/vendor/autoload.php" ] && [ ! -f "$APP_DIR/vendor/bin/rr" ]; then
  php artisan octane:install --server=roadrunner --force --no-interaction
fi

# Ensure an environment file exists so downstream scripts can manage APP_KEY
php -r "file_exists('$APP_DIR/.env') || copy('$APP_DIR/.env.example', '$APP_DIR/.env');"
