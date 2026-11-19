<?php

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Goal;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\Icon;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType;
use App\Service\RecurringTransactionGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Utilisateur de démonstration avec des données réalistes pour présenter l'outil.
 *
 * Connexion : e-mail et mot de passe ci-dessous (environnement de développement uniquement).
 *
 *     symfony console doctrine:fixtures:load --group=demo --append
 *
 * IMPORTANT : toujours avec --append. Sans cette option, Doctrine vide TOUTES les tables (tous les
 * utilisateurs) avant de charger les fixtures. Recharger les fixtures remplace uniquement les données
 * de l'utilisateur de démonstration.
 *
 * Les montants du mois en cours reproduisent les maquettes : solde total 8 450 €, revenus 2 500 €,
 * dépenses 1 250 € (Logement 500, Alimentation 320, Transport 120, Loisirs 80, autres 230).
 * Les mois précédents sont générés de façon déterministe (même résultat à chaque chargement).
 */
final class DemoFixtures extends Fixture implements FixtureGroupInterface
{
    public const EMAIL = 'demo@finora.test';
    public const PASSWORD = 'Demo-Finora-2026!';
    public const DISPLAY_NAME = 'Mathieu';

    /** Soldes visés (solde initial déduit des transactions pour les atteindre). */
    private const TARGET_BALANCES = ['Compte courant' => '6250.00', 'Compte épargne' => '2000.00', 'Espèces' => '200.00', 'Compte voyage' => '0.00'];

