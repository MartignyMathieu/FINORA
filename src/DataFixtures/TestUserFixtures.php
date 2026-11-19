<?php

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\Icon;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Utilisateur de test pour essayer l'application à la main : un compte presque vierge (un compte bancaire
 * et quelques catégories, sans transaction, budget ni objectif), pour tester les créations de zéro.
 *
 * Connexion : e-mail et mot de passe ci-dessous (environnement de développement uniquement).
 *
 *     symfony console doctrine:fixtures:load --group=test --append
 *
 * IMPORTANT : toujours avec --append (sans lui, Doctrine vide TOUTES les tables, utilisateurs compris).
 * Recharger les fixtures remet cet utilisateur à zéro, sans toucher aux autres.
 *
 * Pour un jeu de données complet, voir DemoFixtures (groupe « demo »).
 */
final class TestUserFixtures extends Fixture implements FixtureGroupInterface
{
    public const EMAIL = 'test@finora.test';
    public const PASSWORD = 'Test-Finora-2026!';
    public const DISPLAY_NAME = 'Testeur';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserDataPurger $purger,
    ) {
    }

    public static function getGroups(): array
    {
        return ['test'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Ces fixtures nécessitent l\'ORM Doctrine.');
        }
        $this->purger->purge($manager, self::EMAIL);

        $user = (new User())->setEmail(self::EMAIL)->setDisplayName(self::DISPLAY_NAME);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
        $manager->persist($user);

        $manager->persist(
            (new Account())->setOwner($user)->setName('Compte courant')->setType(AccountType::Checking)->setCurrency('EUR')->setInitialBalance('1000.00')
        );

        foreach ([
            'Revenus' => Icon::Briefcase,
            'Logement' => Icon::Home,
            'Alimentation' => Icon::Basket,
            'Transport' => Icon::Car,
            'Loisirs' => Icon::Gamepad,
        ] as $name => $icon) {
            $manager->persist((new Category())->setOwner($user)->setName($name)->setIcon($icon));
        }

        $manager->flush();
    }
}
