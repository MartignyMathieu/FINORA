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
        $this->client->submitForm('Enregistrer', [
            'category[name]' => 'Transport',
            'category[color]' => '#1d6cf0',
        ]);

        self::assertResponseRedirects('/category');

        $category = $this->manager->getRepository(Category::class)->findOneBy(['name' => 'Transport']);
        self::assertNotNull($category);
        self::assertSame('#1d6cf0', $category->getColor());
    }

    public function testNewWithInvalidColorIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/category/new');
        $form = $crawler->selectButton('Enregistrer')->form(['category[name]' => 'Transport']);
        // Une valeur hors palette (par exemple un code saisi à la main) est refusée.
        $form['category[color]']->disableValidation()->setValue('#3366ff');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager->getRepository(Category::class)->count([]));
    }

    public function testTheColorIsChosenInPalettesNotTypedAsHexadecimal(): void
    {
        $crawler = $this->client->request('GET', '/category/new');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type="text"][name="category[color]"]'), 'Plus de champ hexadécimal');
        // 3 palettes de 8 couleurs + « Automatique ».
        self::assertCount(25, $crawler->filter('.color-choice input[type="radio"][name="category[color]"]'));
        self::assertSame(['Vives', 'Douces', 'Profondes'], $crawler->filter('.color-palette-name')->each(static fn ($n): string => $n->text()));
        self::assertSelectorExists('.color-choice[title="Bleu"] .color-dot');
        self::assertStringNotContainsString('hexadécimal', (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutAChosenColorTheCategoryStaysAutomatic(): void
    {
        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Enregistrer', ['category[name]' => 'Sans couleur']);

        self::assertResponseRedirects('/category');
        self::assertNull($this->manager->getRepository(Category::class)->findOneBy(['name' => 'Sans couleur'])?->getColor());
    }

    public function testACategoryColorOutsideThePalettesIsKeptUntilAnotherIsChosen(): void
    {
        $category = $this->createCategory('Ancienne', '#3366ff');

        $crawler = $this->client->request('GET', '/category/'.$category->getId().'/edit');
        self::assertCount(26, $crawler->filter('.color-choice input[type="radio"]'), '25 choix + « Couleur actuelle »');
        self::assertSame('#3366ff', $crawler->filter('.color-choice input:checked')->attr('value'));
        self::assertContains('Couleur actuelle', $crawler->filter('.color-palette-name')->each(static fn ($n): string => $n->text()));

        // Enregistrer sans toucher à la couleur la conserve.
        $this->client->submitForm('Mettre à jour', ['category[name]' => 'Ancienne renommée']);
        self::assertResponseRedirects('/category');
        $this->manager->clear();
        self::assertSame('#3366ff', $this->manager->getRepository(Category::class)->find($category->getId())->getColor());

        // Choisir une couleur de palette la remplace, et « Couleur actuelle » disparaît.
        $this->client->request('GET', '/category/'.$category->getId().'/edit');
        $this->client->submitForm('Mettre à jour', ['category[color]' => '#22c55e']);
        $this->manager->clear();
        self::assertSame('#22c55e', $this->manager->getRepository(Category::class)->find($category->getId())->getColor());
        $crawler = $this->client->request('GET', '/category/'.$category->getId().'/edit');
        self::assertCount(25, $crawler->filter('.color-choice input[type="radio"]'));
    }

    public function testNewWithDuplicateNameIsRejected(): void
    {
        $this->createCategory('Transport');

        $this->client->request('GET', '/category/new');
        $this->client->submitForm('Enregistrer', ['category[name]' => 'Transport']);

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
        $this->client->submitForm('Mettre à jour', ['category[name]' => 'Alimentation']);

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
        $this->client->submitForm('Supprimer');

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
        $this->client->submitForm('Supprimer');

        self::assertResponseRedirects('/category/'.$category->getId());
        self::assertSame(1, $this->manager->getRepository(Category::class)->count([]));
    }
}
