FROM php:8.4-cli

WORKDIR /app

# Add the official PostgreSQL apt repo so we can install a pg_dump that matches the
# managed server's major version. Render's Postgres is v18, and pg_dump refuses to dump a
# server newer than itself, so Debian's default client (17) is too old — pin client 18.
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo $VERSION_CODENAME)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y \
    zip unzip git nodejs npm postgresql-client-18 \
    libpq-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) pdo_pgsql mbstring gd zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Retry composer install a couple times: GitHub's codeload occasionally returns a transient
# HTTP 400/timeout for a package zip, which would otherwise fail the whole build. If the last
# attempt still fails, the RUN fails as normal.
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
 || { echo "composer install failed; retrying in 10s..."; sleep 10; composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist; } \
 || { echo "composer install failed; retrying in 20s..."; sleep 20; composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist; }

RUN npm install
RUN npm run build

RUN chmod -R 775 storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["docker-entrypoint.sh"]
