#!/bin/sh
# Wait for MySQL, create the schema, then hand off to Apache.
#
# install.php is idempotent (CREATE DATABASE / TABLE IF NOT EXISTS), so running
# it on every container start is safe and keeps a fresh database working with no
# manual step.
set -e

# Hosts such as Railway and Render assign a port at run time.
if [ -n "$PORT" ] && [ "$PORT" != "80" ]; then
    sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
    echo "[entrypoint] Apache listening on ${PORT}"
fi

# Some hosts supply a single connection URL instead of discrete variables.
# Parse it into what config.php expects, without overriding anything explicit.
if [ -n "$DATABASE_URL" ] && [ -z "$TRANSLIT_DB_HOST_EXPLICIT" ]; then
    # mysql://user:pass@host:port/dbname
    url="${DATABASE_URL#*://}"
    creds="${url%%@*}"
    rest="${url#*@}"
    TRANSLIT_DB_USER="${creds%%:*}"
    TRANSLIT_DB_PASS="${creds#*:}"
    hostport="${rest%%/*}"
    TRANSLIT_DB_HOST="${hostport%%:*}"
    case "$hostport" in
        *:*) TRANSLIT_DB_PORT="${hostport#*:}" ;;
    esac
    TRANSLIT_DB_NAME="${rest#*/}"
    TRANSLIT_DB_NAME="${TRANSLIT_DB_NAME%%\?*}"
    export TRANSLIT_DB_USER TRANSLIT_DB_PASS TRANSLIT_DB_HOST TRANSLIT_DB_PORT TRANSLIT_DB_NAME
    echo "[entrypoint] using DATABASE_URL -> ${TRANSLIT_DB_USER}@${TRANSLIT_DB_HOST}:${TRANSLIT_DB_PORT}/${TRANSLIT_DB_NAME}"
fi

echo "[entrypoint] waiting for MySQL at ${TRANSLIT_DB_HOST}:${TRANSLIT_DB_PORT}"
i=0
until php -r '
    $h = getenv("TRANSLIT_DB_HOST"); $p = getenv("TRANSLIT_DB_PORT");
    $u = getenv("TRANSLIT_DB_USER"); $w = getenv("TRANSLIT_DB_PASS");
    try { new PDO("mysql:host=$h;port=$p;charset=utf8mb4", $u, $w); exit(0); }
    catch (Exception $e) { exit(1); }
' 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] MySQL did not become reachable in 60s; starting anyway."
        echo "[entrypoint] The app will show a database error page until it does."
        break
    fi
    sleep 1
done

if [ "$i" -lt 60 ]; then
    echo "[entrypoint] MySQL reachable after ${i}s; running install.php"
    php install.php || echo "[entrypoint] install.php reported a problem; continuing."
fi

exec "$@"
