# ==============================================================================
# STAGE 1: Composer Dependencies (Build Stage)
# ==============================================================================
FROM php:8.2-cli-alpine AS composer-build

# Install system dependencies for composer and PHP extensions
RUN apk add --no-cache \
    git \
    unzip \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    $PHPIZE_DEPS

# Install PHP extensions required for Laravel
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        gd \
        bcmath \
        zip \
        intl \
        exif \
        opcache \
        mbstring

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
COPY packages ./packages

# Install dependencies (no dev, optimized autoloader)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    --no-scripts

# ==============================================================================
# STAGE 2: Production Runtime (Using PHP CLI - no Apache)
# ==============================================================================
FROM php:8.2-cli-alpine AS production

# Install system dependencies (including Supervisor and cron for queue workers and scheduler)
RUN apk add --no-cache \
    libpng \
    libjpeg-turbo \
    freetype \
    libzip \
    icu-libs \
    oniguruma \
    ca-certificates \
    curl \
    supervisor \
    dcron

# Install build dependencies temporarily for PHP extensions
RUN apk add --no-cache --virtual .build-deps \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        gd \
        bcmath \
        zip \
        intl \
        exif \
        opcache \
        mbstring \
    && apk del .build-deps

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy custom PHP configuration
COPY php-custom.ini $PHP_INI_DIR/conf.d/custom.ini

# Create opcache configuration for production
RUN echo "opcache.enable=1" >> $PHP_INI_DIR/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=128" >> $PHP_INI_DIR/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=8" >> $PHP_INI_DIR/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=10000" >> $PHP_INI_DIR/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq=0" >> $PHP_INI_DIR/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> $PHP_INI_DIR/conf.d/opcache.ini

# Create non-root user
RUN addgroup -g 1000 -S www && adduser -u 1000 -S www -G www

WORKDIR /var/www/html

# Copy application code
COPY --chown=www:www . .

# Copy vendor from build stage
COPY --from=composer-build --chown=www:www /app/vendor ./vendor

# Copy Supervisor configuration (queue workers and web)
COPY deploy/supervisor/supervisord.conf /etc/supervisor/supervisord.conf
COPY deploy/supervisor/*.conf /etc/supervisor/conf.d/
RUN mkdir -p /etc/supervisor/conf.d /var/log/supervisor /var/run \
    && chown -R www:www /etc/supervisor /var/log/supervisor /var/run \
    && chmod -R 755 /etc/supervisor

# Install cron for Laravel scheduler (runs via Supervisor-managed cron)
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" | crontab -u www -

# Create required directories and set permissions
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy and configure entrypoint and release scripts
COPY docker-entrypoint.sh /usr/local/bin/
COPY release.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/release.sh

# Switch to non-root user for security
USER www

# Health check endpoint (web is now managed by Supervisor)
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:${PORT:-8080}/api || exit 1

# Expose port (Render will set PORT env var)
EXPOSE 8080

# Start via entrypoint (Supervisor manages web + workers)
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
