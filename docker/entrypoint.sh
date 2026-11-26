#!/bin/sh
# Container start. The same image runs three roles, chosen by CONTAINER_ROLE:
#
#   web        (default) migrate, link the public disk, cache config/routes/views, then serve.
#   worker     wait for the web container to be up, then run the command (queue:work).
#   scheduler  wait for the web container to be up, then run the command (schedule:work).
#
# Only web touches the schema, so two containers never race a migration. web answers /up only after its migrations
# are done (it execs the server last), so the other roles use that as their gate. Seeding is deliberately not here;
# it is run once by hand.
set -eu

cd /app

role="${CONTAINER_ROLE:-web}"

case "$role" in
    web)
        # The database container may still be starting on a fresh deploy.
        attempt=1
        until php artisan migrate --force --no-interaction; do
            if [ "$attempt" -ge 10 ]; then
                echo "entrypoint: migrations failed after $attempt attempts" >&2
                exit 1
            fi
            echo "entrypoint: migrate failed (attempt $attempt), retrying in 3s" >&2
            attempt=$((attempt + 1))
            sleep 3
        done

        # The image ships the link; recreate it only if it has gone missing.
        if [ ! -L public/storage ]; then
            php artisan storage:link --no-interaction
        fi

        # Run at start, not at build time: the config cache must hold the
        # environment Coolify injects. Includes filament:optimize.
        php artisan optimize --no-interaction
        ;;
    worker | scheduler)
        # Gate on web's /up: the schema is current once it answers. Retries for about 5 minutes, then fails so the
        # orchestrator restarts the container.
        url="${WEB_HEALTH_URL:-http://tcg-web:8080/up}"
        attempt=1
        until curl -fsS --max-time 3 "$url" > /dev/null 2>&1; do
            if [ "$attempt" -ge 100 ]; then
                echo "entrypoint: $url did not answer after $attempt attempts" >&2
                exit 1
            fi
            echo "entrypoint: waiting for $url (attempt $attempt)" >&2
            attempt=$((attempt + 1))
            sleep 3
        done
        ;;
    *)
        echo "entrypoint: unknown CONTAINER_ROLE '$role' (web, worker or scheduler)" >&2
        exit 1
        ;;
esac

exec "$@"
