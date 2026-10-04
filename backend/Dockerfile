# ==============================================================================
# TransactIQ Backend Dockerfile
# Production-grade PHP 8.4-FPM container with PostgreSQL, Redis & BCMath
# ==============================================================================

FROM php:8.4-fpm-alpine

# Install system build dependencies and runtime packages
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    curl \
    git \
    unzip \
    linux-headers \
    autoconf \
    build-base

# Install core PHP extensions for financial arithmetic, concurrency and PostgreSQL
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    pcntl \
    opcache \
    zip \
    mbstring

# Install and enable Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# Install Composer from official Composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/backend

# Copy Composer files first to maximize Docker layer caching
COPY composer.json composer.lock ./

# Build argument to control dev dependencies (useful for testing inside Docker)
ARG INSTALL_DEV=false

# Install project dependencies without running scripts/autoloader yet
RUN if [ "$INSTALL_DEV" = "true" ] ; then \
        composer install --no-interaction --no-scripts --prefer-dist ; \
    else \
        composer install --no-dev --no-interaction --no-scripts --prefer-dist ; \
    fi

# Copy the entire backend application code
COPY . .

# Generate production-optimized Composer autoloader
RUN composer dump-autoload --optimize

# Ensure storage and bootstrap/cache permissions for web server
RUN chown -R www-data:www-data /var/www/backend/storage /var/www/backend/bootstrap/cache \
    && chmod -R 775 /var/www/backend/storage /var/www/backend/bootstrap/cache

# Expose PHP-FPM fastcgi port
EXPOSE 9000

# Start PHP FastCGI daemon
CMD ["php-fpm"]
