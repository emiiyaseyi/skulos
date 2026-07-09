# Minimal container for a free test deployment (Render/Fly/Railway) before
# moving to the real cPanel host. Uses SQLite so no separate database
# service is needed to get something live and clickable quickly.
#
# IMPORTANT: on a free host without a persistent disk, the SQLite file is
# wiped on every redeploy/restart. Fine for demoing the platform; not
# where real school data should ever live. Move to MySQL + a persistent
# disk (or the cPanel host) before anyone depends on this deployment.

FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libsqlite3-dev libzip-dev libonig-dev \
    && docker-php-ext-install pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && touch database/database.sqlite \
    && php artisan migrate --force \
    && php artisan db:seed --class="Database\Seeders\RbacSeeder" --force \
    && php artisan config:cache

EXPOSE 8080
CMD php artisan serve --host=0.0.0.0 --port=8080
