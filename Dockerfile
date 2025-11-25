# Use official PHP 8.1 with Apache
FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Copy application files
COPY . /var/www/html/

# Install PHP dependencies
# RUN composer install --no-dev --optimize-autoloader

# Create uploads directory and set permissions
RUN mkdir -p uploads/profiles uploads/reports \
    && chown -R www-data:www-data uploads \
    && chmod -R 755 uploads \
    && cp assets/img/logo.jpg uploads/no-image.png || echo "Logo copied as fallback"

# Set proper permissions for web files
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Create .htaccess for URL rewriting and proper MIME types
RUN echo 'RewriteEngine On\n\
RewriteCond %{REQUEST_FILENAME} !-f\n\
RewriteCond %{REQUEST_FILENAME} !-d\n\
RewriteCond %{REQUEST_URI} !^/(service-worker\.js|manifest\.json|assets/)\n\
RewriteRule ^(.*)$ index.php [QSA,L]\n\
\n\
# Set proper MIME types\n\
<Files "service-worker.js">\n\
    Header set Content-Type "application/javascript"\n\
</Files>\n\
<Files "manifest.json">\n\
    Header set Content-Type "application/json"\n\
</Files>' > /var/www/html/.htaccess

# Expose port 80
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]