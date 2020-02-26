FROM php:7-fpm-alpine

RUN apk add --update --no-cache --virtual .build-deps curl-dev libxml2-dev libressl-dev pcre-dev \
    && apk add --update --no-cache bzip2-dev freetype-dev gettext-dev icu-dev libjpeg-turbo-dev libpng-dev oniguruma-dev supervisor \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include \
    && docker-php-ext-install bz2 curl dom gd gettext mbstring opcache iconv intl mysqli opcache pcntl pdo pdo_mysql phar posix xml xmlrpc \
    && apk del --no-cache .build-deps

COPY php-custom.ini /usr/local/etc/php/conf.d/

# https://git.tt-rss.org/fox/tt-rss
# 20200224
ADD ttrss.tar.gz /
COPY patch /tmp/patch

# https://github.com/levito/tt-rss-feedly-theme
# 20200222
COPY theme.zip /tmp/

# https://github.com/DigitalDJ/tinytinyrss-fever-plugin
# 20180527
COPY fever-plugin.zip /tmp/

# https://github.com/hrk/tt-rss-newsplus-plugin
# 20180121
COPY newsplus-plugin.zip /tmp/

COPY config.php /tt-rss/
RUN mv /tt-rss /rss \
    && cd /rss \
    && patch -p1 -i /tmp/patch \
    && unzip /tmp/theme.zip -d /tmp \
    && mv /tmp/tt-rss-feedly-theme-master/*.css /rss/themes/ \
    && mv /tmp/tt-rss-feedly-theme-master/feedly /rss/themes/ \
    && unzip /tmp/fever-plugin.zip -d /tmp \
    && mv /tmp/tinytinyrss-fever-plugin-master /rss/plugins.local/fever \
    && unzip /tmp/newsplus-plugin.zip -d /tmp \
    && mv /tmp/tt-rss-newsplus-plugin-master/api_newsplus /rss/plugins.local/api_newsplus \
    && rm -rf /tmp/* \
    && mkdir -p /cache/images /cache/upload /cache/export /cache/js /lock \
    && chmod -R 777 /cache /lock /rss/feed-icons \
    && chmod 755 /rss/config.php

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
WORKDIR /rss
ENTRYPOINT ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

