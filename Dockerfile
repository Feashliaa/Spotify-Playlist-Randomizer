# Use official PHP 8.2 Apache image
FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Install system dependencies and PHP extensions needed by Composer & PostgreSQL
RUN apt-get update && apt-get install -y \
    unzip \
    zip \
    git \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files first for caching
COPY composer.json composer.lock ./

# Install PHP dependencies via Composer
RUN composer install --no-dev --optimize-autoloader

# Copy app files
COPY index.php callback.php getPlaylistTracks.php logout.php setup_db.php script.js style.css ./

# Expose Apache port
EXPOSE 80
