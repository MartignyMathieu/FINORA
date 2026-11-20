<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\BackupRestoreType;
use App\Service\BackupExporter;
use App\Service\BackupRestorer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Sauvegarde complète des données de l'utilisateur (téléchargement) et restauration depuis un fichier de sauvegarde.
 * Une sauvegarde permet de retrouver ses données si l'hébergement de la base change ou expire.
 */
#[Route('/profile/backup')]
final class BackupController extends AbstractController
{
    #[Route('/download', name: 'app_backup_download', methods: ['GET'])]
    public function download(BackupExporter $exporter, ClockInterface $clock, #[CurrentUser] User $user): Response
    {
        $response = new Response($exporter->toJson($user));
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('finora-sauvegarde-%s.json', $clock->now()->format('Y-m-d')),
        ));
        // Données financières personnelles : ni cache partagé, ni interprétation du contenu par le navigateur.
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/restore', name: 'app_backup_restore', methods: ['GET', 'POST'])]
    public function restore(Request $request, BackupRestorer $restorer, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(BackupRestoreType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();

            try {
                $data = $restorer->parse((string) file_get_contents($file->getPathname()));
                $counts = $restorer->restore($user, $data);
                $this->addFlash('success', $this->summary($counts));

                return $this->redirectToRoute('app_profile', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $form->get('file')->addError(new FormError($e->getMessage().' Vos données actuelles n\'ont pas été modifiées.'));
            }
        }

        return $this->render('backup/restore.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @param array<string, int> $counts */
    private function summary(array $counts): string
    {
        $labels = [
            'accounts' => ['compte', 'comptes'], 'categories' => ['catégorie', 'catégories'], 'transactions' => ['transaction', 'transactions'],
            'recurring' => ['récurrence', 'récurrences'], 'budgets' => ['budget', 'budgets'], 'goals' => ['objectif', 'objectifs'],
            'contributions' => ['versement d\'objectif', 'versements d\'objectifs'], 'transfers' => ['virement', 'virements'],
        ];
        $parts = [];
        foreach ($labels as $key => [$one, $many]) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = sprintf('%d %s', $counts[$key], $counts[$key] > 1 ? $many : $one);
            }
        }

        return 'Sauvegarde restaurée : '.([] === $parts ? 'aucune donnée (la sauvegarde était vide)' : implode(', ', $parts)).'.';
    }
}
