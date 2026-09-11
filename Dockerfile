# MoneyWise — PHP 8.2 + Apache runtime
FROM php:8.2-apache

# Build dependencies: oniguruma for mbstring, libzip for zip. php:8.2-apache ships neither,
# and docker-php-ext-install zip fails the whole image build without libzip-dev.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions required by the app (PDO MySQL + mb_* string helpers + ZIP for app updates)
RUN docker-php-ext-install pdo pdo_mysql mbstring zip

# mod_headers lets .htaccess send no-cache for manifest.json / service-worker.js
RUN a2enmod headers

# Application source (assets/, api/, Mlogo/ and *.php pages)
COPY . /var/www/html/

# Entrypoint: wait for MySQL, run install.php (idempotent), start Apache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

CMD ["docker-entrypoint.sh"]
