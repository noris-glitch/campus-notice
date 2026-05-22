FROM php:8.2-cli

RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo_pgsql

WORKDIR /app
COPY php.ini /usr/local/etc/php/conf.d/campusnotice.ini
COPY . /app/
EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "/app"]
