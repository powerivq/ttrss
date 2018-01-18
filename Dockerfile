FROM php:7-fpm-alpine

RUN apk add --update --no-cache --virtual .build-deps curl-dev icu-dev libxml2-dev openssl-dev \
    && apk add --update --no-cache bzip2-dev freetype-dev gettext-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include \
    && docker-php-ext-install bz2 curl dom gd gettext mbstring iconv mysqli opcache pdo pdo_mysql phar posix xml xmlrpc \
    && apk del --no-cache .build-deps

COPY php.ini /usr/local/etc/php/

