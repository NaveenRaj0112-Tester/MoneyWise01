# MoneyWise — PHP 8.2 + Apache runtime
FROM php:8.2-apache

# mbstring build dependency (oniguruma). php:8.2-apache does not ship mbstring.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions required by the app (PDO MySQL + mb_* string helpers)
RUN docker-php-ext-install pdo pdo_mysql mbstring

# Application source (assets/, api/, Mlogo/ and *.php pages)
COPY . /var/www/html/

# Entrypoint: wait for MySQL, run install.php (idempotent), start Apache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

CMD ["docker-entrypoint.sh"]
