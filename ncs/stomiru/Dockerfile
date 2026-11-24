FROM php:8.3-fpm-alpine

# Install necessary packages
RUN apk add --no-cache \
    nginx \
    supervisor \
    git \
    unzip \
    curl \
    libpng-dev \
    libzip-dev \
    oniguruma-dev

# Install PHP extensions (pdo_mysql, gd, zip, mbstring)
RUN docker-php-ext-install pdo pdo_mysql gd zip mbstring

# Copy Composer from the official image
COPY --from=composer:2.8.4 /usr/bin/composer /usr/bin/composer

# Set timezone
ENV TZ=Asia/Tokyo
RUN apk add --no-cache tzdata && \
    cp /usr/share/zoneinfo/Asia/Tokyo /etc/localtime && \
    echo "Asia/Tokyo" > /etc/timezone

# Set working directory
WORKDIR /var/www

# Configure Nginx
COPY ./docker/build/nginx.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY ./docker/build/supervisord.conf /etc/supervisord.conf

# Grant permissions for the www-data user (default for PHP-FPM)
RUN chown -R www-data:www-data /var/www /var/lib/nginx /var/log/nginx

# Copy Composer files first to leverage Docker cache
COPY --chown=www-data:www-data ./backend/composer.json ./backend/composer.lock /var/www/

# Switch to www-data to install composer dependencies
USER www-data

# Install dependencies (Skip scripts and autoloader to prevent missing artisan file errors)
RUN composer install \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  --no-scripts \
  --no-autoloader

# Copy the rest of the application code
COPY --chown=www-data:www-data ./backend /var/www

# Generate optimized autoloader and run Laravel scripts (e.g., package:discover)
RUN composer dump-autoload --optimize && \
    composer run-script post-autoload-dump || true

# Switch back to root to start Nginx & Supervisor
USER root

# Copy startup script
COPY ./docker/build/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Expose port
EXPOSE 80

# Execute the startup script
CMD ["/usr/local/bin/start.sh"]
