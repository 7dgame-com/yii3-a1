FROM php:8.4-cli

# System deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev unzip git \
    && docker-php-ext-install pdo_mysql zip \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Ensure $_ENV is populated from environment variables
RUN echo "variables_order = EGPCS" > /usr/local/etc/php/conf.d/env.ini

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Install deps first (layer cache)
COPY composer.json ./
RUN composer install --no-interaction --no-scripts --prefer-dist || true

# Copy source
COPY . .

# Re-run to pick up autoload & scripts
RUN composer install --no-interaction --prefer-dist

# The signing key is injected at runtime.  Do not bake a known key into the
# image, even for development: images are frequently promoted between stages.
RUN mkdir -p /app/runtime

EXPOSE 8080

ENTRYPOINT ["sh", "docker/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
