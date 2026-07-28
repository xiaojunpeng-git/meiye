FROM docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine

RUN apk add --no-cache --virtual .phpize-deps-configure \
      $PHPIZE_DEPS \
      freetype-dev \
      libjpeg-turbo-dev \
      libpng-dev \
      libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install bcmath zip gd \
    && apk add --no-cache freetype libjpeg-turbo libpng libzip

ENTRYPOINT ["sh"]
