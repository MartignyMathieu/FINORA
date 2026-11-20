# Image de production (Render ou tout hébergeur Docker) : FrankenPHP (Caddy + PHP) sur le port $PORT, HTTP seulement.
# Le HTTPS est assuré par l'hébergeur (Render) devant le conteneur.
FROM dunglas/frankenphp:1-php8.4

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN install-php-extensions pdo_pgsql intl opcache zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Le binaire porte la capacité « écouter sur un port < 1024 » : un hébergeur qui retire les capacités du conteneur (Render)
# refuse alors de l'exécuter (« Operation not permitted »). Nous écoutons sur $PORT (> 1024) : on la retire.
RUN setcap -r /usr/local/bin/frankenphp

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dépendances d'abord : cette couche n'est reconstruite que si composer.json / composer.lock changent.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

# Variables factices : elles servent seulement à construire le conteneur de dépendances ; les vraies viennent de l'hébergeur.
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && APP_SECRET=build DATABASE_URL="postgresql://build:build@localhost:5432/build" MAILER_DSN=null://null \
       MESSENGER_TRANSPORT_DSN=doctrine://default LOCK_DSN=flock DEFAULT_URI=http://localhost \
       sh -c "php bin/console importmap:install && php bin/console asset-map:compile && php bin/console cache:warmup" \
    && mkdir -p var/share var/cache var/log

RUN chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]
