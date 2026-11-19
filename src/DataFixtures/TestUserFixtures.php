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
use App\Service\UserDataPurger;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données pour les testeurs : chaque page de l'application contient des données, y compris des cas
 * limites volontaires. Le plan de vérification page par page est dans docs/TESTING.md.
 *
 *     symfony console doctrine:fixtures:load --group=test --append
 *
 * Trois utilisateurs, même mot de passe (environnement de développement uniquement) :
 *  - EMAIL        : utilisateur « complet » (toutes les pages remplies, cas limites compris)
 *  - OTHER_EMAIL  : autre utilisateur, pour vérifier que ses données ne sont jamais visibles depuis EMAIL
 *  - EMPTY_EMAIL  : utilisateur sans aucune donnée, pour vérifier les pages vides
 *
 * IMPORTANT : toujours avec --append (sans lui, Doctrine vide TOUTES les tables, utilisateurs compris).
 * Recharger le groupe remet ces trois utilisateurs à zéro, sans toucher aux autres.
 *
 * Les dates sont relatives à aujourd'hui (le mois en cours est toujours rempli) ; les mois précédents sont
 * tirés de façon déterministe. Pour un jeu de présentation calé sur les maquettes, voir DemoFixtures.
 */
final class TestUserFixtures extends Fixture implements FixtureGroupInterface
{
    public const EMAIL = 'test@finora.test';
    public const OTHER_EMAIL = 'autre@finora.test';
    public const EMPTY_EMAIL = 'vide@finora.test';
    public const PASSWORD = 'Test-Finora-2026!';
    public const DISPLAY_NAME = 'Testeur';

    private const LONG_CATEGORY = 'Catégorie au nom très long pour tester l\'affichage et les retours à la ligne';

    private EntityManagerInterface $em;
    private Randomizer $random;
    private \DateTimeImmutable $today;
    private \DateTimeImmutable $thisMonth;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RecurringTransactionGenerator $generator,
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
        $this->em = $manager;
        $this->random = new Randomizer(new Mt19937(7));
        $this->today = new \DateTimeImmutable('today');
        $this->thisMonth = $this->today->modify('first day of this month');

        foreach ([self::EMAIL, self::OTHER_EMAIL, self::EMPTY_EMAIL] as $email) {
            $this->purger->purgeByEmail($email);
        }

        $this->createUser(self::EMPTY_EMAIL, 'Utilisateur vide');
        $this->em->flush();

        $this->createOtherUserData($this->createUser(self::OTHER_EMAIL, 'Autre testeur'));
        $this->em->flush();

