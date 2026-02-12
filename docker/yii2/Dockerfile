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

# Generate JWT key
RUN mkdir -p runtime && echo "dev-jwt-secret-key-do-not-use-in-production" > runtime/jwt.key

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
