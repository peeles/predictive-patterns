#!/bin/sh

until php artisan migrate --force; do
  echo "Waiting for database..."
  sleep 5
done
