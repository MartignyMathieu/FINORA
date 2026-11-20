<?php

namespace App\Controller;

use App\Dto\BulkTransactionRequest;
use App\Dto\TransactionFilter;
use App\Entity\Account;
use App\Entity\Category;
use App\Entity\User;
use App\Repository\TransactionRepository;
use App\Service\UndoDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Actions en lot sur les transactions cochées dans la liste : supprimer, changer de catégorie, changer de compte.
 * Tout est annulable avec le bouton « Annuler » du message de confirmation (voir UndoDeletion).
 */
final class TransactionBulkController extends AbstractController
{
    #[Route('/bulk/transactions', name: 'app_transaction_bulk', methods: ['POST'])]
    public function apply(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] BulkTransactionRequest $bulk,
        TransactionRepository $repository,
        EntityManagerInterface $em,
        UndoDeletion $undo,
        #[CurrentUser] User $user,
    ): Response {
        $back = $this->redirectToRoute('app_transaction_index', $this->returnQuery($bulk->return), Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('bulk-transactions', $bulk->_token)) {
            $this->addFlash('error', 'Action impossible : la page a expiré, rechargez-la.');

            return $back;
        }

        // Seules les transactions de l'utilisateur comptent ; les autres identifiants sont ignorés.
        $transactions = $repository->findOwnedByIds($user, $bulk->transactionIds());
        if ([] === $transactions) {
            $this->addFlash('error', 'Aucune transaction à modifier : elles n\'existent plus.');

            return $back;
        }

        return match ($bulk->action) {
            BulkTransactionRequest::DELETE => $this->delete($transactions, $em, $undo, $user, $back),
            BulkTransactionRequest::CATEGORY => $this->changeCategory($bulk, $transactions, $em, $undo, $user, $back),
            default => $this->changeAccount($bulk, $transactions, $em, $undo, $user, $back),
        };
    }

    /** @param list<\App\Entity\Transaction> $transactions */
    private function delete(array $transactions, EntityManagerInterface $em, UndoDeletion $undo, User $user, Response $back): Response
    {
        $token = $undo->rememberTransactions($user, $transactions);
        foreach ($transactions as $transaction) {
            $em->remove($transaction);
        }
        $em->flush();

        $count = \count($transactions);
        $this->flash(sprintf('%d transaction%s supprimée%s.', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : ''), $token);

        return $back;
    }

    /** @param list<\App\Entity\Transaction> $transactions */
    private function changeCategory(BulkTransactionRequest $bulk, array $transactions, EntityManagerInterface $em, UndoDeletion $undo, User $user, Response $back): Response
    {
        $category = null !== $bulk->categoryId() ? $em->getRepository(Category::class)->findOneBy(['id' => $bulk->categoryId(), 'owner' => $user]) : null;
        if (null === $category) {
            $this->addFlash('error', 'Choisissez la catégorie de destination.');

            return $back;
        }

        // Les lignes déjà dans cette catégorie ne comptent pas comme modifiées.
        $changed = array_values(array_filter($transactions, static fn ($t): bool => $t->getCategory()?->getId() !== $category->getId()));
        if ([] === $changed) {
            $this->addFlash('success', sprintf('Rien à modifier : la sélection est déjà dans « %s ».', $category->getName()));

            return $back;
        }

        $token = $undo->rememberUpdate($user, 'category', $changed);
        foreach ($changed as $transaction) {
            $transaction->setCategory($category);
        }
        $em->flush();

        $count = \count($changed);
        $this->flash(sprintf('%d transaction%s déplacée%s vers « %s ».', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : '', $category->getName()), $token);

        return $back;
    }

    /** @param list<\App\Entity\Transaction> $transactions */
    private function changeAccount(BulkTransactionRequest $bulk, array $transactions, EntityManagerInterface $em, UndoDeletion $undo, User $user, Response $back): Response
    {
        $account = null !== $bulk->accountId() ? $em->getRepository(Account::class)->findOneBy(['id' => $bulk->accountId(), 'owner' => $user]) : null;
        if (null === $account) {
            $this->addFlash('error', 'Choisissez le compte de destination.');

            return $back;
        }

        // Changer de devise changerait le sens des montants : ces lignes restent où elles sont.
        $sameCurrency = array_values(array_filter($transactions, static fn ($t): bool => $t->getAccount()?->getCurrency() === $account->getCurrency()));
        $otherCurrency = \count($transactions) - \count($sameCurrency);
        $changed = array_values(array_filter($sameCurrency, static fn ($t): bool => $t->getAccount()?->getId() !== $account->getId()));

        if ([] === $changed) {
            $this->addFlash('error', $otherCurrency > 0
                ? sprintf('Aucune transaction déplacée : le compte « %s » est en %s, les transactions sélectionnées sont dans une autre devise.', $account->getName(), $account->getCurrency())
                : sprintf('Rien à modifier : la sélection est déjà dans « %s ».', $account->getName()));

            return $back;
        }

        $token = $undo->rememberUpdate($user, 'account', $changed);
        foreach ($changed as $transaction) {
            $transaction->setAccount($account);
        }
        $em->flush();

        $count = \count($changed);
        $message = sprintf('%d transaction%s déplacée%s vers « %s ».', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : '', $account->getName());
        if ($otherCurrency > 0) {
            $message .= sprintf(' %d autre%s restée%s en place (devise différente).', $otherCurrency, $otherCurrency > 1 ? 's' : '', $otherCurrency > 1 ? 's' : '');
        }
        $this->flash($message, $token);

        return $back;
    }

    private function flash(string $message, ?string $undoToken): void
    {
        $this->addFlash('success', null === $undoToken ? $message : ['message' => $message, 'undo' => $undoToken]);
    }

    /**
     * Filtres de la liste d'où l'on vient (chaîne de requête), relus par le filtre lui-même : rien d'autre que les critères connus
     * n'est repris dans l'adresse de retour.
     *
     * @return array<string, string>
     */
    private function returnQuery(string $raw): array
    {
        parse_str($raw, $params);
        $allowed = array_intersect_key($params, array_flip(['type', 'q', 'from', 'to', 'min', 'max', 'account', 'category']));
        $strings = array_filter($allowed, static fn (mixed $value): bool => \is_string($value));

        try {
            $filter = new TransactionFilter(...$strings);
        } catch (\Throwable) {
            return [];
        }

        return $filter->toQuery();
    }
}
