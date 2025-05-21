#!/usr/bin/sh
if [ ! -f /var/idelium-api/idelium.ok ]; then
    cd /var/idelium-api
    composer install -n
    cp .env.docker .env
    php artisan key:generate
    php artisan cache:clear
    php artisan migrate
    php artisan db:seed
    php artisan db:seed --class=DemoProfileSeeder
    touch idelium.ok
fi
exit 0