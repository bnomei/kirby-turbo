FROM dunglas/frankenphp:1.12.1-php8.4-bookworm

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        imagemagick \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libmemcached-dev \
        libpng-dev \
        libssl-dev \
        libsasl2-dev \
        libwebp-dev \
        libzip-dev \
        libzstd-dev \
        pkg-config \
        unzip \
        zlib1g-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        exif \
        gd \
        intl \
        zip \
    && pecl install apcu \
    && printf "\n\n\n\n\n\n" | pecl install redis \
    && printf "/usr\n/usr\nno\nno\nno\nno\nno\nyes\nyes\n" | pecl install memcached \
    && docker-php-ext-enable apcu redis memcached \
    && rm -rf /var/lib/apt/lists/*
