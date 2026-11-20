# Déploiement sur Render

FINORA se déploie avec le `Dockerfile` et le blueprint `render.yaml` : un service web (Docker) et une base PostgreSQL.
Render fournit le **HTTPS** : l'application devient alors installable sur Android (plein écran) sans manipulation.

## Mise en ligne

1. Pousser le dépôt sur GitHub.
2. Sur render.com : **New → Blueprint**, choisir le dépôt. Render lit `render.yaml` et crée `finora-db` et `finora`.
3. Renseigner les trois variables demandées (marquées `sync: false`, jamais écrites dans le dépôt) :
   - `MAILER_DSN` : `null://null` pour ne rien envoyer, ou le DSN SMTP du fournisseur (`smtp://utilisateur:motdepasse@serveur:587`) ;
   - `MAILER_FROM` : l'adresse d'expédition (d'un domaine que vous possédez) ;
   - `DEFAULT_URI` : l'adresse publique du service, `https://<nom>.onrender.com`.
4. Attendre la fin du déploiement : au démarrage, le conteneur applique les migrations puis sert l'application.
   Le contrôle de santé interroge `/login`.

## Ce que fait le conteneur

- **FrankenPHP** (Caddy + PHP 8.4) écoute sur `$PORT`, en HTTP : le HTTPS est terminé par Render.
  `trusted_proxies` (config/packages/framework.yaml, environnement `prod`) lit alors le protocole dans `X-Forwarded-*`.
- **Migrations** : `doctrine:migrations:migrate` à chaque démarrage (sans effet si tout est appliqué).
- **Tâches en arrière-plan** : un consommateur `messenger:consume async scheduler_default` tourne dans le même conteneur
  (e-mails, génération quotidienne des récurrences), relancé s'il s'arrête. Pas de second service à payer.
  Si le service est mis en veille (offre gratuite), les récurrences manquées sont rattrapées au réveil.
- **Sessions** : stockées en base (table `sessions`), elles survivent aux redéploiements.
- **Journaux** : sur la sortie d'erreur, au format JSON (onglet Logs de Render).

## Compte de démonstration en ligne

Pour présenter l'application avec des données réalistes, sans ouvrir la base :

1. Dans Render, service **finora** → **Environment** → **Add Environment Variable** : clé `LOAD_DEMO_DATA`, valeur `1`. Enregistrer :
   Render redéploie.
2. Au démarrage, le conteneur crée (ou remplace) le compte `demo@finora.test` et ses données (mot de passe : constante `PASSWORD` de
   `src/DataFixtures/DemoFixtures.php`), avec `--append` : les autres comptes ne sont pas touchés.
3. Une fois le compte créé, **supprimer la variable** (nouveau redéploiement) : sinon les données de démonstration sont régénérées à chaque
   démarrage.

Le mot de passe est celui du dépôt : le changer après la première connexion (Profil → Mot de passe) si le dépôt est public.

## Mode hors ligne (service worker)

`public/sw.js` est servi à la racine (portée `/`) ; il met en cache les ressources statiques et, si l'utilisateur l'a activé, des copies de pages de consultation
(effacées à la déconnexion). Il ne fonctionne qu'en HTTPS (Render le fournit) ou sur `localhost`. Pour publier une nouvelle version du worker (nouvelle logique de
cache), changer la constante `VERSION` de `public/sw.js` : à l'activation, les caches des anciennes versions sont supprimés. Après un déploiement, le navigateur
récupère `sw.js` de lui-même (au plus 24 h ; à la prochaine ouverture de l'application, le plus souvent).

## Sauvegardes

Deux niveaux, à combiner :

1. **Chaque utilisateur** peut télécharger la sauvegarde de ses données (Profil → « Télécharger une sauvegarde ») et la restaurer plus tard,
   y compris sur une autre base ou un autre hébergeur : créer un compte, puis Profil → « Restaurer une sauvegarde ». Le fichier ne contient ni
   mot de passe ni adresse e-mail. À faire régulièrement, et **avant l'expiration** de la base gratuite de Render (durée limitée : vérifier
   la date sur la page de la base `finora-db`).
2. **Toute la base** (administrateur) : depuis un poste où PostgreSQL est installé, avec l'*External Database URL* de `finora-db` :
   ```
   pg_dump "<External Database URL>" --format=custom --file=finora.dump
   pg_restore --no-owner --dbname="<URL de la nouvelle base>" finora.dump
   ```
   Le fichier contient les mots de passe hachés de tous les comptes : le garder hors du dépôt Git et ne jamais le publier. Les plans payants
   de Render proposent aussi des sauvegardes automatiques.

Si la base gratuite expire : créer une nouvelle base (le blueprint la recrée), mettre à jour `DATABASE_URL`, puis chaque utilisateur
recrée son compte et restaure sa sauvegarde.

## Ne déployer que ce qui a passé les tests

La CI (`.github/workflows/ci.yml`) rejoue les tests et construit l'image à chaque envoi sur `main`. Par défaut, Render déploie dès le push, sans
attendre son résultat. Pour qu'il attende : service `finora` → *Settings* → *Build & Deploy* → *Auto-Deploy* → **After CI Checks Pass**.
Si la CI échoue, rien n'est déployé et la version en ligne reste celle d'avant.

## Points d'attention

- L'offre gratuite met le service en veille après un moment sans visite (premier chargement lent) et limite la durée de la base :
  vérifier les conditions actuelles sur la page de tarifs de Render avant de choisir.
- **Ne jamais charger les fixtures en production** : elles contiennent des identifiants de démonstration connus. Le paquet des fixtures est
  installé dans l'image, mais rien n'est chargé sans la variable `LOAD_DEMO_DATA`.
- L'inscription est ouverte à tous.
- Les mots de passe de la base fournis par Render sont alphanumériques ; un `%` dans `DATABASE_URL` serait pris pour un paramètre Symfony.

## Tester l'image en local

```
docker build -t finora-prod .
docker run --rm -p 8100:10000 -e PORT=10000 -e APP_SECRET=un-secret-long \
  -e "DATABASE_URL=postgresql://app:!ChangeMe!@host.docker.internal:<port-postgres>/<base-vide>" \
  -e MAILER_DSN=null://null -e MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=0" \
  -e LOCK_DSN=flock -e DEFAULT_URI=http://localhost:8100 finora-prod
```

Utiliser une base vide : les migrations s'appliquent au démarrage. L'application répond sur http://localhost:8100.
