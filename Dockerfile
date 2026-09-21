FROM php:8.4-fpm-alpine

ARG TTRSS_COMMIT=820aeae2ce957c075568b9e7cec28c44f33cfc8a

ENV CI_COMMIT_SHORT_SHA=1
ENV CI_COMMIT_TIMESTAMP=1
ENV TTRSS_AUTO_SUMMARY_WORKERS=1

RUN apk add --no-cache supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
        bzip2-dev freetype-dev gettext-dev gmp-dev icu-dev libjpeg-turbo-dev libpng-dev postgresql-dev \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include \
    && docker-php-ext-install -j"$(nproc)" bz2 gd gmp gettext intl pcntl pdo_pgsql \
    && run_deps="$(scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
        | tr ',' '\n' \
        | sort -u \
        | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }')" \
    && apk add --no-cache --virtual .php-ext-runtime-deps $run_deps \
    && apk del --no-cache .build-deps

COPY php-custom.ini /usr/local/etc/php/conf.d/

# https://github.com/tt-rss/tt-rss
COPY patch /tmp/patch

# https://github.com/levito/tt-rss-feedly-theme
# 20230520
COPY theme.zip /tmp/

# https://github.com/DigitalDJ/tinytinyrss-fever-plugin
# 20230520
COPY fever-plugin.zip /tmp/

# https://github.com/powerivq/ttrss-headlineflow-plugin
COPY powerivq /tmp/powerivq
COPY af_proxy_http /tmp/af_proxy_http
COPY openai_auto_summary /tmp/openai_auto_summary
COPY openai_auto_tag /tmp/openai_auto_tag
COPY jev_auto_tag /tmp/jev_auto_tag

COPY config.php /tmp/config.php
RUN wget -O /tmp/ttrss.zip "https://codeload.github.com/tt-rss/tt-rss/zip/${TTRSS_COMMIT}" \
    && unzip /tmp/ttrss.zip -d /tmp \
    && mv "/tmp/tt-rss-${TTRSS_COMMIT}" /rss \
    && mv /tmp/config.php /rss/config.php \
    && cd /rss \
    && apk add --no-cache patch \
    && patch -p1 -i /tmp/patch \
    && apk del --no-cache patch \
    && unzip /tmp/theme.zip -d /tmp \
    && mv /tmp/tt-rss-feedly-theme-dist/*.css /rss/themes/ \
    && mv /tmp/tt-rss-feedly-theme-dist/feedly /rss/themes/ \
    && unzip /tmp/fever-plugin.zip -d /tmp \
    && mv /tmp/tinytinyrss-fever-plugin-master /rss/plugins.local/fever \
    && wget -O /tmp/headlineflow-plugin.zip https://codeload.github.com/powerivq/ttrss-headlineflow-plugin/zip/refs/heads/main \
    && unzip /tmp/headlineflow-plugin.zip -d /tmp \
    && mv /tmp/ttrss-headlineflow-plugin-main/api_headlineflow /rss/plugins.local/api_headlineflow \
    && mv /tmp/powerivq /rss/plugins.local/powerivq \
    && mv /tmp/af_proxy_http /rss/plugins.local/af_proxy_http \
    && mv /tmp/openai_auto_summary /rss/plugins.local/openai_auto_summary \
    && mv /tmp/openai_auto_tag /rss/plugins.local/openai_auto_tag \
    && mv /tmp/jev_auto_tag /rss/plugins.local/jev_auto_tag \
    && mkdir pusher && cd pusher \
    && wget https://github.com/powerivq/ttrss-pusher/releases/download/2.0.2/release.zip \
    && unzip release.zip && rm release.zip && cd .. \
    && mv pusher plugins.local/ \
    && rm -rf /tmp/* /rss/feed-icons \
    && mkdir -p /cache/images /cache/upload /cache/export /cache/js /lock /feed-icons \
    && chmod -R 777 /cache /lock \
    && chmod 755 /rss/config.php \
    && ln -s /feed-icons /rss/feed-icons

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
WORKDIR /rss
ENTRYPOINT ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
