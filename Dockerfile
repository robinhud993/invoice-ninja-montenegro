# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: install PHP dependencies with Composer
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-interaction \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --no-progress \
        --ignore-platform-reqs

COPY . .

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer dump-autoload --optimize --no-scripts

# ---------------------------------------------------------------------------
# Stage 2: runtime image (Apache + PHP)
# ---------------------------------------------------------------------------
FROM php:8.3-apache AS runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data \
    COMPOSER_ALLOW_SUPERUSER=1 \
    SNAPPDF_CHROMIUM_PATH=/usr/bin/chromium

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        chromium \
        curl \
        default-mysql-client \
        fonts-dejavu-core \
        fonts-liberation \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers \
    && printf '%s\n' \
        '[client]' \
        'skip-ssl' \
        'skip-ssl-verify-server-cert' \
        > /etc/mysql/conf.d/zz-local-client.cnf \
    && rm -rf /var/lib/apt/lists/*

# Serve on a non privileged port and point Apache at the Laravel public dir.
RUN sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -i 's#/var/run/apache2#/tmp#g; s#/var/lock/apache2#/tmp#g' /etc/apache2/envvars \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && printf '%s\n' \
        '<Directory /var/www/html/public>' \
        '    Options -Indexes +FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/invoiceninja.conf \
    && a2enconf invoiceninja

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-invoiceninja.ini

COPY --from=vendor /app /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        /var/www/html/storage/app/public \
        /var/www/html/storage/framework/cache \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
    && rm -rf /var/www/html/public/storage \
    && ln -s /var/www/html/storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && mkdir -p /var/www/.config/psysh \
    && chown -R www-data:www-data /var/www/.config \
    && chown www-data:www-data /var/www/html

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=5 \
    CMD curl -s -o /dev/null http://127.0.0.1:8080/ || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["apache2-foreground"]
