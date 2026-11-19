<?php

namespace App\Tests\Controller;

use App\Entity\Goal;
use App\Enum\Icon;

final class GoalControllerTest extends AbstractCrudTestCase
{
    public function testIndexShowsProgressAndDeadline(): void
    {
        $this->createGoal('Vacances', '2000.00', '1200.00');

        $this->client->request('GET', '/goal');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.goals', 'Vacances');
        self::assertSelectorTextContains('.goals', '60');
        self::assertSelectorTextContains('.goals', 'Échéance : Août 2027');
    }

    public function testIndexIsEmptyByDefault(): void
    {
        $this->client->request('GET', '/goal');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.goals', 'Aucun objectif');
    }

    public function testNew(): void
    {
        $this->client->request('GET', '/goal/new');
        $this->client->submitForm('Enregistrer', [
            'goal[name]' => 'Nouveau PC',
            'goal[icon]' => Icon::Laptop->value,
            'goal[targetAmount]' => '1500',
            'goal[savedAmount]' => '750',
            'goal[currency]' => 'EUR',
            'goal[targetDate]' => '2027-03-01',
        ]);

        self::assertResponseRedirects('/goal');

        $goal = $this->manager->getRepository(Goal::class)->findOneBy(['name' => 'Nouveau PC']);
        self::assertNotNull($goal);
        self::assertSame('1500.00', $goal->getTargetAmount());
        self::assertSame(Icon::Laptop, $goal->getIcon());
        self::assertSame(50, $goal->getProgress());
    }

    public function testNewRejectsNonPositiveTarget(): void
    {
        $this->client->request('GET', '/goal/new');
        $this->client->submitForm('Enregistrer', [
            'goal[name]' => 'Impossible',
            'goal[targetAmount]' => '0',
            'goal[savedAmount]' => '0',
            'goal[currency]' => 'EUR',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Goal::class)->count([]));
    }

    public function testProgressIsCappedAtOneHundredPercent(): void
    {
        $goal = $this->createGoal('Dépassé', '100.00', '250.00');

        self::assertSame(100, $goal->getProgress());
    }

    public function testShow(): void
    {
        $goal = $this->createGoal('Voiture');

        $this->client->request('GET', '/goal/'.$goal->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Voiture');
    }

    public function testEdit(): void
    {
        $goal = $this->createGoal();

        $this->client->request('GET', sprintf('/goal/%d/edit', $goal->getId()));
        $this->client->submitForm('Mettre à jour', ['goal[savedAmount]' => '900']);

        self::assertResponseRedirects('/goal');
        $this->manager->clear();
        self::assertSame('900.00', $this->manager->find(Goal::class, $goal->getId())->getSavedAmount());
    }

    public function testRemove(): void
    {
        $goal = $this->createGoal();

        $this->client->request('GET', '/goal/'.$goal->getId());
        $this->client->submitForm('Supprimer');

        self::assertResponseRedirects('/goal');
        self::assertSame(0, $this->manager->getRepository(Goal::class)->count([]));
    }
}
