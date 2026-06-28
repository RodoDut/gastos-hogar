FROM php:8.2-apache

# Dependencias del sistema que Composer necesita para descargar paquetes
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

# mod_rewrite para los .htaccess
RUN a2enmod rewrite

# VirtualHost apuntando a public/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Instalar dependencias primero (mejor cache de capas)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist

# Copiar el resto del código (sin .env ni vendor gracias a .dockerignore)
COPY . .

# Directorio de datos con permisos correctos para www-data
RUN mkdir -p data && chown -R www-data:www-data data
