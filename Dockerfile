FROM php:8.2-apache

# Installer GD et les dépendances système
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
    zip unzip git curl libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_pgsql pdo_mysql zip

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Installer les dépendances
RUN composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-gd

# Créer le fichier .env avec la clé déjà définie
RUN echo "APP_KEY=base64:dTxnUzGkZgJZpJxYxVpLyQnLkZtGqWkRcFvHmNpLmE=" > .env
RUN echo "APP_ENV=production" >> .env
RUN echo "APP_DEBUG=false" >> .env

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Configuration Apache
RUN a2enmod rewrite
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

EXPOSE 80