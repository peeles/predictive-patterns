#!/usr/bin/env sh
# shellcheck disable=SC2039
# Ensure a Laravel-compatible APP_KEY is available to the current shell by
# reading the generated value from the application's .env file. This script is
# intended to be sourced (". scripts/load-backend-env.sh") so any exported
# variables remain in the calling environment.

APP_DIR="${APP_DIR:-/var/www/html}"

wait_for_backend_assets() {
  # Wait for Composer to finish installing dependencies that other services
  # (Horizon, queue workers, etc.) rely on. Those services can start as
  # soon as the backend container is "started", which happens before
  # init-backend.sh has completed. That race meant php artisan commands would be
  # executed without an autoloader and terminate immediately, leaving nginx to
  # return HTTP 500 responses.
  if [ ! -f "$APP_DIR/vendor/autoload.php" ]; then
    echo "Waiting for backend dependencies to be installed..." >&2
    attempts=0
    # Wait up to ~2 minutes before giving up so we don't hang forever if
    # Composer is failing for another reason.
    while [ ! -f "$APP_DIR/vendor/autoload.php" ] && [ "$attempts" -lt 120 ]; do
      attempts=$((attempts + 1))
      sleep 1
    done
  fi
}

wait_for_backend_assets

if [ ! -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/.env.example" ]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

ensure_app_key_from_file() {
  if [ -f "$APP_DIR/.env" ]; then
    APP_KEY_LINE="$(grep -E '^APP_KEY=' "$APP_DIR/.env" | tail -n 1 2>/dev/null)"
    APP_KEY_VALUE="${APP_KEY_LINE#APP_KEY=}"
    if [ -n "$APP_KEY_VALUE" ] && [ "$APP_KEY_VALUE" != "null" ]; then
      export APP_KEY="$APP_KEY_VALUE"
      return 0
    fi
  fi
  return 1
}

ensure_app_key_slot_exists() {
  if [ ! -f "$APP_DIR/.env" ]; then
    return 1
  fi

  if ! grep -qE '^APP_KEY=' "$APP_DIR/.env"; then
    # Ensure the .env file always contains an APP_KEY entry so that
    # `php artisan key:generate` can update it. Without this line the command
    # aborts with "Unable to set application key" and nginx serves.
    {
      printf '\n'
      printf 'APP_KEY=\n'
    } >>"$APP_DIR/.env"
  fi

  return 0
}

if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "null" ]; then
  if ! ensure_app_key_from_file; then
    if [ -f "$APP_DIR/vendor/autoload.php" ] && [ -f "$APP_DIR/artisan" ]; then
      if ensure_app_key_slot_exists; then
        php "$APP_DIR/artisan" key:generate --force --no-interaction >/dev/null 2>&1 || true
        ensure_app_key_from_file
      fi
    fi
  fi
fi

if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "null" ]; then
  ensure_app_key || true
fi


