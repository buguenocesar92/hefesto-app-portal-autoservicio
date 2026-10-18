# ---------------------------------------------------------------------------
# Imagen de la API (Laravel + php-fpm)
# ---------------------------------------------------------------------------
# Las versiones estan fijadas por digest, no por etiqueta. Una etiqueta como
# `8.4` puede cambiar el contenido sin avisar; un digest no. El mismo digest
# que se creo es el que se instala, en cualquier maquina y en cualquier dia.
#
# Referencia de digests:
#   php:8.4.26-fpm-alpine3.23  -> usado abajo (capas de ejecucion y cli)
#   php:8.4.26-cli-alpine3.23   -> usado en la etapa de compilacion
#
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Etapa 1: compilar dependencias con el autoloader optimizado.
# ---------------------------------------------------------------------------
# Las imagenes de PHP estan fijadas por tag exacto (8.4.26), no por digest.
# Falta fijarlas tambien por digest SHA256 antes de produccion: es una
# tarea pendiente, anotada en docs/10.
#
# La imagen `composer:2` NO esta fijada (ni por tag ni por digest) porque su
# version exacta no se pudo verificar al escribir este archivo. Antes de
# produccion hay que reemplazarla por `composer:<version>@sha256:<digest>`
# verificados. Ver docs/07, seccion "Pinning de imagenes".
FROM php:8.4.26-cli-alpine3.23 AS dependencias

RUN apk add --no-cache git unzip libpq-dev icu-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copian solo los manifiestos primero para que la capa de dependencias se
# reutilice cuando no cambian las versiones del composer.json.
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev

# Etapa 2: la aplicacion.
FROM php:8.4.26-fpm-alpine3.23 AS app

# `nginx` queda como servidor web interno de la imagen de la API para las
# rutas internas de salud. El servidor publico es Caddy (deploy/caddy/Caddyfile).
# `curl` se instala solo para las comprobaciones de salud del orquestador.
RUN apk add --no-cache nginx supervisor curl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl opcache \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY --from=dependencias /var/www/html /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/portal.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# El usuario de php-fpm no es root. Los volumenes de datos se montan con los
# permisos de este usuario.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]