FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_pgsql pgsql

COPY . /var/www/html/

WORKDIR /var/www/html

EXPOSE 8080

CMD ["apache2-foreground"]
