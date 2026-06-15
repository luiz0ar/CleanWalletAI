FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --optimize-autoloader --no-dev

RUN groupadd -g 1000 www && useradd -u 1000 -ms /bin/bash -g www www
RUN chown -R www:www /var/www/html
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

USER www

EXPOSE 80
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-80} -t public"]