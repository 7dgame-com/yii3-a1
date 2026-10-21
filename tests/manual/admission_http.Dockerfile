# Local equivalent of setup-node + setup-php. Only public, already cached bases.
FROM node:24-bookworm AS node
FROM php:8.4-cli-bookworm
COPY --from=node /usr/local/bin/node /usr/local/bin/node
RUN docker-php-ext-install -j2 pdo_mysql
