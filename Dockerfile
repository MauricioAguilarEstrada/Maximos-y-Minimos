# Usamos una imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instalamos las dependencias y los drivers de PostgreSQL (PDO)
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Habilitamos el módulo de reescritura de Apache
RUN a2enmod rewrite

# Copiamos todo tu código a la carpeta pública del servidor
COPY . /var/www/html/

# Exponemos el puerto estándar web
EXPOSE 80