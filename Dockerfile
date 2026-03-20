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

# Create app user
RUN groupadd -g 1000 www && useradd -u 1000 -ms /bin/bash -g www www

# Copy entrypoint script (optional)

# During build, create a fresh Laravel project (if not mounting code)
# This step may be heavy; you can prefer mounting a host project instead.
RUN composer create-project --prefer-dist laravel/laravel="11.*" . --no-interaction || true

# Set permissions
RUN chown -R www:www /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
