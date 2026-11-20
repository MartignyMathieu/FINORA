<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\UndoDeletion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Annulation d'une suppression (bouton « Annuler » du message de confirmation). */
final class UndoController extends AbstractController
{
    #[Route('/undo/{token}', name: 'app_undo', requirements: ['token' => '[a-f0-9]{16}'], methods: ['POST'])]
    public function undo(Request $request, string $token, UndoDeletion $undo, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('undo'.$token, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Annulation impossible : la page a expiré, rechargez-la.');

            return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
        }

        $result = $undo->restore($user, $token);
        if (null === $result) {
            $this->addFlash('error', 'Le délai pour annuler cette suppression est dépassé.');

            return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
        }

        // Accord : « transaction restaurée », « virement restauré ».
        [$noun, $agreement] = UndoDeletion::KIND_TRANSFER === $result['kind'] ? ['virement', 'é'] : ['transaction', 'ée'];
        $message = 0 === $result['restored']
            ? 'Rien n\'a pu être restauré : le compte ou la catégorie concernés n\'existent plus.'
            : sprintf('%d %s%s restaur%s%s.', $result['restored'], $noun, $result['restored'] > 1 ? 's' : '', $agreement, $result['restored'] > 1 ? 's' : '');
        if ($result['skipped'] > 0 && $result['restored'] > 0) {
            $message .= sprintf(' %d ligne%s n\'a pas pu l\'être (compte ou catégorie supprimés).', $result['skipped'], $result['skipped'] > 1 ? 's' : '');
        }
        $this->addFlash(0 === $result['restored'] ? 'error' : 'success', $message);

        return $this->redirectToRoute(UndoDeletion::KIND_TRANSFER === $result['kind'] ? 'app_transfer_index' : 'app_transaction_index', [], Response::HTTP_SEE_OTHER);
    }
}
