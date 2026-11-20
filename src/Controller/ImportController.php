<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ImportMappingType;
use App\Form\ImportUploadType;
use App\Service\CsvReader;
use App\Service\TransactionImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Import de transactions depuis un fichier CSV, en deux étapes : envoi du fichier, puis correspondance des colonnes avec
 * aperçu et confirmation. Le fichier lu est gardé dans la session de l'utilisateur entre les deux étapes.
 */
#[Route('/import/transactions')]
final class ImportController extends AbstractController
{
    private const SESSION_KEY = 'import.transactions';

    #[Route(name: 'app_import_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request, CsvReader $reader): Response
    {
        $form = $this->createForm(ImportUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();

            try {
                $request->getSession()->set(self::SESSION_KEY, $reader->read((string) file_get_contents($file->getPathname())));

                return $this->redirectToRoute('app_import_map', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $form->get('file')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('import/upload.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/map', name: 'app_import_map', methods: ['GET', 'POST'])]
    public function map(Request $request, TransactionImporter $importer, #[CurrentUser] User $user): Response
    {
        $session = $request->getSession();
        $file = $session->get(self::SESSION_KEY);
        if (!\is_array($file) || !isset($file['header'], $file['rows'])) {
            $this->addFlash('error', 'Aucun fichier à importer : envoyez d\'abord un fichier CSV.');

            return $this->redirectToRoute('app_import_upload', [], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(ImportMappingType::class, $this->defaults($importer, $file['header']), ['header' => $file['header']]);
        $form->handleRequest($request);

        $analysis = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $mapping = [];
            foreach (['date', 'label', 'amount', 'type', 'account', 'category', 'note'] as $field) {
                $mapping[$field] = isset($data[$field]) && '' !== $data[$field] ? (int) $data[$field] : null;
            }
            $analysis = $importer->analyse($user, $file['rows'], $mapping, $data['defaultAccount'], $data['fallbackCategory'], (bool) $data['createCategories']);

            if ($form->get('import')->isClicked()) {
                $result = $importer->import($user, $analysis['rows'], (bool) $data['skipDuplicates']);
                $session->remove(self::SESSION_KEY);
                $this->addFlash('success', $this->summary($result));

                return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('import/map.html.twig', [
            'form' => $form,
            'file' => $file,
            'analysis' => $analysis,
            'previewLimit' => 30,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/cancel', name: 'app_import_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        if ($this->isCsrfTokenValid('import-cancel', $request->getPayload()->getString('_token'))) {
            $request->getSession()->remove(self::SESSION_KEY);
        }

        return $this->redirectToRoute('app_transaction_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Valeurs proposées au départ : colonnes reconnues d'après les en-têtes ; création des catégories et ignorance des doublons activées.
     *
     * @param list<string> $header
     *
     * @return array<string, mixed>
     */
    private function defaults(TransactionImporter $importer, array $header): array
    {
        $mapping = array_map(static fn (?int $index): ?string => null === $index ? null : (string) $index, $importer->guessMapping($header));

        return $mapping + ['createCategories' => true, 'skipDuplicates' => true];
    }

    /** @param array{imported: int, skippedDuplicates: int, invalid: int, categoriesCreated: int} $result */
    private function summary(array $result): string
    {
        $parts = [sprintf('%d transaction%s importée%s', $result['imported'], $result['imported'] > 1 ? 's' : '', $result['imported'] > 1 ? 's' : '')];
        if ($result['skippedDuplicates'] > 0) {
            $parts[] = sprintf('%d doublon%s ignoré%s', $result['skippedDuplicates'], $result['skippedDuplicates'] > 1 ? 's' : '', $result['skippedDuplicates'] > 1 ? 's' : '');
        }
        if ($result['invalid'] > 0) {
            $parts[] = sprintf('%d ligne%s invalide%s ignorée%s', $result['invalid'], $result['invalid'] > 1 ? 's' : '', $result['invalid'] > 1 ? 's' : '', $result['invalid'] > 1 ? 's' : '');
        }
        if ($result['categoriesCreated'] > 0) {
            $parts[] = sprintf('%d catégorie%s créée%s', $result['categoriesCreated'], $result['categoriesCreated'] > 1 ? 's' : '', $result['categoriesCreated'] > 1 ? 's' : '');
        }

        return implode(', ', $parts).'.';
    }
}
