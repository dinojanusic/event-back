FROM pimcore/pimcore:php8.5-max-5.x

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN COMPOSER_HOME=/tmp/composer composer install \
    --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

# Pimcore infrastructure transports (AMQP) as a separate config file so it
# doesn't replace the app's messenger.yaml which owns the Redis orders transport
COPY .docker/messenger.railway.yaml config/packages/messenger_pimcore.yaml

RUN mkdir -p var/cache var/log var/assets var/tmp/thumbnails public/var \
    && chown -R www-data:www-data var public/var

EXPOSE 9000
CMD ["php-fpm", "-F"]
