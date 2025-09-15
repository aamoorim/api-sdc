FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_pgsql pgsql

# HABILITAR O MOD_REWRITE DO APACHE
RUN a2enmod rewrite

# COPIAR OS ARQUIVOS DO PROJETO
COPY . /var/www/html/
WORKDIR /var/www/html

# GARANTIR QUE .htaccess FUNCIONE CORRETAMENTE
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# ALTERAR PORTAS PARA RENDER
RUN sed -i 's/80/10000/' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's/80/10000/' /etc/apache2/ports.conf

EXPOSE 10000

CMD ["apache2-foreground"]
