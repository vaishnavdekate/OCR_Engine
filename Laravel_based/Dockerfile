FROM composer:2.11.3 AS composer
FROM php:8.5-cli-trixie
RUN apt-get update && apt-get install -y --no-install-recommends cmake g++ unzip libicu-dev imagemagick poppler-utils \
    && docker-php-ext-install intl && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    && sh scripts/build-native.sh \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && chmod +x bin/image-tool scripts/container-entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions storage/logs storage/app/ocr-tmp \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENV PDFINFO_BINARY=pdfinfo PDFTOPPM_BINARY=pdftoppm
EXPOSE 8000
ENTRYPOINT ["sh", "scripts/container-entrypoint.sh"]