        $this->createFullUserData($this->createUser(self::EMAIL, self::DISPLAY_NAME));
        $this->em->flush();
    }

    private function createUser(string $email, string $displayName): User
    {
        $user = (new User())->setEmail($email)->setDisplayName($displayName);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);

        return $user;
    }

    /** Un peu de données reconnaissables, qui ne doivent jamais apparaître chez l'utilisateur « complet ». */
    private function createOtherUserData(User $user): void
    {
        $account = $this->account($user, 'Compte secret (autre utilisateur)', AccountType::Checking, 'EUR', '777.00');
        $category = $this->category($user, 'Catégorie secrète (autre utilisateur)', Icon::Tag);
        $this->em->flush();

        $this->transaction($account, $category, TransactionType::Expense, '77.70', $this->today, 'Achat privé (autre utilisateur)');
        $this->em->persist((new Budget())->setCategory($category)->setMonth($this->thisMonth)->setAmount('100.00'));
        $this->em->persist((new Goal())->setOwner($user)->setName('Objectif privé (autre utilisateur)')->setTargetAmount('1000.00')->setSavedAmount('100.00')->setIcon(Icon::Target));
        $this->em->persist(
            (new RecurringTransaction())->setLabel('Récurrence privée (autre utilisateur)')->setAmount('9.99')->setType(TransactionType::Expense)
                ->setAccount($account)->setCategory($category)->setFrequency(RecurrenceFrequency::Monthly)
                ->setStartDate($this->thisMonth->modify('+27 days'))
        );
    }

    private function createFullUserData(User $user): void
    {
        $courant = $this->account($user, 'Compte courant', AccountType::Checking, 'EUR', '1500.00');
        $livret = $this->account($user, 'Livret A', AccountType::Savings, 'EUR', '5000.00');
        $cash = $this->account($user, 'Espèces', AccountType::Cash, 'EUR', '1000.00');
        $usd = $this->account($user, 'Compte voyage (USD)', AccountType::Travel, 'USD', '800.00');
        $overdraft = $this->account($user, 'Compte à découvert', AccountType::Other, 'EUR', '-250.00');

        $categories = [];
        foreach ([
            'Revenus' => [Icon::Briefcase, '#16a34a'],
            'Logement' => [Icon::Home, null],
            'Alimentation' => [Icon::Basket, null],
            'Transport' => [Icon::Car, null],
            'Loisirs' => [Icon::Gamepad, null],
            'Santé' => [Icon::Heart, null],
            'Shopping' => [Icon::Bag, '#f472b6'],
            'Abonnements' => [Icon::Wifi, null],
            'Cadeaux' => [Icon::Gift, null],
            'Restaurants' => [Icon::Utensils, null],
            'Voyage' => [Icon::Plane, null],
            'Épargne' => [Icon::Piggy, null],
            'Sans icône ni couleur' => [null, null],
            self::LONG_CATEGORY => [null, null],
        ] as $name => [$icon, $color]) {
            $categories[$name] = $this->category($user, $name, $icon, $color);
        }
        $this->em->flush();

        $this->createRecurringRules($courant, $cash, $usd, $categories);
        $this->createCurrentMonth($courant, $livret, $cash, $usd, $overdraft, $categories);
        $this->createHistory($courant, $cash, $usd, $categories);
        $this->createBudgets($categories);
        $this->createGoals($user);
    }

    /**
     * Une règle de chaque fréquence et de chaque état : active, en pause, terminée, pas encore commencée,
     * à échéance en fin de mois, avec fin dans le futur, dans une autre devise.
     *
     * @param array<string, Category> $categories
     */
    private function createRecurringRules(Account $courant, Account $cash, Account $usd, array $categories): void
    {
        $month = fn (int $offset, int $day): \DateTimeImmutable => $this->thisMonth->modify(sprintf('%+d months', $offset))->modify(sprintf('+%d days', $day - 1));

        // Première date « le 31 » des huit derniers mois : les échéances tombent le 30 ou le 28 les mois courts.
        $endOfMonthStart = $month(-1, 28);
        for ($i = 1; $i <= 8; ++$i) {
            $candidate = $this->thisMonth->modify(sprintf('-%d months', $i));
            if (31 === (int) $candidate->format('t')) {
                $endOfMonthStart = $candidate->modify('+30 days');
                break;
            }
        }

        $rule = function (Account $account, string $category, string $label, string $amount, RecurrenceFrequency $frequency, \DateTimeImmutable $start, TransactionType $type = TransactionType::Expense, ?\DateTimeImmutable $end = null) use ($categories): RecurringTransaction {
            $recurring = (new RecurringTransaction())
                ->setLabel($label)->setAmount($amount)->setType($type)->setAccount($account)->setCategory($categories[$category])
                ->setFrequency($frequency)->setStartDate($start)->setEndDate($end);
            $this->em->persist($recurring);
            $this->em->flush();

            return $recurring;
        };
        $monthly = RecurrenceFrequency::Monthly;

        $active = [
            $rule($courant, 'Revenus', 'Salaire', '2500.00', $monthly, $month(-8, 3), TransactionType::Income),
            $rule($courant, 'Logement', 'Loyer', '650.00', $monthly, $month(-8, 5)),
            $rule($courant, 'Abonnements', 'Streaming', '13.99', $monthly, $month(-8, 12)),
            $rule($cash, 'Alimentation', 'Marché du samedi', '25.00', RecurrenceFrequency::Weekly, $this->today->modify('-10 weeks')),
            // Quotidienne, avec une fin dans le futur.
            $rule($cash, 'Restaurants', 'Café quotidien', '2.50', RecurrenceFrequency::Daily, $this->today->modify('-12 days'), TransactionType::Expense, $this->today->modify('+10 days')),
            $rule($courant, 'Logement', 'Assurance habitation', '240.00', RecurrenceFrequency::Yearly, $month(-8, 15)),
            $rule($courant, 'Abonnements', 'Abonnement fin de mois (le 31)', '9.90', $monthly, $endOfMonthStart),
            // Autre devise.
            $rule($usd, 'Voyage', 'Abonnement cloud (USD)', '9.99', $monthly, $month(-4, 18)),
            // Terminée il y a deux mois.
            $rule($courant, 'Loisirs', 'Abonnement magazine', '7.50', $monthly, $month(-8, 20), TransactionType::Expense, $month(-2, 28)),
        ];
        foreach ($active as $recurring) {
            $this->generator->generateFor($recurring, $this->today);
        }

        // En pause : a généré les cinq premiers mois puis a été suspendue.
        $paused = $rule($courant, 'Loisirs', 'Salle de sport (en pause)', '29.90', $monthly, $month(-8, 15));
        $this->generator->generateFor($paused, $month(-3, 28));
        $paused->setActive(false);

        // Pas encore commencées : visibles comme « Prévue » dans le calendrier.
        $rule($courant, 'Transport', 'Nouvelle assurance auto (à venir)', '58.50', $monthly, $month(1, 8));
        $rule($courant, 'Revenus', 'Prime annuelle (à venir)', '1200.00', RecurrenceFrequency::Yearly, $month(3, 20), TransactionType::Income);
        $this->em->flush();
    }

    /**
     * @param array<string, Category> $categories
     */
    private function createCurrentMonth(Account $courant, Account $livret, Account $cash, Account $usd, Account $overdraft, array $categories): void
    {
        $m = $this->thisMonth;
        $out = TransactionType::Expense;
        $in = TransactionType::Income;
        $at = fn (int $day): \DateTimeImmutable => $m->modify(sprintf('+%d days', $day - 1));

        // Aujourd'hui et dans le futur (case « aujourd'hui » du calendrier, opération saisie à l'avance).
        $this->tx($courant, $categories, 'Restaurants', $out, '3.20', $this->today, 'Café du jour');
        $this->tx($courant, $categories, 'Cadeaux', $out, '80.00', $this->today->modify('+2 days'), 'Paiement programmé (futur)');

        // Alimentation : environ 85 % du budget avec le marché hebdomadaire (barre orange).
        $this->tx($courant, $categories, 'Alimentation', $out, '120.40', $at(6), 'Supermarché');
        $this->tx($courant, $categories, 'Alimentation', $out, '195.00', $at(13), 'Courses');
        // Loisirs : 180 € pour un budget de 150 € (dépassement, barre rouge).
        $this->tx($courant, $categories, 'Loisirs', $out, '95.00', $at(9), 'Concert');
        $this->tx($courant, $categories, 'Loisirs', $out, '85.00', $at(16), 'Jeux vidéo');
        // Transport : environ 40 % du budget (barre bleue).
        $this->tx($courant, $categories, 'Transport', $out, '70.00', $at(2), 'Carburant');
        $this->tx($courant, $categories, 'Transport', $out, '12.50', $at(11), 'Parking');
        $this->tx($courant, $categories, 'Shopping', $out, '85.00', $at(10), 'Vêtements');
        $this->tx($courant, $categories, 'Santé', $out, '34.90', $at(8), 'Pharmacie');
        $this->tx($courant, $categories, 'Santé', $out, '55.00', $at(18), 'Dentiste');
        $this->tx($courant, $categories, 'Cadeaux', $out, '45.00', $at(14), 'Cadeau d\'anniversaire');
        $this->tx($courant, $categories, 'Restaurants', $out, '24.30', $at(7), 'Restaurant');

        // Revenus ponctuels.
        $this->tx($courant, $categories, 'Revenus', $in, '650.00', $at(4), 'Mission freelance');
        $this->tx($courant, $categories, 'Revenus', $in, '80.00', $at(15), 'Vente d\'occasion');
        $this->tx($courant, $categories, 'Santé', $in, '45.00', $at(12), 'Remboursement mutuelle');

        // Autres comptes : épargne, espèces, découvert (solde négatif), devise étrangère.
        $this->tx($livret, $categories, 'Épargne', $in, '200.00', $at(5), 'Virement épargne');
        $this->tx($livret, $categories, 'Épargne', $in, '12.50', $at(1), 'Intérêts du livret');
        $this->tx($cash, $categories, 'Alimentation', $out, '18.00', $at(3), 'Boulangerie');
        $this->tx($overdraft, $categories, 'Sans icône ni couleur', $out, '100.00', $at(7), 'Retrait');
        $this->tx($usd, $categories, 'Voyage', $out, '120.00', $at(8), 'Hôtel (USD)');
        $this->tx($usd, $categories, 'Voyage', $out, '340.00', $at(9), 'Vol (USD)');

        // Textes difficiles : HTML et guillemets (doivent s'afficher tels quels, sans être interprétés), libellé long.
        $special = $this->tx($courant, $categories, 'Sans icône ni couleur', $out, '1.00', $at(1), 'Test <b>HTML</b> & "guillemets" \'apostrophes\'');
        $special->setNote("Première ligne\nDeuxième ligne <script>alert('xss')</script>");
        $this->tx($courant, $categories, self::LONG_CATEGORY, $out, '9.99', $at(2), 'Achat avec un libellé particulièrement long pour vérifier que la mise en page ne déborde pas, même sur mobile');
    }

    /**
     * Huit mois d'historique (au-delà des six mois du graphique), tirés de façon déterministe.
     *
     * @param array<string, Category> $categories
     */
    private function createHistory(Account $courant, Account $cash, Account $usd, array $categories): void
    {
        $out = TransactionType::Expense;
        $money = fn (int $min, int $max): string => number_format($this->random->getInt($min * 100, $max * 100) / 100, 2, '.', '');

        for ($i = 8; $i >= 1; --$i) {
            $m = $this->thisMonth->modify(sprintf('-%d months', $i));
            $at = fn (int $day): \DateTimeImmutable => $m->modify(sprintf('+%d days', $day - 1));

            $this->tx($courant, $categories, 'Alimentation', $out, $money(70, 130), $at(4), 'Supermarché');
            $this->tx($courant, $categories, 'Alimentation', $out, $money(90, 160), $at(13), 'Courses');
            $this->tx($courant, $categories, 'Transport', $out, $money(40, 80), $at(6), 'Carburant');
            $this->tx($courant, $categories, 'Loisirs', $out, $money(30, 160), $at(17), 'Sortie');
            $this->tx($courant, $categories, 'Restaurants', $out, $money(25, 80), $at(22), 'Restaurant');
            $this->tx($cash, $categories, 'Alimentation', $out, $money(10, 40), $at(21), 'Boulangerie');
            if ($this->random->getInt(0, 1)) {
                $this->tx($courant, $categories, 'Santé', $out, $money(20, 90), $at(9), 'Pharmacie');
            }
            if ($this->random->getInt(0, 1)) {
                $this->tx($courant, $categories, 'Shopping', $out, $money(30, 160), $at(24), 'Shopping');
            }
            if (0 === $i % 3) {
                $this->tx($courant, $categories, 'Revenus', TransactionType::Income, $money(300, 900), $at(25), 'Mission freelance');
            }
            if (0 === $i % 4) {
                $this->tx($usd, $categories, 'Voyage', $out, $money(40, 200), $at(10), 'Achat en ligne (USD)');
            }
        }
    }

    /** @param array<string, Category> $categories */
    private function createBudgets(array $categories): void
    {
        $budget = function (string $category, int $offset, string $amount, string $currency = 'EUR') use ($categories): void {
            $this->em->persist(
                (new Budget())->setCategory($categories[$category])->setMonth($this->thisMonth->modify(sprintf('%+d months', $offset)))
                    ->setAmount($amount)->setCurrency($currency)
            );
        };

        // Mois en cours : un budget dans chaque état (ok, à surveiller, dépassé, autre devise).
        $budget('Alimentation', 0, '500.00');
        $budget('Loisirs', 0, '150.00');
        $budget('Transport', 0, '200.00');
        $budget('Restaurants', 0, '60.00');
        $budget('Shopping', 0, '100.00');
        $budget('Voyage', 0, '500.00', 'USD');
        // Mois précédent, et mois suivant (aucune dépense encore : 0 %).
        $budget('Alimentation', -1, '500.00');
        $budget('Loisirs', -1, '150.00');
        $budget('Transport', -1, '200.00');
        $budget('Alimentation', 1, '500.00');
    }

    private function createGoals(User $user): void
    {
        foreach ([
            ['Achat d\'une voiture', '5000.00', '3200.00', 'EUR', '+7 months', Icon::Car],
            ['Vacances (objectif atteint)', '2000.00', '2000.00', 'EUR', '+2 months', Icon::Umbrella],
            ['Nouveau PC (sans échéance, 0 %)', '1500.00', '0.00', 'EUR', null, Icon::Laptop],
            ['Voyage aux États-Unis (USD)', '3000.00', '500.00', 'USD', '+10 months', Icon::Plane],
            ['Objectif dépassé (250 % épargné)', '100.00', '250.00', 'EUR', '+1 month', Icon::Piggy],
            ['Objectif en retard (échéance passée)', '800.00', '320.00', 'EUR', '-1 month', null],
            ['Objectif au nom très long pour vérifier que le titre de la carte reste lisible et ne déborde pas', '10000.00', '1234.56', 'EUR', null, null],
        ] as [$name, $target, $saved, $currency, $when, $icon]) {
            $this->em->persist(
                (new Goal())->setOwner($user)->setName($name)->setTargetAmount($target)->setSavedAmount($saved)->setCurrency($currency)
                    ->setTargetDate(null === $when ? null : $this->thisMonth->modify($when))->setIcon($icon)
            );
        }
    }

    private function account(User $user, string $name, AccountType $type, string $currency, string $initialBalance): Account
    {
        $account = (new Account())->setOwner($user)->setName($name)->setType($type)->setCurrency($currency)->setInitialBalance($initialBalance);
        $this->em->persist($account);

        return $account;
    }

    private function category(User $user, string $name, ?Icon $icon, ?string $color = null): Category
    {
        $category = (new Category())->setOwner($user)->setName($name)->setIcon($icon)->setColor($color);
        $this->em->persist($category);

        return $category;
    }

    /** @param array<string, Category> $categories */
    private function tx(Account $account, array $categories, string $category, TransactionType $type, string $amount, \DateTimeImmutable $date, string $label): Transaction
    {
        return $this->transaction($account, $categories[$category], $type, $amount, $date, $label);
    }

    private function transaction(Account $account, Category $category, TransactionType $type, string $amount, \DateTimeImmutable $date, string $label): Transaction
    {
        $transaction = (new Transaction())->setAccount($account)->setCategory($category)->setType($type)->setAmount($amount)->setDate($date)->setLabel($label);
        $this->em->persist($transaction);

        return $transaction;
    }
}
