FROM php:8.2-apache

# Installer les dépendances système
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    libpq-dev \
    libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        zip \
        exif \
        pcntl \
        bcmath

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copier les fichiers composer
COPY composer.json composer.lock ./

# Installer les dépendances
RUN composer install --no-dev --no-scripts --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip

# Copier le reste du code
COPY . .

# Créer le fichier .env
RUN cp .env.example .env 2>/dev/null || echo "APP_KEY=" > .env

# Configurer les permissions des logs
RUN mkdir -p /var/www/html/storage/logs
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 755 /var/www/html/storage
RUN chmod -R 777 /var/www/html/storage/logs

# Configurer Apache
RUN a2enmod rewrite
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# PHP error logging
RUN echo "display_errors = On" >> /usr/local/etc/php/conf.d/custom.ini
RUN echo "error_log = /var/www/html/storage/logs/php-error.log" >> /usr/local/etc/php/conf.d/custom.ini

EXPOSE 80