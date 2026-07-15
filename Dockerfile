FROM php:8.3-fpm

# Устанавливаем WorkingDir
WORKDIR /var/www/html

# Устанавливаем зависимости
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Пользователь и группа
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

#PHP.ini настройки
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# Установка по умолчанию composer
RUN composer config --global process-timeout 3000

EXPOSE 9000

CMD ["php-fpm"]