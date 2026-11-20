# syntax=docker/dockerfile:1
#
# 证照到期管理系统 —— 飞牛 fnOS 单容器镜像
#
# 容器内同时运行：nginx + php-fpm + 定时任务（supervisor 托管），
# 数据库 MySQL 8 与 Redis 7 由 docker-compose.yml 中的独立容器提供。
#
# 说明：
#   - backend/vendor 与 frontend 构建产物（backend/public/web、backend/public/mobile）
#     均随源码分发，构建时无需联网执行 composer / npm。
#   - OPcache 关闭时间戳校验（与生产一致），修改 PHP 代码后必须重建镜像。
#
# 基础镜像可通过构建参数切换为阿里云 ACR，避免构建机访问 Docker Hub
ARG PHP_BASE_IMAGE=php:8.2-fpm-bookworm
FROM ${PHP_BASE_IMAGE}

ENV TZ=Asia/Shanghai \
    DEBIAN_FRONTEND=noninteractive

# 系统依赖：
#   nginx / supervisor      容器内进程托管
#   libzip/libpng/jpeg/onig PHP 扩展编译依赖
#   libfreetype6            验证码 imagettftext 需要
#   default-mysql-client    在线升级/备份所需的 mysql、mysqldump
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        nginx \
        supervisor \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        default-mysql-client \
        ca-certificates \
        curl \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展：与业务实际使用保持一致
#   pdo_mysql 数据库 / zip 导入导出 / gd 验证码 / bcmath SM2 国密运算 / redis 强依赖
#   mbstring 与 opcache 已由官方 php:8.2-fpm-bookworm 基础镜像内置（opcache 已默认启用），
#   不要再 docker-php-ext-install，否则会重复编译并产生 “already loaded” 警告。
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd bcmath \
    && yes '' | pecl install redis \
    && docker-php-ext-enable redis

# 运行参数与 OPcache
COPY docker/php-app.ini /usr/local/etc/php/conf.d/zz-php-app.ini
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# php-fpm：保留容器环境变量（GOV 加密密钥等通过 PHP_* 注入）
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-fnos.conf

# nginx：用自定义主配置（main 层 worker 性能参数 + http 层），conf.d 下放 server 块
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx-main.conf /etc/nginx/nginx.conf
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf.dpkg-dist

# supervisor 进程托管
COPY docker/supervisord.conf /etc/supervisor/conf.d/fnos.conf

# 入口脚本、CLI 安装器、健康检查、定时任务
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/install-cli.php /usr/local/bin/fnos-install.php
COPY docker/healthcheck.php /usr/local/bin/fnos-healthcheck.php
COPY docker/scheduler.sh /usr/local/bin/fnos-scheduler.sh

# 应用代码（vendor、web、mobile 均已包含）
COPY backend /var/www/backend

RUN mkdir -p \
        /data/config \
        /var/www/backend/runtime/backup \
        /var/www/backend/runtime/cache \
        /var/www/backend/runtime/export \
        /var/www/backend/runtime/log \
        /var/www/backend/runtime/temp \
        /var/www/backend/runtime/upgrade \
        /var/www/backend/runtime/upload \
        /var/www/backend/runtime/vault \
        /var/www/backend/public/uploads \
    && chown -R www-data:www-data \
        /var/www/backend/runtime \
        /var/www/backend/public/uploads \
        /data/config \
    && chmod +x \
        /usr/local/bin/entrypoint.sh \
        /usr/local/bin/fnos-scheduler.sh \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && php -l /usr/local/bin/fnos-install.php \
    && php -l /usr/local/bin/fnos-healthcheck.php

WORKDIR /var/www/backend

EXPOSE 80

STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
