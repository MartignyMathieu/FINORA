# FINORA

FINORA est une application web de suivi de budget personnel. Elle permet d'enregistrer ses revenus et ses dépenses, de les classer par catégorie et de suivre ses budgets mensuels.

> Projet en cours de développement.

## Fonctionnalités prévues

- Ajouter, modifier et supprimer des transactions (revenus et dépenses)
- Classer les transactions par catégorie (loyer, courses, transport, loisirs...)
- Définir un budget mensuel par catégorie et suivre ce qu'il reste
- Tableau de bord : total des revenus, total des dépenses, solde
- Alerte en cas de dépassement de budget
- Liste des transactions avec pagination et filtres (mois, catégorie)
- Graphiques de suivi des dépenses

## Technologies

- PHP 8.2+
- Symfony 7
- Doctrine ORM
- Twig et Bootstrap 5
- MySQL / MariaDB

## Installation

```bash
git clone <url-du-depot>
cd FINORA
composer install
```

Créer un fichier `.env.local` avec la connexion à la base de données :

```
DATABASE_URL="mysql://utilisateur:motdepasse@127.0.0.1:3306/finora?serverVersion=8.0"
```

Puis :

```bash
symfony console doctrine:database:create
symfony console doctrine:migrations:migrate
symfony serve
```

L'application est accessible sur `http://localhost:8000`.

## Tâches en arrière-plan

Les e-mails (réinitialisation du mot de passe) et la génération nocturne des transactions récurrentes
(`app:recurring:generate`, planifiée à 3 h) passent par Messenger. Il faut donc un worker qui tourne :

```bash
symfony console messenger:consume async scheduler_default -vv
```

En local, `symfony serve -d` le lance automatiquement (voir `.symfony.local.yaml`). La génération peut aussi
être déclenchée à la main : `symfony console app:recurring:generate [--date=AAAA-MM-JJ]`.

## Données de démonstration (développement uniquement)

```bash
symfony console doctrine:fixtures:load --group=demo --append
```

Crée un utilisateur de démonstration avec des comptes, transactions, récurrences, budgets et objectifs
réalistes ; ses identifiants sont dans `src/DataFixtures/DemoFixtures.php`. Toujours utiliser `--append` :
sans cette option, Doctrine vide toutes les tables, utilisateurs compris. Recharger les fixtures remplace
uniquement les données de l'utilisateur de démonstration.

## Auteur

Projet personnel réalisé dans le cadre de ma formation.
