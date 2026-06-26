FROM php:8.4-cli

WORKDIR /app

RUN apt-get update && apt-get install -y \
    zip unzip curl git nodejs npm postgresql-client \
    libpq-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) pdo_pgsql mbstring gd zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN npm install
RUN npm run build

RUN chmod -R 775 storage bootstrap/cache

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["docker-entrypoint.sh"]
