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

## Auteur

Projet personnel réalisé dans le cadre de ma formation.
