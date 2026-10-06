# Official PHP 8.2 with Apache base image
FROM php:8.2-apache

# Install required system packages, CA certificates, and PHP extensions
RUN apt-get update && apt-get install -y \
    ca-certificates \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Fix MPM conflict: Remove event/worker MPMs and ensure only mpm_prefork is enabled
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
    && a2enmod mpm_prefork rewrite

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

# Clean any conflicting MPMs at startup, bind to Railway's dynamic $PORT, and start Apache
CMD ["sh", "-c", "rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* && a2enmod mpm_prefork 2>/dev/null || true; sed -i \"s/Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf; sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf; apache2-foreground"]
