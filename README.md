# FINORA

> Maîtrisez votre argent.

FINORA est une application web de suivi de budget personnel : on y enregistre ses revenus et ses dépenses sur
plusieurs comptes, on les classe par catégorie, on fixe des budgets mensuels, on suit des objectifs d'épargne
et on automatise les opérations qui reviennent chaque mois. Chaque utilisateur a ses propres données.

## Fonctionnalités

- **Comptes** : plusieurs comptes (courant, épargne, espèces, voyage…), chacun avec sa devise et son solde initial
- **Transactions** : revenus et dépenses, regroupés par mois, avec onglets Toutes / Revenus / Dépenses et recherche
- **Catégories** : nom, icône et couleur, propres à chaque utilisateur
- **Budgets mensuels** : un plafond par catégorie et par mois, avec barre de progression (orange à partir de 80 %, rouge en cas de dépassement)
- **Objectifs d'épargne** : montant visé, montant épargné, échéance et avancement
- **Transactions récurrentes** : loyer, salaire, abonnements… Une règle (fréquence quotidienne, hebdomadaire, mensuelle ou
  annuelle) génère automatiquement les transactions à chaque échéance ; elle peut être mise en pause, reprise ou supprimée
- **Tableau de bord** : solde total, revenus, dépenses et épargne du mois, évolution sur 6 mois, répartition des dépenses
- **Calendrier** : vue mensuelle avec les opérations de chaque jour, les récurrences à venir et les totaux du mois ;
  un clic sur un jour affiche son détail et permet d'y ajouter une transaction
- **Statistiques** : répartition par catégorie en pourcentage et en montant, pour les dépenses ou les revenus
- **Comptes utilisateurs** : inscription, connexion, réinitialisation du mot de passe par e-mail, données cloisonnées par utilisateur
- **Profil** : modification du prénom, de l'adresse e-mail et du mot de passe (mot de passe actuel exigé), récapitulatif de ses
  données et suppression définitive du compte
- **Interface** adaptée au bureau (barre latérale) et au mobile (barre d'onglets), entièrement en français

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
├── Service/         FinanceStats (agrégats), CalendarBuilder, RecurringTransactionGenerator, UserDataPurger
├── Command/         app:recurring:generate
├── Twig/            icônes, montants et dates en français
├── DataFixtures/    utilisateurs de démonstration et de test
docs/TESTING.md      plan de vérification manuelle pour les testeurs
```

## Sécurité

- Mots de passe hachés, connexion protégée contre le CSRF et limitée à 5 tentatives par minute et par couple IP/identifiant
- Réinitialisation du mot de passe : lien à usage unique valable 1 heure, réponse identique que le compte existe ou non
- Chaque donnée appartient à un utilisateur : listes, totaux, graphiques et formulaires sont filtrés, et un voter refuse
  l'accès aux données d'autrui (403)

## Auteur

Projet personnel réalisé dans le cadre de ma formation.
