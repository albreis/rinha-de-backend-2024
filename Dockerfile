FROM phpswoole/swoole

WORKDIR /var/www

RUN apt-get update && apt-get install -y zip unzip git

RUN apt-get install -y \
        libzip-dev \
        zip \
  && docker-php-ext-install zip

RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN pecl install openswoole-22.1.2

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN pecl install xdebug
# RUN docker-php-ext-install xdebug

RUN sed -i -e "s/upload_max_filesize = .*/upload_max_filesize = 1G/g" \
        -e "s/post_max_size = .*/post_max_size = 1G/g" \
        -e "s/memory_limit = .*/memory_limit = 512M/g" \
        /usr/local/etc/php/php.ini-production \
        && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

# Install yasd (XDebug alternative) and Swoole and use the default development configuration
RUN apt-get update && apt-get install -y dumb-init libboost-all-dev \
    && cd /tmp \
    && curl -L https://github.com/swoole/yasd/archive/v0.3.7.tar.gz --output yasd.tar.gz \
    && mkdir yasd && tar xzf yasd.tar.gz -C yasd --strip-components 1 \
    && cd yasd \
    && phpize --clean \
    && phpize \
    && ./configure \
    && make clean \
    && make \
    && make install \
    && cd / && rm -rf /tmp/* \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN docker-php-ext-install pgsql pdo pdo_pgsql