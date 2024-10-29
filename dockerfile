# 使用 php:8.1.30-cli-alpine3.20 作为基础镜像
FROM php:8.1.30-cli-alpine3.20

# 更改为阿里云的 Alpine 软件源并更新索引
RUN sed -i 's|dl-cdn.alpinelinux.org|mirrors.aliyun.com|g' /etc/apk/repositories \
    && apk update \
    && apk add --no-cache \
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
        gcc \
        g++ \
        make \
        autoconf \
        libc-dev \
        openssl-dev \
        libaio-dev \
        linux-headers \
        brotli-dev

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-xpm --with-avif --with-freetype=/usr/include/freetype2 --with-jpeg=/usr/include\
        && docker-php-ext-install gd \
        && docker-php-ext-install zip \
        && docker-php-ext-install pdo pdo_mysql \
        && docker-php-ext-enable opcache \
        && pecl install swoole \
        && docker-php-ext-enable swoole \
        && rm -rf /var/cache/apk/*

# 将应用程序代码复制到容器中
COPY . /app
# 设置工作目录
WORKDIR /app
# 安装composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# 安装项目依赖
RUN composer install --no-interaction --no-dev --optimize-autoloader
# 暴露端口
EXPOSE 8000
# 复制计划任务配置文件
COPY scripts/opiptask /etc/crontabs/root
# 复制进程守护配置文件
COPY scripts/supervisord.conf /etc/supervisord.conf
# 授权启动脚本
RUN chmod +x /app/scripts/run_tasks.sh
# 复制opcache配置文件
COPY scripts/opcache.ini /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
# 运行进程守护应用
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
