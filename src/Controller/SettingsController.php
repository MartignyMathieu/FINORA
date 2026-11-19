<?php

namespace App\Controller;

use App\Service\FinanceStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET'])]
    public function index(FinanceStats $stats): Response
    {
        return $this->render('settings/index.html.twig', [
            'currency' => $stats->getCurrency(),
        ]);
    }
}
