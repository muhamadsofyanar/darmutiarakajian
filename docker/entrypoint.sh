#!/bin/sh
set -eu
mkdir -p /data /var/www/html/assets/uploads /var/www/html/storage/downloads /var/www/html/storage/ticket-attachments /var/www/html/storage/update-tmp
ln -sfn /data/config.php /var/www/html/config.php
ln -sfn /data/install.lock /var/www/html/installer/install.lock
chown -h www-data:www-data /var/www/html/config.php /var/www/html/installer/install.lock
chown -R www-data:www-data /data /var/www/html/assets/uploads /var/www/html/storage
install -d -m 0700 -o www-data -g www-data /data/php-sessions
printf '%s\n' 'session.save_path = "/data/php-sessions"' > /usr/local/etc/php/conf.d/darmutiara-sessions.ini
exec docker-php-entrypoint "$@"
