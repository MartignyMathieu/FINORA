<?php

namespace App\Controller;

use App\Enum\TransactionType;
use App\Service\FinanceStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class StatsController extends AbstractController
{
    #[Route('/stats', name: 'app_stats', methods: ['GET'])]
    public function index(
        FinanceStats $stats,
        #[MapQueryParameter] ?string $month = null,
        #[MapQueryParameter] string $type = 'expense',
    ): Response {
        $month = FinanceStats::resolveMonth($month);
        $kind = TransactionType::tryFrom($type) ?? TransactionType::Expense;
        $rows = $stats->totalsByCategory($month, $kind);

        return $this->render('stats/index.html.twig', [
            'month' => $month,
            'type' => $kind->value,
            'currency' => $stats->getCurrency(),
            'donut' => $stats->donut($rows),
        ]);
    }
}
