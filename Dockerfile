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

# Définir le répertoire de travail
WORKDIR /var/www/html

# Copier les fichiers composer d'abord (pour optimiser le cache)
COPY composer.json composer.lock ./

# Installer les dépendances PHP avec ignore-platform-req pour éviter les erreurs d'extension
RUN composer install --optimize-autoloader --no-dev --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip

# Copier le reste du code
COPY . .

# Copier .env.example en .env si .env n'existe pas
RUN cp .env.example .env 2>/dev/null || true

# Configurer les permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80