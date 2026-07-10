FROM php:8.2-apache

# Enable Apache rewrite module
RUN a2enmod rewrite headers

# Install PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
        libzip-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html/api

# Copy composer files and install deps
COPY api/composer.json api/composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs

# Copy application source
COPY api/ .

# Copy Docker deployment config (has V2 module enabled)
COPY files/api/config/db.php ./config/db.php
COPY files/api/config/web.php ./config/web.php

# Keep signing material out of the image.  The inherited PHP entrypoint is
# wrapped to create a random local-development key at container start only;
# cloud/production containers must receive JWT_SECRET or a mounted key file.
RUN mkdir -p runtime \
    && mv /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint-base \
    && printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        'if [ -n "$${JWT_KEY:-}" ] && [ ! -f "$$JWT_KEY" ]; then' \
        '  if [ "$${DEPLOYMENT_MODE:-}" = "cloud" ] || { [ -z "$${DEPLOYMENT_MODE:-}" ] && { [ "$${APP_ENV:-$${YII_ENV:-development}}" = "production" ] || [ "$${APP_ENV:-$${YII_ENV:-development}}" = "prod" ]; }; }; then' \
        '    if [ -z "$${JWT_SECRET:-}" ]; then echo "JWT_KEY must point to a readable key or JWT_SECRET must be set in production" >&2; exit 1; fi' \
        '  fi' \
        '  mkdir -p "$$(dirname "$$JWT_KEY")"; umask 077' \
        '  if [ -n "$${JWT_SECRET:-}" ]; then printf "%s" "$$JWT_SECRET" > "$$JWT_KEY"' \
        '  elif command -v openssl >/dev/null 2>&1; then openssl rand -hex 32 > "$$JWT_KEY"' \
        '  else head -c 32 /dev/urandom | od -An -tx1 | tr -d " \\n" > "$$JWT_KEY"; fi' \
        '  chown www-data:www-data "$$JWT_KEY"' \
        'fi' \
        'exec /usr/local/bin/docker-php-entrypoint-base "$$@"' \
        > /usr/local/bin/docker-php-entrypoint \
    && chmod +x /usr/local/bin/docker-php-entrypoint

# Set permissions
RUN chown -R www-data:www-data /var/www/html/api \
    && chmod -R 755 /var/www/html/api \
    && mkdir -p runtime web/assets \
    && chmod -R 777 runtime web/assets

# Apache config: point DocumentRoot to web/
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/api/web\n\
    <Directory "/var/www/html/api/web">\n\
        AllowOverride None\n\
        Require all granted\n\
    </Directory>\n\
    RewriteEngine on\n\
    RewriteCond /var/www/html/api/web%{REQUEST_FILENAME} !-f\n\
    RewriteCond /var/www/html/api/web%{REQUEST_FILENAME} !-d\n\
    RewriteRule . /var/www/html/api/web/index.php\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

EXPOSE 80
