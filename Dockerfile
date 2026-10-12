FROM php:8.2-apache

# Match www-data's UID/GID to the host user (1000) so bind-mounted
# files in /var/www/html/uploads are writable by PHP from inside the container.
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# Install system dependencies (including oniguruma for mbstring)
RUN apt-get update && apt-get install -y \
    libonig-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    mysqli \
    mbstring \
    zip \
    && a2enmod rewrite

# PHP configuration
RUN echo "upload_max_filesize = 50M" > /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/custom.ini

# Enable .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/html>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]
