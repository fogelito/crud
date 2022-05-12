FROM phpswoole/swoole:4.6-php7.4-alpine
FROM composer:2.0 as composer
WORKDIR /app

ENV PHP_SWOOLE_VERSION="v4.8.3"

COPY composer.json /app

RUN composer install --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist

RUN \
  apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  zlib-dev \
  brotli-dev \
  openssl-dev \
  yaml-dev

RUN git clone --depth 1 --branch "${PHP_SWOOLE_VERSION}" https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  phpize && \
  ./configure --enable-http2 && \
  make && make install && \
  cd ..

RUN docker-php-ext-install \
    pdo \
    pdo_mysql

RUN pecl install redis

RUN docker-php-ext-enable \
    swoole \
    pdo \
    pdo_mysql \
    redis

COPY . /app

EXPOSE 8080

CMD [ "php", "app/server.php" ]
