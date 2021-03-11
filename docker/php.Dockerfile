FROM php:7.4-fpm
RUN apt-get update && apt-get install -y \
    && rm -rf /var/lib/apt/lists/* \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && sync && \
    install-php-extensions mysqli pdo_mysql sockets imagick mcrypt swoole exif gettext memcache redis bz2 zip intl ldap memcached
VOLUME /etc/letsencrypt
CMD ["php-fpm"]

EXPOSE 9000
