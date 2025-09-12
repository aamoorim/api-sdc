FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_pgsql pgsql

COPY . /var/www/html/
WORKDIR /var/www/html

RUN sed -i 's/80/10000/' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's/80/10000/' /etc/apache2/ports.conf

EXPOSE 10000
CMD ["apache2-foreground"]