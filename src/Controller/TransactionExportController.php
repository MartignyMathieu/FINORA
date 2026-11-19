<?php

namespace App\Controller;

use App\Dto\TransactionExportQuery;
use App\Entity\User;
use App\Service\TransactionCsvExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class TransactionExportController extends AbstractController
{
    /**
     * Export CSV des transactions de l'utilisateur connecté (toutes, ou filtrées par type, texte et période).
     * Paramètres facultatifs : type, q, from, to, format (voir TransactionExportQuery).
     */
    #[Route('/export/transactions', name: 'app_export_transactions', methods: ['GET'])]
    public function transactions(
        TransactionCsvExporter $exporter,
        ClockInterface $clock,
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ?TransactionExportQuery $query = null,
    ): Response {
        $query ??= new TransactionExportQuery();

        $response = new StreamedResponse(static function () use ($exporter, $user, $query): void {
            $out = fopen('php://output', 'wb');
            $exporter->write($out, $user, $query);
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('finora-transactions-%s.csv', $clock->now()->format('Y-m-d')),
        ));
        // Données financières personnelles : ni cache partagé, ni interprétation du contenu par le navigateur.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
