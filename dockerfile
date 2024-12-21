# 使用 php:8.1.30-cli-alpine3.20 作为基础镜像
FROM php:8.1.30-cli-alpine3.20

# 更改为阿里云的软件源并更新索引
RUN sed -i 's|dl-cdn.alpinelinux.org|mirrors.aliyun.com|g' /etc/apk/repositories \
    && apk update

# 安装基本的构建工具和 PHP 扩展依赖
RUN apk add --no-cache \
        supervisor \
        curl \
        bash \
        libzip-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        libxpm-dev \
        libavif-dev \
        icu-dev \
        gcc \
        g++ \
        make \
        autoconf \
        libc-dev \
        openssl-dev \
        libaio-dev \
        linux-headers \
        brotli-dev

# 配置并安装 PHP 扩展 gd
RUN docker-php-ext-configure gd \
        --with-jpeg=/usr/include \
        --with-webp \
        --with-xpm \
        --with-avif \
        --with-freetype=/usr/include/freetype2
RUN docker-php-ext-install gd

# 安装其他 PHP 扩展
RUN docker-php-ext-install zip
RUN docker-php-ext-install pdo
RUN docker-php-ext-install pdo_mysql
RUN docker-php-ext-install intl

# 启用 opcache 扩展
RUN docker-php-ext-enable opcache

# 安装并启用 swoole 扩展
RUN pecl install swoole \
    && docker-php-ext-enable swoole

# 清理 APK 缓存
RUN rm -rf /var/cache/apk/* /tmp/*

# 设置工作目录并复制项目文件
WORKDIR /app
COPY . /app

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-interaction --no-dev --optimize-autoloader

# 配置相关文件
COPY scripts/opcache.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
COPY scripts/supervisord.conf /etc/supervisord.conf

# 安装计划任务配置
COPY scripts/opiptask /etc/crontabs/root
RUN chmod 600 /etc/crontabs/root

# 授权启动脚本
RUN chmod +x /app/scripts/run_tasks.sh

# 暴露端口
EXPOSE 8000

# 设置默认命令
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
