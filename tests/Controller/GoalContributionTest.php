<?php

namespace App\Tests\Controller;

use App\Entity\Goal;
use App\Entity\GoalContribution;

/** Versements et retraits sur un objectif : épargne mise à jour avec l'historique, validation, isolation. */
final class GoalContributionTest extends AbstractCrudTestCase
{
    private function goal(string $saved = '300.00', string $target = '1000.00', ?\App\Entity\User $owner = null): Goal
    {
        $goal = (new Goal())->setOwner($owner ?? $this->user)->setName('Voiture')->setTargetAmount($target)->setSavedAmount($saved);
        $this->manager->persist($goal);
        $this->manager->flush();

        return $goal;
    }

    private function reload(Goal $goal): Goal
    {
        $this->manager->clear();

        return $this->manager->getRepository(Goal::class)->find($goal->getId());
    }

    private function submit(Goal $goal, string $kind, string $amount, string $note = ''): void
    {
        $this->client->request('GET', '/goal/'.$goal->getId().'/contribution/new');
        $this->client->submitForm('Enregistrer', [
            'goal_contribution[kind]' => $kind,
            'goal_contribution[amount]' => $amount,
            'goal_contribution[date]' => '2026-11-10',
            'goal_contribution[note]' => $note,
        ]);
    }

    public function testADepositRaisesTheSavingsAndIsLogged(): void
    {
        $goal = $this->goal('300.00');

        $this->submit($goal, 'deposit', '120.50', 'Prime');

        self::assertResponseRedirects('/goal/'.$goal->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'versement a été enregistré');
        self::assertSelectorTextContains('.contributions', 'Versement');
        self::assertSelectorTextContains('.contributions', 'Prime');
        self::assertSelectorTextContains('.contributions', "+120,50\u{00A0}€");

        $goal = $this->reload($goal);
        self::assertSame('420.50', $goal->getSavedAmount());
        self::assertCount(1, $goal->getContributions());
        self::assertSame('120.50', $goal->getContributions()->first()->getAmount());
    }

    public function testAWithdrawalLowersTheSavings(): void
    {
        $goal = $this->goal('300.00');

        $this->submit($goal, 'withdrawal', '100.00');

        self::assertResponseRedirects('/goal/'.$goal->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'retrait a été enregistré');
        self::assertSelectorTextContains('.contributions', 'Retrait');

        $goal = $this->reload($goal);
        self::assertSame('200.00', $goal->getSavedAmount());
        self::assertSame('-100.00', $goal->getContributions()->first()->getAmount());
        self::assertTrue($goal->getContributions()->first()->isWithdrawal());
    }

