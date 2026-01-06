FROM php:8.2-apache

RUN a2enmod rewrite

# Install system deps needed by Composer
RUN apt-get update && apt-get install -y unzip git \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first (better caching)
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy app files
COPY index.php .
COPY callback.php .
COPY getPlaylistTracks.php .
COPY logout.php .
COPY script.js .
COPY style.css .
COPY setup_db.php .

EXPOSE 80