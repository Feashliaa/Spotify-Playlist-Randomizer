FROM php:8.2-apache

# Enable Apache rewrite (often needed)
RUN a2enmod rewrite

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy only what PHP actually needs
COPY index.php .
COPY callback.php .
COPY getPlaylistTracks.php .
COPY logout.php .
COPY vendor/ ./vendor
COPY script.js .
COPY style.css .
COPY composer.json .
COPY composer.lock .

# Apache expects port 80
EXPOSE 80
