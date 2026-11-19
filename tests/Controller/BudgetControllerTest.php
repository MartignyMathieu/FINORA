<?php

namespace App\Tests\Controller;

use App\Entity\Budget;

final class BudgetControllerTest extends AbstractCrudTestCase
{
    public function testIndex(): void
    {
        $this->createBudget($this->createCategory('Loisirs'));

        $this->client->request('GET', '/budget?month=2026-11');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.list', 'Loisirs');
    }

    public function testNewNormalizesMonthToFirstDay(): void
    {
        $category = $this->createCategory();

        $this->client->request('GET', '/budget/new');
        $this->client->submitForm('Enregistrer', [
            'budget[category]' => (string) $category->getId(),
            'budget[month]' => '2026-12-17',
            'budget[amount]' => '250',
            'budget[currency]' => 'EUR',
        ]);

        self::assertResponseRedirects('/budget');

        $budget = $this->manager->getRepository(Budget::class)->findOneBy([]);
        self::assertNotNull($budget);
        self::assertSame('2026-12-01', $budget->getMonth()->format('Y-m-d'));
        self::assertSame('250.00', $budget->getAmount());
    }

    public function testNewRejectsSecondBudgetForSameCategoryAndMonth(): void
    {
        $category = $this->createCategory();
        $this->createBudget($category, '2026-11-01');

        $this->client->request('GET', '/budget/new');
        $this->client->submitForm('Enregistrer', [
            'budget[category]' => (string) $category->getId(),
            'budget[month]' => '2026-11-20',
            'budget[amount]' => '100',
            'budget[currency]' => 'EUR',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->manager->getRepository(Budget::class)->count([]));
    }

    public function testNewRejectsZeroAmount(): void
    {
        $category = $this->createCategory();

        $this->client->request('GET', '/budget/new');
        $this->client->submitForm('Enregistrer', [
            'budget[category]' => (string) $category->getId(),
            'budget[month]' => '2026-11-01',
            'budget[amount]' => '0',
            'budget[currency]' => 'EUR',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testShow(): void
    {
        $budget = $this->createBudget($this->createCategory('Santé'));

        $this->client->request('GET', '/budget/'.$budget->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Santé');
    }

    public function testEdit(): void
    {
        $budget = $this->createBudget($this->createCategory());

        $this->client->request('GET', sprintf('/budget/%d/edit', $budget->getId()));
        $this->client->submitForm('Mettre à jour', ['budget[amount]' => '450']);

        self::assertResponseRedirects('/budget');
        $this->manager->clear();
        self::assertSame('450.00', $this->manager->find(Budget::class, $budget->getId())->getAmount());
    }

    public function testRemove(): void
    {
        $budget = $this->createBudget($this->createCategory());

        $this->client->request('GET', '/budget/'.$budget->getId());
        $this->client->submitForm('Supprimer');

        self::assertResponseRedirects('/budget');
        self::assertSame(0, $this->manager->getRepository(Budget::class)->count([]));
    }
}
