ARG BASE_IMAGE_NAME=php-laravel-nginx

# Builder stage
FROM registry.gitlab.com/your-org/docker-images/${BASE_IMAGE_NAME}:8.5 AS builder

WORKDIR /var/www

USER root

# Install git for composer fallback when GitHub API timeout
RUN apk add --no-cache git unzip

# Copy composer files first to leverage Docker cache
COPY ./backend/composer.json ./backend/composer.lock /var/www/

RUN chown -R www:www /var/www

USER www

# Install dependencies without dev packages
RUN composer install \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  --no-dev \
  --optimize-autoloader \
  --no-scripts

# Copy application code
COPY --chown=www:www ./backend /var/www

# Ensure Laravel storage directories exist
USER root
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    && chown -R www:www storage bootstrap/cache
USER www

# Run post-install scripts
RUN composer dump-autoload --optimize

# Final stage
FROM registry.gitlab.com/your-org/docker-images/${BASE_IMAGE_NAME}:8.5

WORKDIR /var/www

USER root

# Copy only necessary files from builder
COPY --from=builder --chown=www:www /var/www /var/www
COPY ./docker/supervisor/laravel-queue.ini /etc/supervisor.d/laravel-queue.ini

USER www
