FROM randock/docker-ubuntu-php:7.2-dev

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php-fpm7.2.conf /etc/php/7.2/fpm/php-fpm.conf

ENTRYPOINT ["supervisord", "--nodaemon", "--configuration", "/etc/supervisor/conf.d/supervisord.conf"]
