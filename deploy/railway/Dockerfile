# syntax=docker/dockerfile:1
# ============================================================================
# OpenEMR — Railway deploy image (builds THIS fork's local source)
# ============================================================================
# This is a fork-aware variant of docker/release/Dockerfile.
#
# The stock docker/release/Dockerfile fetches OpenEMR with
#   git clone https://github.com/openemr/openemr.git
# which would ship UPSTREAM master, not the local working tree. For deploying
# a fork we instead COPY the local repo into the build.
#
# Build context MUST be the repository root, e.g. from the repo root:
#   docker build -f deploy/railway/Dockerfile -t openemr-fork .
# Railway does this automatically when build.dockerfilePath points here
# (see railway.json). Config assets (php.ini, openemr.sh, ...) still live in
# docker/release/, so those COPY paths are prefixed with docker/release/.
# ============================================================================

ARG ALPINE_VERSION=3.23
FROM alpine:${ALPINE_VERSION} AS base

ARG PHP_VERSION=8.5
ENV PHP_VERSION=${PHP_VERSION}
ARG PHP_VERSION_ABBR=${PHP_VERSION//./}
ENV PHP_VERSION_ABBR=${PHP_VERSION_ABBR}

RUN apk --no-cache upgrade

# System packages (see docker/release/Dockerfile for the annotated list)
RUN apk add --no-cache \
    apache2 \
    apache2-proxy \
    apache2-ssl \
    apache2-utils \
    bash \
    certbot \
    curl \
    dcron \
    git \
    imagemagick \
    mariadb-client \
    mariadb-connector-c \
    ncurses \
    nodejs \
    npm \
    openssl \
    openssl-dev \
    perl \
    rsync \
    shadow \
    su-exec \
    tar

# PHP + extensions required by OpenEMR
RUN apk add --no-cache \
    php${PHP_VERSION_ABBR} \
    php${PHP_VERSION_ABBR}-apache2 \
    php${PHP_VERSION_ABBR}-bcmath \
    php${PHP_VERSION_ABBR}-calendar \
    php${PHP_VERSION_ABBR}-ctype \
    php${PHP_VERSION_ABBR}-curl \
    php${PHP_VERSION_ABBR}-dom \
    php${PHP_VERSION_ABBR}-fileinfo \
    php${PHP_VERSION_ABBR}-fpm \
    php${PHP_VERSION_ABBR}-gd \
    php${PHP_VERSION_ABBR}-iconv \
    php${PHP_VERSION_ABBR}-intl \
    php${PHP_VERSION_ABBR}-ldap \
    php${PHP_VERSION_ABBR}-mbstring \
    php${PHP_VERSION_ABBR}-mysqli \
    php${PHP_VERSION_ABBR}-openssl \
    php${PHP_VERSION_ABBR}-pdo \
    php${PHP_VERSION_ABBR}-pdo_mysql \
    php${PHP_VERSION_ABBR}-pecl-apcu \
    php${PHP_VERSION_ABBR}-pecl-imagick \
    php${PHP_VERSION_ABBR}-phar \
    php${PHP_VERSION_ABBR}-pecl-redis \
    php${PHP_VERSION_ABBR}-session \
    php${PHP_VERSION_ABBR}-simplexml \
    php${PHP_VERSION_ABBR}-soap \
    php${PHP_VERSION_ABBR}-sockets \
    php${PHP_VERSION_ABBR}-sodium \
    php${PHP_VERSION_ABBR}-tokenizer \
    php${PHP_VERSION_ABBR}-xml \
    php${PHP_VERSION_ABBR}-xmlreader \
    php${PHP_VERSION_ABBR}-xmlwriter \
    php${PHP_VERSION_ABBR}-xsl \
    php${PHP_VERSION_ABBR}-zip \
    php${PHP_VERSION_ABBR}-zlib

RUN sed -i 's/^Listen 80$/Listen 0.0.0.0:80/' /etc/apache2/httpd.conf
RUN usermod -u 1000 apache
RUN ln -sf /usr/bin/php${PHP_VERSION_ABBR} /usr/bin/php
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer

# ============================================================================
# Stage 1: OpenEMR source — the local fork tree (NOT an upstream git clone)
# ============================================================================
FROM base AS openemr-source
COPY . /openemr
# Drop VCS metadata / any stray build artifacts if present (belt-and-suspenders;
# .dockerignore already excludes .git, vendor, node_modules).
RUN rm -rf /openemr/.git /openemr/vendor /openemr/node_modules

# ============================================================================
# Stage 2: Composer dependencies
# ============================================================================
FROM base AS openemr-composer
COPY --from=openemr-source /openemr /openemr
WORKDIR /openemr
# NOTE: no --mount=type=cache here. Railway's Metal builder rejects BuildKit
# cache mounts that lack an id; plain RUN is portable across local + Railway.
RUN composer install --no-dev --optimize-autoloader \
    && composer dump-autoload --optimize --apcu

# ============================================================================
# Stage 3: Frontend assets (npm build)
# ============================================================================
FROM base AS openemr-assets
COPY --from=openemr-source /openemr /openemr
WORKDIR /openemr
RUN apk add --no-cache build-base nodejs npm
# No --mount=type=cache (see composer stage note).
RUN npm install --unsafe-perm \
    && npm run build \
    && cd ccdaservice \
    && npm install --unsafe-perm \
    && cd .. \
    && rm -fr node_modules \
    && apk del --no-cache build-base

# ============================================================================
# Stage 4: Final assembly
# ============================================================================
FROM base AS production
COPY --from=openemr-source /openemr /tmp/openemr
COPY --from=openemr-composer /openemr/vendor /tmp/openemr/vendor
COPY --from=openemr-composer /openemr/composer.json /tmp/openemr/composer.json
COPY --from=openemr-composer /openemr/composer.lock /tmp/openemr/composer.lock
COPY --from=openemr-assets /openemr/public /tmp/openemr/public
COPY --from=openemr-assets /openemr/ccdaservice /tmp/openemr/ccdaservice

RUN cd /tmp \
    && find openemr -type d -exec chmod 500 {} + \
    && find openemr -type f -exec chmod 400 {} + \
    && chmod 666 openemr/sites/default/sqlconf.php \
    && chmod 700 openemr/sites/default \
    && find openemr/sites/default/documents -type d -exec chmod 700 {} + \
    && find openemr/sites/default/documents -type f -exec chmod 600 {} + \
    && chown -R apache:apache openemr/ \
    && mv openemr /var/www/localhost/htdocs/ \
    && mkdir -p /etc/ssl/certs /etc/ssl/private \
    && sed -i 's/^ *CustomLog/#CustomLog/' /etc/apache2/httpd.conf \
    && sed -i 's/^ *ErrorLog/#ErrorLog/' /etc/apache2/httpd.conf \
    && sed -i 's/^ *CustomLog/#CustomLog/' /etc/apache2/conf.d/ssl.conf \
    && sed -i 's/^ *TransferLog/#TransferLog/' /etc/apache2/conf.d/ssl.conf

WORKDIR /var/www/localhost/htdocs/openemr
# NOTE: no VOLUME instruction. Railway forbids Dockerfile VOLUME (it manages
# persistence via Railway Volumes — the sites/ volume is attached separately).
ENV APACHE_LOG_DIR=/var/log/apache2

COPY docker/release/php.ini /etc/php${PHP_VERSION_ABBR}/php.ini
COPY docker/release/openemr.conf /etc/apache2/conf.d/

COPY docker/release/openemr.sh \
     docker/release/ssl.sh \
     docker/release/xdebug.sh \
     docker/release/auto_configure.php \
     /var/www/localhost/htdocs/openemr/

COPY docker/release/utilities/unlock_admin.php \
     docker/release/utilities/unlock_admin.sh \
     /root/

RUN chmod 500 openemr.sh ssl.sh xdebug.sh /root/unlock_admin.sh \
    && chmod 000 auto_configure.php /root/unlock_admin.php

COPY docker/release/upgrade/docker-version \
     docker/release/upgrade/fsupgrade-1.sh \
     docker/release/upgrade/fsupgrade-2.sh \
     docker/release/upgrade/fsupgrade-3.sh \
     docker/release/upgrade/fsupgrade-4.sh \
     docker/release/upgrade/fsupgrade-5.sh \
     docker/release/upgrade/fsupgrade-6.sh \
     docker/release/upgrade/fsupgrade-7.sh \
     docker/release/upgrade/fsupgrade-8.sh \
     docker/release/upgrade/fsupgrade-9.sh \
     docker/release/upgrade/fsupgrade-10.sh \
     docker/release/upgrade/fsupgrade-11.sh \
     /root/

RUN chmod 500 \
    /root/fsupgrade-1.sh \
    /root/fsupgrade-2.sh \
    /root/fsupgrade-3.sh \
    /root/fsupgrade-4.sh \
    /root/fsupgrade-5.sh \
    /root/fsupgrade-6.sh \
    /root/fsupgrade-7.sh \
    /root/fsupgrade-8.sh \
    /root/fsupgrade-9.sh \
    /root/fsupgrade-10.sh \
    /root/fsupgrade-11.sh

RUN mkdir -p /run/apache2

COPY docker/release/utilities/devtoolsLibrary.source /root/

RUN mkdir /swarm-pieces \
    && rsync --owner --group --perms --delete --recursive --links /etc/ssl /swarm-pieces/ \
    && rsync --owner --group --perms --delete --recursive --links /var/www/localhost/htdocs/openemr/sites /swarm-pieces/

CMD [ "./openemr.sh" ]
EXPOSE 80 443