    private Randomizer $random;
    private \DateTimeImmutable $today;
    private \DateTimeImmutable $thisMonth;
    private EntityManagerInterface $manager;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RecurringTransactionGenerator $generator,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Ces fixtures nécessitent l\'ORM Doctrine.');
        }
        $this->manager = $manager;
        $this->random = new Randomizer(new Mt19937(2026));
        $this->today = new \DateTimeImmutable('today');
        $this->thisMonth = $this->today->modify('first day of this month');

        $this->removePreviousDemoData();

        $user = (new User())->setEmail(self::EMAIL)->setDisplayName(self::DISPLAY_NAME);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
        $this->manager->persist($user);

        $accounts = $this->createAccounts($user);
        $categories = $this->createCategories($user);
        $this->manager->flush();

        $this->createRecurringRules($accounts, $categories);
        $this->createManualTransactions($accounts, $categories);
        $this->manager->flush();

        $this->alignInitialBalances($accounts);
        $this->createBudgets($categories);
        $this->createGoals($user);
        $this->manager->flush();
    }

    private function removePreviousDemoData(): void
    {
        $em = $this->manager;
        $user = $em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if (null === $user) {
            return;
        }

        $em->createQuery('DELETE FROM '.Transaction::class.' t WHERE t.account IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
        $em->createQuery('DELETE FROM '.RecurringTransaction::class.' r WHERE r.account IN (SELECT a FROM '.Account::class.' a WHERE a.owner = :user)')->setParameter('user', $user)->execute();
        $em->createQuery('DELETE FROM '.Budget::class.' b WHERE b.category IN (SELECT c FROM '.Category::class.' c WHERE c.owner = :user)')->setParameter('user', $user)->execute();
        foreach ([Goal::class, Category::class, Account::class] as $class) {
            $em->createQuery(sprintf('DELETE FROM %s e WHERE e.owner = :user', $class))->setParameter('user', $user)->execute();
        }
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.id = :id')->setParameter('id', $user->getId())->execute();
        $em->clear();
    }

    /** @return array<string, Account> */
    private function createAccounts(User $user): array
    {
        $accounts = [];
        foreach ([
            'Compte courant' => AccountType::Checking,
            'Compte épargne' => AccountType::Savings,
            'Espèces' => AccountType::Cash,
            'Compte voyage' => AccountType::Travel,
        ] as $name => $type) {
            $accounts[$name] = (new Account())->setOwner($user)->setName($name)->setType($type)->setCurrency('EUR');
            $this->manager->persist($accounts[$name]);
        }

        return $accounts;
    }

    /** @return array<string, Category> */
    private function createCategories(User $user): array
    {
        $categories = [];
        foreach ([
            'Revenus' => Icon::Briefcase,
            'Logement' => Icon::Home,
            'Alimentation' => Icon::Basket,
            'Transport' => Icon::Car,
            'Loisirs' => Icon::Gamepad,
            'Abonnements' => Icon::Wifi,
            'Santé' => Icon::Heart,
            'Shopping' => Icon::Bag,
            'Cadeaux' => Icon::Gift,
            'Restaurants' => Icon::Utensils,
        ] as $name => $icon) {
            $categories[$name] = (new Category())->setOwner($user)->setName($name)->setIcon($icon);
            $this->manager->persist($categories[$name]);
        }

        return $categories;
    }

    /**
     * Règles récurrentes : actives (salaire, loyer…), en pause (salle de sport) et terminée (magazine).
     * Le générateur crée les transactions échues, comme le ferait la tâche planifiée.
     *
     * @param array<string, Account>  $accounts
     * @param array<string, Category> $categories
     */
    private function createRecurringRules(array $accounts, array $categories): void
    {
        $start = $this->thisMonth->modify('-5 months');
        $checking = $accounts['Compte courant'];
        $day = static fn (\DateTimeImmutable $month, int $d): \DateTimeImmutable => $month->modify(sprintf('+%d days', $d - 1));

        $rule = function (string $label, string $amount, TransactionType $type, string $category, int $dayOfMonth, ?\DateTimeImmutable $end = null) use ($checking, $categories, $start, $day): RecurringTransaction {
            $recurring = (new RecurringTransaction())
                ->setLabel($label)
                ->setAmount($amount)
                ->setType($type)
                ->setAccount($checking)
                ->setCategory($categories[$category])
                ->setFrequency(RecurrenceFrequency::Monthly)
                ->setStartDate($day($start, $dayOfMonth))
                ->setEndDate($end);
            $this->manager->persist($recurring);
            $this->manager->flush();

            return $recurring;
        };

        foreach ([
            $rule('Salaire', '2500.00', TransactionType::Income, 'Revenus', 3),
            $rule('Loyer', '500.00', TransactionType::Expense, 'Logement', 5),
            $rule('Internet', '39.99', TransactionType::Expense, 'Abonnements', 1),
            $rule('Streaming', '13.99', TransactionType::Expense, 'Abonnements', 12),
            $rule('Pass Navigo', '60.00', TransactionType::Expense, 'Transport', 9),
            // Terminée il y a deux mois.
            $rule('Abonnement magazine', '7.50', TransactionType::Expense, 'Loisirs', 20, $day($this->thisMonth->modify('-2 months'), 28)),
        ] as $active) {
            $this->generator->generateFor($active, $this->today);
        }

        // En pause : a généré les trois premiers mois puis a été suspendue.
        $paused = $rule('Salle de sport', '29.90', TransactionType::Expense, 'Loisirs', 15);
        $this->generator->generateFor($paused, $this->thisMonth->modify('-3 months')->modify('last day of this month'));
        $paused->setActive(false);
        $this->manager->flush();
    }

    /**
     * Dépenses courantes saisies à la main. Le mois en cours est fixé (totaux des maquettes), les mois
     * précédents sont tirés de façon déterministe.
     *
     * @param array<string, Account>  $accounts
     * @param array<string, Category> $categories
     */
    private function createManualTransactions(array $accounts, array $categories): void
    {
        $checking = $accounts['Compte courant'];
        $cash = $accounts['Espèces'];

        $add = function (Account $account, string $category, TransactionType $type, string $amount, \DateTimeImmutable $month, int $day, string $label) use ($categories): void {
            // Jamais de transaction datée dans le futur : le jour est ramené à aujourd'hui si besoin.
            $date = min($month->modify(sprintf('+%d days', min($day, 28) - 1)), $this->today);
            $this->manager->persist(
                (new Transaction())->setAccount($account)->setCategory($categories[$category])->setType($type)
                    ->setAmount($amount)->setDate($date)->setLabel($label)
            );
        };
        $expense = TransactionType::Expense;

        // Mois en cours : totaux des maquettes.
        $m = $this->thisMonth;
        $add($checking, 'Transport', $expense, '60.00', $m, 2, 'Carburant');
        $add($checking, 'Alimentation', $expense, '85.40', $m, 18, 'Supermarché');
        $add($checking, 'Alimentation', $expense, '210.00', $m, 12, 'Courses');
        $add($cash, 'Alimentation', $expense, '24.60', $m, 8, 'Marché');
        $add($checking, 'Loisirs', $expense, '80.00', $m, 14, 'Cinéma');
        $add($checking, 'Santé', $expense, '70.00', $m, 8, 'Pharmacie');
        $add($checking, 'Shopping', $expense, '60.00', $m, 10, 'Vêtements');
        $add($checking, 'Cadeaux', $expense, '46.02', $m, 16, 'Cadeau d\'anniversaire');

        // Mois précédents : tirage déterministe.
        $money = fn (int $min, int $max): string => number_format($this->random->getInt($min * 100, $max * 100) / 100, 2, '.', '');
        for ($i = 5; $i >= 1; --$i) {
            $m = $this->thisMonth->modify(sprintf('-%d months', $i));
            $add($checking, 'Alimentation', $expense, $money(70, 120), $m, 4, 'Supermarché');
            $add($checking, 'Alimentation', $expense, $money(90, 150), $m, 13, 'Courses');
            $add($cash, 'Alimentation', $expense, $money(15, 40), $m, 21, 'Marché');
            $add($checking, 'Transport', $expense, $money(45, 70), $m, 6, 'Carburant');
            $add($checking, 'Loisirs', $expense, $money(30, 110), $m, 17, 'Sortie');
            $add($checking, 'Restaurants', $expense, $money(25, 70), $m, 22, 'Restaurant');
            if ($this->random->getInt(0, 1)) {
                $add($checking, 'Santé', $expense, $money(20, 80), $m, 9, 'Pharmacie');
            }
            if ($this->random->getInt(0, 1)) {
                $add($checking, 'Shopping', $expense, $money(30, 140), $m, 24, 'Shopping');
            }
            if (\in_array($i, [4, 2], true)) {
                $add($checking, 'Revenus', TransactionType::Income, $money(350, 900), $m, 25, 'Mission freelance');
            }
        }
    }

    /**
     * Ajuste le solde initial de chaque compte pour que les soldes affichés correspondent aux cibles,
     * quelles que soient les transactions générées.
     *
     * @param array<string, Account> $accounts
     */
    private function alignInitialBalances(array $accounts): void
    {
        $net = [];
        foreach ($this->manager->createQuery(
            'SELECT IDENTITY(t.account) AS account, SUM(CASE WHEN t.type = :income THEN t.amount ELSE -t.amount END) AS net
             FROM '.Transaction::class.' t WHERE t.account IN (:accounts) GROUP BY t.account'
        )->setParameter('income', TransactionType::Income)->setParameter('accounts', array_values($accounts))->getArrayResult() as $row) {
            $net[(int) $row['account']] = (float) $row['net'];
        }

        foreach ($accounts as $name => $account) {
            $account->setInitialBalance(number_format((float) self::TARGET_BALANCES[$name] - ($net[$account->getId()] ?? 0.0), 2, '.', ''));
        }
    }

    /** @param array<string, Category> $categories */
    private function createBudgets(array $categories): void
    {
        foreach ([0, -1] as $offset) {
            $month = $this->thisMonth->modify(sprintf('%+d months', $offset));
            foreach (['Alimentation' => '500.00', 'Logement' => '800.00', 'Transport' => '200.00', 'Loisirs' => '150.00'] as $name => $amount) {
                $this->manager->persist((new Budget())->setCategory($categories[$name])->setMonth($month)->setAmount($amount));
            }
        }
    }

    private function createGoals(User $user): void
    {
        foreach ([
            ['Achat d\'une voiture', '5000.00', '3200.00', 7, Icon::Car],
            ['Vacances', '2000.00', '1200.00', 9, Icon::Umbrella],
            ['Nouveau PC', '1500.00', '750.00', 4, Icon::Laptop],
        ] as [$name, $target, $saved, $months, $icon]) {
            $this->manager->persist(
                (new Goal())->setOwner($user)->setName($name)->setTargetAmount($target)->setSavedAmount($saved)
                    ->setTargetDate($this->thisMonth->modify(sprintf('+%d months', $months)))->setIcon($icon)
            );
        }
    }
}
