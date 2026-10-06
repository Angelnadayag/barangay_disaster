# Official PHP 8.2 with Apache base image
FROM php:8.2-apache

# Install required system packages, CA certificates, and PHP extensions
RUN apt-get update && apt-get install -y \
    ca-certificates \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess and URL routing overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files to document root
COPY . /var/www/html/

# Set proper ownership for web server user
RUN chown -R www-data:www-data /var/www/html

# Expose default port
EXPOSE 80

# Dynamically configure Apache to listen on $PORT assigned by Railway, then run apache2
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
