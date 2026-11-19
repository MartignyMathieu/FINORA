<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\Category;

final class CategoryControllerTest extends AbstractCrudTestCase
{
    public function testIndex(): void
    {
        $this->createCategory('Loisirs');

        $this->client->request('GET', '/category');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Loisirs');
    }

    public function testNew(): void
    {
        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Save', [
            'category[name]' => 'Transport',
            'category[color]' => '#3366ff',
        ]);

        self::assertResponseRedirects('/category');

        $category = $this->manager->getRepository(Category::class)->findOneBy(['name' => 'Transport']);
        self::assertNotNull($category);
        self::assertSame('#3366ff', $category->getColor());
    }

    public function testNewWithInvalidColorIsRejected(): void
    {
        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Save', [
            'category[name]' => 'Transport',
            'category[color]' => 'bleu',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Category::class)->count([]));
    }

    public function testNewWithDuplicateNameIsRejected(): void
    {
        $this->createCategory('Transport');

        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Save', ['category[name]' => 'Transport']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->manager->getRepository(Category::class)->count([]));
    }

    public function testShow(): void
    {
        $category = $this->createCategory('Santé');

        $this->client->request('GET', '/category/'.$category->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Santé');
    }

    public function testEdit(): void
    {
        $category = $this->createCategory();

        $this->client->request('GET', sprintf('/category/%d/edit', $category->getId()));
        $this->client->submitForm('Update', ['category[name]' => 'Alimentation']);

        self::assertResponseRedirects('/category');
        $this->manager->clear();
        self::assertSame('Alimentation', $this->manager->find(Category::class, $category->getId())->getName());
    }

    public function testRemoveAlsoRemovesItsBudgets(): void
    {
        $category = $this->createCategory();
        $this->createBudget($category);
        $this->manager->clear();

        $this->client->request('GET', '/category/'.$category->getId());
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/category');
        self::assertSame(0, $this->manager->getRepository(Category::class)->count([]));
        self::assertSame(0, $this->manager->getRepository(Budget::class)->count([]));
    }

    public function testRemoveIsRefusedWhenCategoryHasTransactions(): void
    {
        $category = $this->createCategory();
        $this->createTransaction($this->createAccount(), $category);
        $this->manager->clear();

        $this->client->request('GET', '/category/'.$category->getId());
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/category/'.$category->getId());
        self::assertSame(1, $this->manager->getRepository(Category::class)->count([]));
    }
}
