FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libssl-dev \
    pkg-config

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

RUN pecl install mongodb && docker-php-ext-enable mongodb

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# If no composer.json exists (no project mounted), create a fresh Laravel project
RUN if [ ! -f /var/www/composer.json ]; then \
    composer create-project --prefer-dist "laravel/laravel=11.*" /var/www --no-interaction || true; \
fi

# If composer.json is present in the build context, install dependencies into the image
RUN if [ -f /var/www/composer.json ]; then \
    composer install --no-interaction --prefer-dist || true; \
fi

# Ensure permissions for www user
RUN groupadd -g 1000 www || true && useradd -u 1000 -ms /bin/bash -g www www || true
RUN chown -R www:www /var/www || true