FROM php:8.5-cli

ARG WWWUSER=1000
ARG WWWGROUP=1000

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip curl \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install mbstring zip

# Install Xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create non-root user
RUN groupadd -g ${WWWGROUP} sail \
    && useradd -u ${WWWUSER} -ms /bin/bash -g sail sail

# Copy PHP config
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-custom.ini

# Copy start script
COPY docker/app/container-start.sh /usr/local/bin/container-start.sh
RUN chmod +x /usr/local/bin/container-start.sh

# Git x Composer fix
RUN git config --global --add safe.directory '*'

WORKDIR /var/www/html

CMD ["/usr/local/bin/container-start.sh"]
