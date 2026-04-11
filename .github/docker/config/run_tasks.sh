#!/bin/bash

if [ -f "/app/www/.env" ]; then
    php /app/www/think dmtask
else
    exit 0
fi
