#!/bin/sh
# ---------------------------------------------------------------------------
# Entrada del contenedor de la API.
# ---------------------------------------------------------------------------
# El punto de este script es hacer FALLAR el despliegue con un mensaje claro
# cuando algo quedo mal configurado, en vez de levantar un contenedor que
# responde con errores 500 a todas partes.
#
# Lo que comprueba:
#   1. que APP_KEY este definida
#   2. que la API pueda conectarse a PostgreSQL
#   3. que la RLS este puesta (portal:verificar-aislamiento)
#
# Por que la 3 es un paso de despliegue y no un chequeo de arranque: si se
# hiciera en cada arranque, un contenedor que arranca con la base de datos
# todavia levantandose caeria en bucle. Aqui solo se avisa por log; el
# comando se ejecuta aparte (ver docs/04 y docs/07).
# ---------------------------------------------------------------------------

set -e

cd /var/www/html

echo "[entrada] PHP $(php -v | head -n 1)"

# ---------------------------------------------------------------------------
# 1. Configuracion minima
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "[entrada] ERROR: APP_KEY no esta definida."
    echo "[entrada] Genere una con:  docker compose run --rm api php artisan key:generate --show"
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Espera a la base de datos y aplicacion de migraciones
# ---------------------------------------------------------------------------
echo "[entrada] esperando a PostgreSQL..."
intentos=0
hasta="intentos + 1"
while [ "$intentos" -lt "$hasta" ]; do
    if php -r 'exit(@pg_connect("host=".(getenv("DB_HOST") ?: "127.0.0.1")." port=".(getenv("DB_PORT") ?: "5432")." dbname=".getenv("DB_DATABASE")." user=".getenv("DB_USERNAME")." connect_timeout=2")) ? 0 : 1);'; then
        echo "[entrada] PostgreSQL responde."
        break
    fi

    intentos=$((intentos + 1))
    echo "[entrada] todavia no responde (intento $intentos de $hasta)"
    sleep 2
done

if [ "$intentos" -ge "$hasta" ]; then
    echo "[entrada] ERROR: PostgreSQL no respondio a tiempo."
    exit 1
fi

if [ "${PORTAL_CORRER_MIGRACIONES:-false}" = "true" ]; then
    echo "[entrada] aplicando migraciones..."
    php artisan migrate --force --no-interaction
    php artisan config:cache
    php artisan route:cache
fi

# ---------------------------------------------------------------------------
# 3. Aviso sobre el aislamiento (no bloqueante)
# ---------------------------------------------------------------------------
echo "[entrada] revisando el aislamiento entre Pymes..."
if php artisan portal:verificar-aislamiento; then
    echo "[entrada] aislamiento verificado."
else
    echo "[entrada] AVISO: el aislamiento entre Pymes NO esta bien puesto."
    echo "[entrada] El portal puede arrancar, pero no debe recibir personas hasta corregirlo."
fi

exec "$@"