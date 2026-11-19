<?php

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\Goal;
use App\Entity\User;

/**
 * Régression : vider un champ obligatoire en MODIFIANT un élément existant faisait planter le formulaire
 * (setter typé non nullable appelé avec null → erreur 500) au lieu d'afficher une erreur de validation.
 * À la création le cas passait par hasard : la propriété valait déjà null, le setter n'était pas appelé.
 */
final class EditFormsBlankFieldsTest extends AbstractCrudTestCase
{
    /** @return array<string, array{string, string, string, string}> [créateur, champ, bouton] */
    private function cases(): array
    {
        $account = $this->createAccount();
        $category = $this->createCategory();
        $goal = $this->createGoal();
        $transaction = $this->createTransaction($account, $category);
        $budget = $this->createBudget($category);
        $recurring = $this->createRecurring($account, $category);

        return [
            'compte : nom' => ["/account/{$account->getId()}/edit", 'account[name]', 'Mettre à jour'],
            'catégorie : nom' => ["/category/{$category->getId()}/edit", 'category[name]', 'Mettre à jour'],
            'objectif : nom' => ["/goal/{$goal->getId()}/edit", 'goal[name]', 'Mettre à jour'],
            'objectif : montant visé' => ["/goal/{$goal->getId()}/edit", 'goal[targetAmount]', 'Mettre à jour'],
            'transaction : libellé' => ["/transaction/{$transaction->getId()}/edit", 'transaction[label]', 'Mettre à jour'],
            'transaction : montant' => ["/transaction/{$transaction->getId()}/edit", 'transaction[amount]', 'Mettre à jour'],
            'transaction : date' => ["/transaction/{$transaction->getId()}/edit", 'transaction[date]', 'Mettre à jour'],
            'budget : plafond' => ["/budget/{$budget->getId()}/edit", 'budget[amount]', 'Mettre à jour'],
            'budget : mois' => ["/budget/{$budget->getId()}/edit", 'budget[month]', 'Mettre à jour'],
            'récurrence : libellé' => ["/recurring/{$recurring->getId()}/edit", 'recurring_transaction[label]', 'Mettre à jour'],
            'récurrence : montant' => ["/recurring/{$recurring->getId()}/edit", 'recurring_transaction[amount]', 'Mettre à jour'],
            'récurrence : première échéance' => ["/recurring/{$recurring->getId()}/edit", 'recurring_transaction[startDate]', 'Mettre à jour'],
            'profil : prénom' => ['/profile', 'profile[displayName]', 'Enregistrer'],
            'profil : e-mail' => ['/profile', 'change_email[email]', 'Changer l\'adresse e-mail'],
        ];
    }

    public function testEmptyingARequiredFieldWhenEditingIsAValidationErrorNotACrash(): void
    {
        foreach ($this->cases() as $case => [$url, $field, $button]) {
            $this->client->request('GET', $url);
            $values = [$field => ''];
            if (str_starts_with($field, 'change_email')) {
                $values['change_email[currentPassword]'] = self::PASSWORD;
            }
            $this->client->submitForm($button, $values);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertSelectorExists('form ul li', $case.' : un message d\'erreur est affiché');
        }
    }

    public function testNothingWasChangedByTheRejectedSubmissions(): void
    {
        foreach ($this->cases() as [$url, $field, $button]) {
            $this->client->request('GET', $url);
            $this->client->submitForm($button, [$field => '']);
        }

        $this->manager->clear();
        self::assertSame('alice@example.test', $this->manager->getRepository(User::class)->findOneBy([])->getEmail());
        self::assertSame('Alice', $this->manager->getRepository(User::class)->findOneBy([])->getDisplayName());
    }

    public function testAnEmptyInitialBalanceMeansZero(): void
    {
        $account = $this->createAccount();
        $account->setInitialBalance('250.00');
        $this->manager->flush();

        $this->client->request('GET', "/account/{$account->getId()}/edit");
        $this->client->submitForm('Mettre à jour', ['account[initialBalance]' => '']);

        self::assertResponseRedirects('/account');
        $this->manager->clear();
        self::assertSame('0.00', $this->manager->find(Account::class, $account->getId())->getInitialBalance());
    }

    public function testAnEmptySavedAmountMeansNothingSavedYet(): void
    {
        $goal = $this->createGoal('Vacances', '2000.00', '500.00');

        $this->client->request('GET', "/goal/{$goal->getId()}/edit");
        $this->client->submitForm('Mettre à jour', ['goal[savedAmount]' => '']);

        self::assertResponseRedirects('/goal');
        $this->manager->clear();
        self::assertSame('0.00', $this->manager->find(Goal::class, $goal->getId())->getSavedAmount());
    }
}
