FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_pgsql pgsql

# HABILITAR MOD_REWRITE
RUN a2enmod rewrite

# COPIAR OS ARQUIVOS
COPY . /var/www/html/
WORKDIR /var/www/html

# PERMITIR USO DE .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# USAR PORTA DO RENDER
ENV PORT=8080
EXPOSE ${PORT}

# AJUSTAR A CONFIG DO VHOST
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf && \
    sed -i "s/80/\${PORT}/g" /etc/apache2/ports.conf

CMD ["apache2-foreground"]
