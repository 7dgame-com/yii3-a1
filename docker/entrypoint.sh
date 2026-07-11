#!/bin/sh
set -eu

mkdir -p runtime/logs

if [ -n "${JWT_KEY:-}" ] && [ ! -f "$JWT_KEY" ]; then
    is_cloud_production=false
    if [ "${DEPLOYMENT_MODE:-}" = "cloud" ]; then
        is_cloud_production=true
    elif [ -z "${DEPLOYMENT_MODE:-}" ] && { [ "${APP_ENV:-${YII_ENV:-development}}" = "production" ] || [ "${APP_ENV:-${YII_ENV:-development}}" = "prod" ]; }; then
        is_cloud_production=true
    fi

    if [ "$is_cloud_production" = true ] && [ -z "${JWT_SECRET:-}" ]; then
        echo "JWT_KEY must point to a readable signing key or JWT_SECRET must be set in production" >&2
        exit 1
    fi

    mkdir -p "$(dirname "$JWT_KEY")"
    umask 077
    # A secret manager may inject a value directly; write it only into the
    # runtime filesystem with restrictive permissions.  Local development gets
    # an unpredictable ephemeral key instead of a repository-known fallback.
    if [ -n "${JWT_SECRET:-}" ]; then
        printf '%s' "$JWT_SECRET" > "$JWT_KEY"
    elif command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32 > "$JWT_KEY"
    else
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$JWT_KEY"
    fi
fi

composer dump-autoload --no-interaction

exec "$@"
