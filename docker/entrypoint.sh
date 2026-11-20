#!/bin/sh
set -eu
mkdir -p /data /var/www/html/assets/uploads /var/www/html/storage/downloads /var/www/html/storage/ticket-attachments /var/www/html/storage/update-tmp
ln -sfn /data/config.php /var/www/html/config.php
ln -sfn /data/install.lock /var/www/html/installer/install.lock
chown -h www-data:www-data /var/www/html/config.php /var/www/html/installer/install.lock
chown -R www-data:www-data /data /var/www/html/assets/uploads /var/www/html/storage
exec docker-php-entrypoint "$@"