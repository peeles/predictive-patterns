#!/usr/bin/env sh
# shellcheck disable=SC2039
# Ensure a Laravel-compatible APP_KEY is available to the current shell by
# reading the generated value from the application's .env file. This script is
# intended to be sourced (". scripts/load-backend-env.sh") so any exported
# variables remain in the calling environment.

APP_DIR="${APP_DIR:-/var/www/html}"

if [ ! -f "$APP_DIR/.env" ] && [ -f "$APP_DIR/.env.example" ]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

ensure_app_key_from_file() {
  if [ -f "$APP_DIR/.env" ]; then
    APP_KEY_LINE="$(grep -E '^APP_KEY=' "$APP_DIR/.env" | tail -n 1 2>/dev/null)"
    APP_KEY_VALUE="${APP_KEY_LINE#APP_KEY=}"
    if [ -n "$APP_KEY_VALUE" ]; then
      export APP_KEY="$APP_KEY_VALUE"
      return 0
    fi
  fi
  return 1
}

if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "null" ]; then
  if ! ensure_app_key_from_file; then
    if [ -f "$APP_DIR/vendor/autoload.php" ] && [ -f "$APP_DIR/artisan" ]; then
      php "$APP_DIR/artisan" key:generate --force --no-interaction >/dev/null 2>&1 || true
      ensure_app_key_from_file
    fi
  fi
fi
