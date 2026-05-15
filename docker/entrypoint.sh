#!/bin/sh
set -eu

mkdir -p runtime/logs

if [ -n "${JWT_KEY:-}" ] && [ ! -f "$JWT_KEY" ]; then
    mkdir -p "$(dirname "$JWT_KEY")"
    printf '%s\n' "${JWT_SECRET:-dev-jwt-secret-key-do-not-use-in-production}" > "$JWT_KEY"
fi

composer dump-autoload --no-interaction

exec "$@"
