FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev libzip-dev zip unzip git curl

RUN docker-php-ext-install pdo pdo_pgsql pdo_mysql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Supprimer complètement l'ancien cache
RUN rm -rf vendor/ composer.lock

# Réinstaller sans pail
RUN composer install --no-dev --no-scripts --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip

RUN a2enmod rewrite
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80