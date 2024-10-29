# 使用 PHP 8.1 镜像
FROM php:8.1-cli

# 安装 OPcache 扩展
RUN apt-get update && apt-get install -y libzip-dev \
    && docker-php-ext-install opcache \
    && docker-php-ext-install pdo pdo_mysql

# 拷贝项目文件到容器
COPY . /var/www/html/

# 设置工作目录
WORKDIR /var/www/html/

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 安装项目依赖
RUN composer install --no-interaction

# 配置 OPcache
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