    public function testAWithdrawalAboveTheSavingsIsRejected(): void
    {
        $goal = $this->goal('50.00');

        $this->submit($goal, 'withdrawal', '80.00');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'dépasse le montant épargné');
        $goal = $this->reload($goal);
        self::assertSame('50.00', $goal->getSavedAmount());
        self::assertCount(0, $goal->getContributions());
    }

    public function testReachingTheTargetCongratulates(): void
    {
        $goal = $this->goal('900.00', '1000.00');

        $this->submit($goal, 'deposit', '100.00');
        $crawler = $this->client->followRedirect();

        $messages = implode(' | ', $crawler->filter('.flash-success')->each(static fn ($n): string => $n->text()));
        self::assertStringContainsString('versement a été enregistré', $messages);
        self::assertStringContainsString('atteint, bravo', $messages);
        self::assertSame('1000.00', $this->reload($goal)->getSavedAmount());
    }

    public function testNoCongratulationWhenTheGoalWasAlreadyReached(): void
    {
        $goal = $this->goal('1000.00', '1000.00');

        $this->submit($goal, 'deposit', '10.00');
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('.flash-success'));
    }

    public function testNonPositiveAmountsAreRejected(): void
    {
        $goal = $this->goal();

        foreach (['0', '-5.00'] as $amount) {
            $this->submit($goal, 'deposit', $amount);
            self::assertResponseStatusCodeSame(422, $amount);
        }
        self::assertSame('300.00', $this->reload($goal)->getSavedAmount());
    }

    public function testDeletingALineRestoresTheSavings(): void
    {
        $goal = $this->goal('300.00');
        $this->submit($goal, 'deposit', '120.00');
        $this->submit($goal, 'withdrawal', '20.00');
        self::assertSame('400.00', $this->reload($goal)->getSavedAmount());

        $contributions = $this->manager->getRepository(GoalContribution::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $contributions);

        // Supprimer le versement de 120 € : 400 − 120 = 280.
        $this->client->request('GET', '/goal/'.$goal->getId());
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/'.$contributions[0]->getId().'/delete"]')->form());

        self::assertResponseRedirects('/goal/'.$goal->getId());
        $goal = $this->reload($goal);
        self::assertSame('280.00', $goal->getSavedAmount());
        self::assertCount(1, $goal->getContributions());
    }

    public function testDeletingNeverMakesTheSavingsNegative(): void
    {
        $goal = $this->goal('300.00');
        $this->submit($goal, 'deposit', '200.00');
        // L'épargne a ensuite été corrigée à la main, en dessous du versement.
        $goal = $this->reload($goal);
        $goal->setSavedAmount('50.00');
        $this->manager->flush();
        $contribution = $this->manager->getRepository(GoalContribution::class)->findOneBy([]);

        $this->client->request('GET', '/goal/'.$goal->getId());
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/'.$contribution->getId().'/delete"]')->form());

        self::assertSame('0.00', $this->reload($goal)->getSavedAmount());
    }

    public function testTheHistoryIsShownNewestFirst(): void
    {
        $goal = $this->goal();
        foreach ([['2026-09-01', '10.00'], ['2026-11-01', '30.00'], ['2026-10-01', '20.00']] as [$date, $amount]) {
            $this->manager->persist((new GoalContribution())->setGoal($goal)->setAmount($amount)->setDate(new \DateTimeImmutable($date)));
        }
        $this->manager->flush();
        $this->manager->clear();

        $crawler = $this->client->request('GET', '/goal/'.$goal->getId());

        $amounts = $crawler->filter('.contributions .amount-in')->each(static fn ($n): string => $n->text());
        self::assertSame(["+30,00\u{00A0}€", "+20,00\u{00A0}€", "+10,00\u{00A0}€"], $amounts);
    }

    public function testTheGoalPageAndTheCardLinkToTheForm(): void
    {
        $goal = $this->goal();

        $this->client->request('GET', '/goal/'.$goal->getId());
        self::assertSelectorExists('a[href="/goal/'.$goal->getId().'/contribution/new"]');

        $this->client->request('GET', '/goal');
        self::assertSelectorExists('.goal-back a[href="/goal/'.$goal->getId().'/contribution/new"]');
    }

    public function testAnotherUsersGoalIsForbidden(): void
    {
        $other = $this->createUser('bob@example.test', 'Bob');
        $goal = $this->goal('300.00', '1000.00', $other);
        $contribution = (new GoalContribution())->setGoal($goal)->setAmount('10.00')->setDate(new \DateTimeImmutable('2026-11-01'));
        $this->manager->persist($contribution);
        $this->manager->flush();

        $this->client->request('GET', '/goal/'.$goal->getId().'/contribution/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/goal/'.$goal->getId().'/contribution/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/goal/'.$goal->getId().'/contribution/'.$contribution->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('300.00', $this->reload($goal)->getSavedAmount());
    }

    public function testALineCannotBeDeletedThroughAnotherGoalOfTheSameUser(): void
    {
        $goal = $this->goal('300.00');
        $other = $this->goal('500.00');
        $contribution = (new GoalContribution())->setGoal($other)->setAmount('10.00')->setDate(new \DateTimeImmutable('2026-11-01'));
        $this->manager->persist($contribution);
        $this->manager->flush();

        $this->client->request('POST', '/goal/'.$goal->getId().'/contribution/'.$contribution->getId().'/delete');

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->manager->getRepository(GoalContribution::class)->findAll());
    }

    public function testDeletingAGoalRemovesItsHistory(): void
    {
        $goal = $this->goal();
        $this->submit($goal, 'deposit', '10.00');
        self::assertCount(1, $this->manager->getRepository(GoalContribution::class)->findAll());

        $this->client->request('GET', '/goal/'.$goal->getId().'/edit');
        $this->client->submitForm('Supprimer');

        self::assertCount(0, $this->manager->getRepository(GoalContribution::class)->findAll());
    }

    public function testTheFormRequiresALogin(): void
    {
        $goal = $this->goal();
        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/goal/'.$goal->getId().'/contribution/new');

        self::assertResponseRedirects('http://localhost/login');
    }
}
