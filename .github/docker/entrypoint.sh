#!/bin/bash

set -e

if [ ! -f /app/www/public/index.php ] || [ ! -f /app/firstrun ]; then
    echo 'Copying new files'
    \cp -a /usr/src/www /app/

    if [ -d /app/www/runtime/cache ]; then
        rm -rf /app/www/runtime/*
    fi

    chown -R www.www /app/www

    touch /app/firstrun
fi

exec "$@"