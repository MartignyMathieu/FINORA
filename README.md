# FINORA

> Maîtrisez votre argent.

FINORA est une application web de suivi de budget personnel : on y enregistre ses revenus et ses dépenses sur
plusieurs comptes, on les classe par catégorie, on fixe des budgets mensuels, on suit des objectifs d'épargne
et on automatise les opérations qui reviennent chaque mois. Chaque utilisateur a ses propres données.

## Fonctionnalités

- **Comptes** : plusieurs comptes (courant, épargne, espèces, voyage…), chacun avec sa devise et son solde initial
- **Solde masquable** : l'œil de la carte de solde masque ou affiche le solde total (choix mémorisé sur l'appareil)
- **Filtres de transactions** : période (avec périodes rapides), montants min. et max., compte et catégorie, en plus du type et de la recherche ; pastilles des filtres actifs, totaux de toutes les transactions trouvées, et même filtre pour l'export CSV
- **Alertes de budget** : bannière sur l'accueil et les budgets, et pastille rouge sur « Budgets », dès qu'un budget du mois atteint 80 % de son plafond, l'atteint ou le dépasse
- **Prévision de fin de mois** : solde prévu à la fin du mois (transactions déjà saisies à venir et récurrences pas encore générées), avec un avertissement si le solde risque de passer sous zéro
- **Objectifs d'épargne** : montant visé, échéance, avancement, **versements et retraits** avec historique (l'épargne suit)
- **Virements** : de l'argent déplacé d'un compte à l'autre (même devise), sans fausser les revenus, les dépenses ni le solde total
- **Transactions** : revenus et dépenses, regroupés par mois, avec onglets Toutes / Revenus / Dépenses et recherche
- **Catégories** : nom, icône et couleur (3 palettes de 8 couleurs à choisir d'un clic, ou « Automatique »), propres à chaque utilisateur
- **Budgets mensuels** : un plafond par catégorie et par mois, avec barre de progression (orange à partir de 80 %, rouge en cas de dépassement)
- **Objectifs d'épargne** : montant visé, montant épargné, échéance et avancement
- **Transactions récurrentes** : loyer, salaire, abonnements… Une règle (fréquence quotidienne, hebdomadaire, mensuelle ou
  annuelle) génère automatiquement les transactions à chaque échéance ; elle peut être mise en pause, reprise ou supprimée
- **Tableau de bord** : solde total, revenus, dépenses et épargne du mois, évolution sur 6 mois, répartition des dépenses
- **Export CSV** des transactions : bouton sur la page Transactions (il reprend l'onglet et la recherche en cours) et lien dans
  le profil ; l'export n'est pas limité aux lignes affichées (voir « Export CSV » plus bas)
- **Calendrier** : vue mensuelle avec les opérations de chaque jour, les récurrences à venir et les totaux du mois ;
  un clic sur un jour affiche son détail et permet d'y ajouter une transaction
- **Statistiques** : répartition par catégorie en pourcentage et en montant, pour les dépenses ou les revenus
- **Comptes utilisateurs** : inscription, connexion, réinitialisation du mot de passe par e-mail, données cloisonnées par utilisateur
- **Profil** : modification du prénom et de l'icône de profil (10 pictogrammes ou l'initiale), de l'adresse e-mail et du mot de passe (mot de passe actuel exigé), récapitulatif de ses
  données et suppression définitive du compte
- **Interface** adaptée au bureau (barre latérale) et au mobile (barre d'onglets), entièrement en français
- **Thème clair ou sombre** : automatique selon l'appareil par défaut, au choix dans Paramètres → Apparence, ou en un clic
  depuis la barre latérale ; le choix est mémorisé dans le navigateur et appliqué avant l'affichage (pas d'éclair clair)
- **Animations, interactions et éléments en 3D** (voir « Animations et interactions »), désactivables dans
  Paramètres → Apparence → Animations

## Animations et interactions

Tout est fait en CSS et en petits contrôleurs [Stimulus](https://stimulus.hotwired.dev/) (aucune bibliothèque d'animation
ni de 3D) :

| Élément | Où | Ce qu'il fait |
| --- | --- | --- |
| Apparition progressive | toutes les pages | les cartes et les lignes de liste glissent et apparaissent à tour de rôle en défilant |
| Compteurs animés | accueil | les montants montent de 0 à leur valeur ; le texte exact du serveur est remis à la fin |
| Carte inclinable en 3D | accueil (cartes de totaux, carte de solde) | s'incline vers le pointeur, avec un reflet qui suit la souris et du relief (souris uniquement) |
| Courbe interactive | accueil | tracé progressif ; survol ou clavier sur un mois : trait de repère et info-bulle (revenus, dépenses) |
| Donut interactif | accueil, statistiques | tracé progressif ; survol d'une part ou d'une ligne de légende : la catégorie ressort, son montant s'affiche au centre |
| Barres de progression | budgets, objectifs | se remplissent en s'affichant |
| Carte d'objectif à deux faces (3D) | objectifs | un bouton la retourne : reste à épargner, mois restants, effort mensuel, actions (Échap pour revenir) |
| Pièce 3D | connexion, inscription | le « F » FINORA sur une pièce qui tourne |
| Ajout rapide | bouton en bas à droite | déploie 6 raccourcis (transaction, virement, récurrence, budget, objectif, compte) ; absent des pages de formulaire, de profil et de paramètres |
| Messages | toutes les pages | glissent, se ferment d'un clic ; les confirmations disparaissent seules (pas les erreurs) |
| Transitions | thème, navigation | fondu entre les thèmes clair et sombre ; transition fondue entre les pages (Turbo) |
| Survol et appui | listes, boutons | la ligne se soulève, le bouton s'enfonce |

**Réduire ou couper les animations.** Paramètres → Apparence → Animations : *Auto* (défaut) suit le réglage « réduire les
animations » du système, *Réduites* coupe tout, *Complètes* force les animations même si le système demande de les réduire.
Le choix est mémorisé dans le navigateur. Les interactions (info-bulle, retournement des cartes, menu d'ajout) fonctionnent
dans tous les cas ; seul le mouvement disparaît.

**Comment c'est construit.**

- `assets/styles/motion.css` contient toutes les animations et les poses 3D ; **toute animation ou transition y est sous
  `[data-fx="on"]`** (attribut posé sur `<html>` avant l'affichage, voir `assets/motion.js`). Ne jamais écrire d'`animation` ou de
  `transition` hors de cette condition : un test échoue sinon.
- Les contrôleurs sont dans `assets/controllers/` (`reveal`, `countup`, `tilt`, `chart`, `donut`, `flip`, `fab`, `flash`, `motion`, `theme`).
- Amélioration progressive : sans JavaScript, ou animations coupées, rien n'est masqué ni déplacé et chaque élément garde son état final ;
  le bouton d'ajout rapide devient un lien vers « Nouvelle transaction ».
- On anime `translate`, `scale` et `rotate` plutôt que `transform`, pour se combiner avec l'inclinaison 3D.

## Mise en page : bureau et mobile

Une seule interface, qui s'adapte à la largeur de l'écran (point de rupture à **900 px**, mobile d'abord) :

| | Bureau (≥ 900 px) | Mobile (< 900 px) |
| --- | --- | --- |
| Navigation | Barre latérale : Accueil, Comptes, Transactions, Récurrences, Budgets, Objectifs, Statistiques, Calendrier, Paramètres, profil et bouton de thème | Barre d'onglets en bas : **Accueil, Comptes, Budgets, Stats, Plus** |
| Accès au reste | Directement dans la barre latérale | Onglet **Plus** : profil, transactions, calendrier, récurrences, objectifs, catégories, apparence (clair / sombre) et déconnexion |
| Accueil | Message d'accueil, quatre cartes (solde, revenus, dépenses, épargne), courbe sur 6 mois et répartition des dépenses | Prénom avec avatar, carte de solde avec l'évolution du mois, accès rapides (Ajouter, Transactions, Objectifs, Plus) et aperçu du mois |
| Calendrier | Montants du jour dans chaque case | De simples points colorés (revenus, dépenses, récurrence prévue) ; le détail s'affiche en touchant un jour |
| Statistiques | Sous-titre explicatif | Donut au-dessus de la légende sur les écrans très étroits (< 480 px) |
| Listes et cartes | Cartes d'objectifs sur plusieurs colonnes, totaux en rangée de 4 | Une colonne ; totaux sur 1 colonne (accueil, récurrences), 2 (calendrier) ou 3 (profil) ; lignes de récurrences qui passent à la ligne |

Les écrans intermédiaires (900 à 1 180 px) gardent la barre latérale, passent les cartes de totaux sur deux colonnes et
placent les graphiques l'un sous l'autre.

Détails de mise en œuvre :

- Les zones réservées au bureau ou au mobile sont repérées par les classes `only-desktop` et `only-mobile`
  (`assets/styles/app.css`), pas par du JavaScript : une seule page HTML est servie.
- La barre d'onglets tient compte des zones d'encoche et de la barre d'accueil des téléphones (`viewport-fit=cover` et
  `env(safe-area-inset-bottom)`), et le contenu garde une marge basse pour ne jamais passer dessous.
- La couleur de la barre d'état du navigateur suit le thème clair ou sombre.
- Aucun défilement horizontal attendu, quel que soit l'écran : à vérifier avec `docs/TESTING.md` (section « Affichage »).

## Installer sur un téléphone

FINORA est installable comme une application : un manifeste (`public/manifest.webmanifest`) et des icônes (`public/icons/`)
donnent une icône, un nom court (« FINORA ») et une ouverture plein écran, sans barre d'adresse.

1. Lancer le serveur pour le réseau local : `symfony serve -d --allow-all-ip --no-tls` (Docker doit tourner : `docker compose up -d`).
2. Sur le téléphone, connecté au même Wi-Fi, ouvrir `http://<adresse-ip-du-pc>:8000` (adresse donnée par `ipconfig`).
3. Android (Chrome) : menu ⋮ → *Ajouter à l'écran d'accueil* ; iPhone (Safari) : Partager → *Sur l'écran d'accueil*.

Chrome sur Android n'installe une vraie application que pour un site en **HTTPS** (ou `localhost`) ; en HTTP sur le réseau local,
on obtient un raccourci avec la bonne icône. Les icônes se régénèrent avec `node tools/icons/generate.mjs` (Chrome ou Edge requis).

Pour une vraie installation (HTTPS, plein écran sur Android), voir le déploiement sur Render : [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md).

## Technologies

- PHP 8.4+ et Symfony 8.1 (Security, Form, Validator, Messenger, Scheduler, Lock, Mailer, Rate Limiter)
- Doctrine ORM 3 et PostgreSQL 16
- Twig, AssetMapper, Stimulus et Turbo (pas de bundler JavaScript), feuille de style maison
- Symfony UX Icons avec le jeu d'icônes Phosphor, graphiques en SVG générés côté serveur
- PHPUnit 13 pour les tests

## Prérequis

- PHP 8.4 ou plus, avec les extensions `pdo_pgsql`, `intl`, `ctype` et `iconv`
- [Composer](https://getcomposer.org/) et le [Symfony CLI](https://symfony.com/download)
- Docker (PostgreSQL et Mailpit sont définis dans `compose.yaml` et `compose.override.yaml`)

## Installation

```bash
git clone <url-du-depot>
cd FINORA
composer install
docker compose up -d
symfony console doctrine:migrations:migrate
symfony serve -d
```

L'application est accessible sur l'adresse affichée par `symfony serve` (en général `http://127.0.0.1:8000`).
Créez un compte depuis la page d'inscription, ou chargez des données de démonstration (voir plus bas).

Avec `symfony console` et `symfony php`, l'adresse de la base lancée par Docker est détectée automatiquement.
Pour utiliser `php bin/console` directement, ou une autre base, définissez `DATABASE_URL` dans `.env.local`
(fichier ignoré par Git) :

```
DATABASE_URL="postgresql://utilisateur:motdepasse@127.0.0.1:5432/finora?serverVersion=16&charset=utf8"
```

## Configuration

| Variable ou paramètre | Rôle |
| --- | --- |
| `DATABASE_URL` | Connexion PostgreSQL |
| `MAILER_DSN` | Envoi des e-mails (`null://null` par défaut : rien n'est envoyé. Avec Docker, Mailpit est détecté et son interface web affiche les e-mails) |
| `MESSENGER_TRANSPORT_DSN` | File d'attente des messages asynchrones (e-mails, tâches planifiées) |
| `LOCK_DSN` | Verrous (`flock` par défaut) |
| `app.default_currency` (`config/services.yaml`) | Devise des totaux du tableau de bord (`EUR`) : il n'y a pas de conversion entre devises |
| `app.mailer_from` (`config/services.yaml`) | Adresse d'expédition des e-mails |

Les secrets réels vont dans `.env.local` ou dans le coffre de secrets Symfony (`symfony console secrets:set`), jamais dans `.env`.

## Export CSV

`GET /export/transactions` (connecté) télécharge les transactions de l'utilisateur, de la plus ancienne à la plus récente,
sans limite de nombre. Paramètres facultatifs, tous combinables :

| Paramètre | Valeurs | Effet |
| --- | --- | --- |
| `type` | `all` (défaut), `income`, `expense` | Revenus ou dépenses seulement |
| `q` | texte (100 caractères max) | Libellé ou catégorie contenant le texte, sans tenir compte de la casse |
| `from`, `to` | `AAAA-MM-JJ` | Période, bornes incluses |
| `format` | `fr` (défaut), `intl` | `fr` : séparateur `;` et virgule décimale (Excel en français) ; `intl` : séparateur `,` et point décimal |

Un paramètre invalide renvoie une erreur 422. Colonnes : `Date;Libellé;Type;Montant;Devise;Compte;Catégorie;Note;Origine`.
Le montant est signé (les dépenses sont négatives, pour pouvoir additionner la colonne) et la devise est celle du compte ;
l'origine est « Manuelle » ou « Récurrente ». Le fichier est en UTF-8 avec BOM, et n'est pas mis en cache.

Les textes saisis par l'utilisateur qui commencent par `=`, `+`, `-` ou `@` sont préfixés d'une apostrophe : sans cela, Excel ou
LibreOffice les exécuteraient comme des formules à l'ouverture du fichier (injection de formules).

## Tâches en arrière-plan

Les e-mails (réinitialisation du mot de passe) et la génération nocturne des transactions récurrentes
(`app:recurring:generate`, planifiée à 3 h) passent par Messenger. Un worker doit donc tourner :

```bash
symfony console messenger:consume async scheduler_default -vv
```

En local, `symfony serve -d` le lance automatiquement (voir `.symfony.local.yaml`). La génération peut aussi
être déclenchée à la main : `symfony console app:recurring:generate [--date=AAAA-MM-JJ]`. Elle est idempotente
et rattrape les échéances manquées.

## Données de démonstration et de test (développement uniquement)

```bash
symfony console doctrine:fixtures:load --group=demo --append   # jeu de présentation calé sur les maquettes
symfony console doctrine:fixtures:load --group=test --append   # jeu pour les testeurs
```

- **demo** : un utilisateur avec comptes, transactions sur 6 mois, récurrences, budgets et objectifs réalistes
- **test** : trois utilisateurs pour les testeurs. L'utilisateur complet a des données dans chaque page, cas limites
  compris (budget dépassé, solde négatif, devise étrangère, récurrences de chaque fréquence et de chaque état,
  textes longs ou contenant du HTML). Un deuxième utilisateur sert à vérifier le cloisonnement des données et un
  troisième, sans aucune donnée, à vérifier les pages vides.

Le plan de vérification page par page est dans [docs/TESTING.md](docs/TESTING.md). Les identifiants sont dans
`src/DataFixtures/DemoFixtures.php` et `src/DataFixtures/TestUserFixtures.php`.
**Toujours utiliser `--append`** : sans cette option, Doctrine vide toutes les tables, utilisateurs compris.
Recharger un groupe remplace uniquement les données de ses utilisateurs. Ne jamais charger ces fixtures en production.

## Tests

```bash
symfony console doctrine:database:create --env=test --if-not-exists
symfony console doctrine:migrations:migrate --env=test --no-interaction
symfony php bin/phpunit
```

**Contrôleurs JavaScript.** Leurs tests (`tests/js`) tournent avec Node.js 22 ou plus, sans dépendance ni navigateur, sur un faux
DOM : `node --test "tests/js/*.test.mjs"`. Ils sont aussi lancés par PHPUnit (ignorés si Node n'est pas installé).

**Vérification dans un vrai navigateur.** `node tools/browser/check.mjs [adresse]` pilote Chrome, Edge ou Chromium en mode headless
(variable `CHROME_PATH` pour choisir le navigateur) sur le serveur de développement, avec l'utilisateur de test complet : toutes
les pages sans erreur JavaScript en clair et en sombre, aucun défilement horizontal en 390, 360 et 320 px, et les interactions
(inclinaison 3D, info-bulle, donut, carte retournée, ajout rapide, compteurs, animations réduites). Les captures d'écran sont écrites
dans `var/screenshots/`. Le serveur doit tourner (`symfony serve -d`) et le jeu de données de test doit être chargé.

Un test lit la feuille de style et vérifie les contrastes WCAG AA de chaque paire de couleurs dans les deux thèmes
(`tests/Unit/ThemeContrastTest.php`) : en modifiant une couleur de `assets/styles/app.css`, garder les deux jeux de
variables sombres identiques (`@media (prefers-color-scheme: dark)` et `[data-theme="dark"]`).

Les tests utilisent une base dédiée (`<base>_test`) qu'ils vident avant chaque test : ne jamais la faire pointer vers
des données à conserver. Utiliser `symfony php` (et non `php`) pour qu'ils trouvent la base lancée par Docker.

## Architecture

```
src/
├── Controller/      contrôleurs fins, un par page ou ressource
├── Entity/          User, Account, Category, Transaction, RecurringTransaction, Budget, Goal…
├── Enum/            types de transaction, fréquences, types de compte, icônes
├── Form/            formulaires (listes de comptes et catégories limitées à l'utilisateur)
├── Security/        OwnerVoter : seul le propriétaire accède à ses données
├── Dto/             paramètres validés des requêtes (export CSV)
├── Service/         FinanceStats (agrégats), CalendarBuilder, RecurringTransactionGenerator, TransactionCsvExporter, UserDataPurger
├── Command/         app:recurring:generate
├── Twig/            icônes, montants et dates en français
├── DataFixtures/    utilisateurs de démonstration et de test
assets/
├── controllers/     contrôleurs Stimulus (interactions, animations, thème)
├── styles/          app.css (mise en page, thèmes) et motion.css (animations, 3D)
└── motion.js        règle commune des animations (data-fx)
tests/js/            tests des contrôleurs JavaScript (faux DOM, `node --test`)
tools/browser/       vérification dans un vrai navigateur (CDP, sans dépendance)
docs/TESTING.md      plan de vérification manuelle pour les testeurs
```

## Sécurité

- Mots de passe hachés, connexion protégée contre le CSRF et limitée à 5 tentatives par minute et par couple IP/identifiant
- Réinitialisation du mot de passe : lien à usage unique valable 1 heure, réponse identique que le compte existe ou non
- Chaque donnée appartient à un utilisateur : listes, totaux, graphiques et formulaires sont filtrés, et un voter refuse
  l'accès aux données d'autrui (403)

## Auteur

Projet personnel réalisé dans le cadre de ma formation.
