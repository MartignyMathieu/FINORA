#!/bin/sh
# Démarrage du conteneur : migrations, traitement des tâches en arrière-plan, puis le serveur web.
set -e
cd /app

# Le serveur écoute sur le port imposé par l'hébergeur (PORT) ; le HTTPS est géré en amont.
export SERVER_NAME=":${PORT:-10000}"

# Schéma à jour avant de servir la moindre page (rien à faire si tout est déjà appliqué).
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Option de démonstration : avec LOAD_DEMO_DATA=1, crée (ou remplace) le compte demo@finora.test et ses données.
# --append : ne touche à aucun autre compte. Désactivée par défaut ; à retirer une fois le compte créé.
if [ "${LOAD_DEMO_DATA:-0}" = "1" ]; then
    php bin/console doctrine:fixtures:load --group=demo --append --no-interaction
fi

# E-mails (transport async) et tâches planifiées (récurrences) : un consommateur dans le même conteneur,
# relancé s'il s'arrête (limite de durée ou de mémoire) — pas besoin d'un second service.
(
    while true; do
        php bin/console messenger:consume async scheduler_default --time-limit=3600 --memory-limit=96M --no-interaction -q || true
        sleep 2
    done
) &

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
