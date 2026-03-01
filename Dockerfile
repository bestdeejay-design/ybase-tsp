FROM php:8.4-fpm

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    libxml2-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Установка PHP расширений
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    curl \
    dom \
    mbstring

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка рабочей директории
WORKDIR /var/www

# Копирование composer файлов
COPY composer.json composer.lock* ./

# Установка зависимостей
RUN composer install --no-scripts --no-autoloader

# Команда по умолчанию
CMD ["php-fpm"]
