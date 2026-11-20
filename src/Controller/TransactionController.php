<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Dto\TransactionFilter;
use App\Entity\Account;
use App\Entity\Category;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Service\UndoDeletion;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use App\Form\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;
use App\Security\OwnerVoter;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/transaction')]
final class TransactionController extends AbstractController
{
    #[Route(name: 'app_transaction_index', methods: ['GET'])]
    public function index(
        TransactionRepository $transactionRepository,
        AccountRepository $accountRepository,
        CategoryRepository $categoryRepository,
        ClockInterface $clock,
        #[CurrentUser] User $user,
        #[Autowire(param: 'app.default_currency')] string $currency,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ?TransactionFilter $filter = null,
    ): Response {
        $filter ??= new TransactionFilter();
        $accounts = $accountRepository->findBy(['owner' => $user], ['name' => 'ASC']);
        $categories = $categoryRepository->findBy(['owner' => $user], ['name' => 'ASC']);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactionRepository->search($user, $filter),
            'totals' => $transactionRepository->totals($user, $filter, $currency),
            'limit' => $transactionRepository->limit(),
            'currency' => $currency,
            'filter' => $filter,
            // Onglets et recherche gardent les autres critères.
            'type' => $filter->transactionType()?->value ?? 'all',
            'q' => $filter->searchText(),
            'accounts' => $accounts,
            'categories' => $categories,
            'chips' => $this->chips($filter, $accounts, $categories),
            'presets' => $this->presets($clock->now()),
        ]);
    }

    #[Route('/new', name: 'app_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, #[MapQueryParameter] ?string $date = null): Response
    {
        // Date pré-remplie : celle du jour choisi dans le calendrier (?date=AAAA-MM-JJ), sinon aujourd'hui.
        $day = null !== $date ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        $transaction = (new Transaction())->setDate(false !== $day && $day->format('Y-m-d') === $date ? $day : new \DateTimeImmutable('today'));
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($transaction);
            $entityManager->flush();

            return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('transaction/new.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'transaction')]
    #[Route('/{id}', name: 'app_transaction_show', methods: ['GET'])]
    public function show(Transaction $transaction): Response
    {
        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'transaction')]
    #[Route('/{id}/edit', name: 'app_transaction_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Transaction $transaction, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('transaction/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[IsGranted(OwnerVoter::MANAGE, 'transaction')]
    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'])]
    public function delete(Request $request, Transaction $transaction, EntityManagerInterface $entityManager, UndoDeletion $undo, #[CurrentUser] User $user): Response
    {
        if ($this->isCsrfTokenValid('delete'.$transaction->getId(), $request->getPayload()->getString('_token'))) {
            // Copie gardée avant la suppression : le message de confirmation propose de l'annuler.
            $token = $undo->rememberTransactions($user, [$transaction]);
            $entityManager->remove($transaction);
            $entityManager->flush();
            if (null !== $token) {
                $this->addFlash('success', ['message' => 'La transaction a été supprimée.', 'undo' => $token]);
            }
        }

        return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Critères avancés actifs, chacun avec le lien qui le retire.
     *
     * @param list<Account>  $accounts
     * @param list<Category> $categories
     *
     * @return list<array{label: string, query: array<string, string>}>
     */
    private function chips(TransactionFilter $filter, array $accounts, array $categories): array
    {
        $chips = [];
        $from = $filter->fromDate();
        $to = $filter->toDate();
        if (null !== $from || null !== $to) {
            $chips[] = [
                'label' => match (true) {
                    null !== $from && null !== $to => sprintf('Du %s au %s', $from->format('d/m/Y'), $to->format('d/m/Y')),
                    null !== $from => 'Depuis le '.$from->format('d/m/Y'),
                    default => 'Jusqu\'au '.$to->format('d/m/Y'),
                },
                'query' => $filter->without(['from', 'to']),
            ];
        }
        $min = $filter->minAmount();
        $max = $filter->maxAmount();
        if (null !== $min || null !== $max) {
            $chips[] = [
                'label' => match (true) {
                    null !== $min && null !== $max => sprintf('Montant de %s à %s', $this->amount($min), $this->amount($max)),
                    null !== $min => 'Montant d\'au moins '.$this->amount($min),
                    default => 'Montant d\'au plus '.$this->amount($max),
                },
                'query' => $filter->without(['min', 'max']),
            ];
        }
        foreach ($accounts as $account) {
            if ($account->getId() === $filter->accountId()) {
                $chips[] = ['label' => 'Compte : '.$account->getName(), 'query' => $filter->without(['account'])];
            }
        }
        foreach ($categories as $category) {
            if ($category->getId() === $filter->categoryId()) {
                $chips[] = ['label' => 'Catégorie : '.$category->getName(), 'query' => $filter->without(['category'])];
            }
        }

        return $chips;
    }

    private function amount(string $amount): string
    {
        return str_replace('.', ',', $amount);
    }

    /**
     * Périodes prêtes à l'emploi.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    private function presets(\DateTimeInterface $now): array
    {
        $today = \DateTimeImmutable::createFromInterface($now)->setTime(0, 0);
        $month = $today->modify('first day of this month');

        return [
            ['label' => 'Ce mois-ci', 'from' => $month->format('Y-m-d'), 'to' => $month->modify('last day of this month')->format('Y-m-d')],
            ['label' => 'Mois dernier', 'from' => $month->modify('-1 month')->format('Y-m-d'), 'to' => $month->modify('-1 day')->format('Y-m-d')],
            ['label' => '30 derniers jours', 'from' => $today->modify('-29 days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            ['label' => 'Cette année', 'from' => $today->format('Y').'-01-01', 'to' => $today->format('Y').'-12-31'],
        ];
    }
}
