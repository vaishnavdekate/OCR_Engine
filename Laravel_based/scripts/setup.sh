#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs storage/app/ocr-tmp
if command -v composer >/dev/null 2>&1; then
    composer install --no-interaction --prefer-dist
elif [ -f tools/composer.phar ]; then
    php tools/composer.phar install --no-interaction --prefer-dist
else
    echo 'Install Composer from https://getcomposer.org/download/ first.' >&2
    exit 1
fi
if [ ! -f .env ]; then cp .env.example .env; fi
if ! php -r "require 'vendor/autoload.php'; \$values=Dotenv\\Dotenv::parse(file_get_contents('.env')); exit(empty(\$values['APP_KEY']) ? 1 : 0);"; then
    php artisan key:generate --force
fi
chmod +x bin/image-tool
sh scripts/build-native.sh
