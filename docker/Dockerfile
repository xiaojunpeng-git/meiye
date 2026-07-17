FROM docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine

# 基础镜像已含 swoole/redis/pdo_mysql/mbstring
# gd：登录验证码；bcmath：订单/欠款金额计算（bcadd/bcsub）
RUN apk add --no-cache \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd bcmath zip

WORKDIR /var/www/html

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 20700

ENTRYPOINT ["/entrypoint.sh"]
