#!/bin/sh
# Same-host disposable fixture. No pulls, published ports, application keys or cloud.
set -eu
app_dir=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
[ -f "$app_dir/vendor/autoload.php" ]
for image in php:8.4-cli-bookworm node:24-bookworm mysql:8.0 redis:7-alpine; do docker image inspect "$image" >/dev/null; done
scratch=$(mktemp -d "${TMPDIR:-/tmp}/y1-admission-runtime.XXXXXX")
network_id=''; mysql_id=''; redis_id=''; php_id=''
runtime_image="codex-y1-admission-fixture-runtime:fixture-$$-$(date +%s)"
cleanup() {
    for id in "$php_id" "$redis_id" "$mysql_id"; do
        if [ -n "$id" ]; then docker rm -fv "$id" >/dev/null 2>&1 || true; fi
    done
    if [ -n "$network_id" ]; then docker network rm "$network_id" >/dev/null 2>&1 || true; fi
    docker image rm "$runtime_image" >/dev/null 2>&1 || true
    rm -rf -- "$scratch"
}
trap cleanup EXIT HUP INT TERM
# No private xrugc image, pulls or package network access are required.
if ! DOCKER_BUILDKIT=0 docker build --pull=false --network=none -t "$runtime_image" -f "$app_dir/tests/manual/admission_http.Dockerfile" "$app_dir/tests/manual" >"$scratch/build.log" 2>&1; then
    cat "$scratch/build.log" >&2; exit 1
fi
network_id=$(docker network create "codex-y1-admission-fixture-$$-$(date +%s)")
mysql_id=$(docker run -d --network "$network_id" --network-alias codex-y1-namespace-mysql-20261013 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% mysql:8.0)
# Share only these disposable containers' network namespace, so the exact CI
# loopback guard runs locally too. Nothing is bound to the host's 3306/6379.
redis_id=$(docker run -d --network "container:$mysql_id" redis:7-alpine redis-server --save '' --appendonly no)
attempt=0
until docker exec "$mysql_id" mysqladmin ping -h127.0.0.1 -uroot --silent >/dev/null 2>&1; do
    attempt=$((attempt + 1)); [ "$attempt" -lt 60 ] || { echo 'Isolated MySQL startup failed.' >&2; exit 1; }; sleep 1
done
printf '%s\n' '127.0.0.1 localhost codex-y1-namespace-mysql-20261013 codex-y1-namespace-redis-20261013' >"$scratch/hosts"
php_id=$(docker create --network "container:$mysql_id" -v "$app_dir:/app:ro" -v "$scratch/hosts:/etc/hosts:ro" \
    -e Y1_ADMISSION_FIXTURE_MODE=ci-loopback -w /app \
    --entrypoint node "$runtime_image" tests/manual/run_admission_http_ci.mjs)
docker start -a "$php_id"
status=$(docker inspect --format '{{.State.ExitCode}}' "$php_id")
exit "$status"
