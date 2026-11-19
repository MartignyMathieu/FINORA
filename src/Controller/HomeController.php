<?php

namespace App\Controller;

use App\Service\FinanceStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use App\Enum\TransactionType;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        FinanceStats $stats,
        #[Autowire(param: 'app.display_name')] string $displayName,
        #[MapQueryParameter] ?string $month = null,
    ): Response {
        $month = FinanceStats::resolveMonth($month);

        return $this->render('home/index.html.twig', [
            'month' => $month,
            'displayName' => $displayName,
            'currency' => $stats->getCurrency(),
            'balance' => $stats->totalBalance($month),
            'variation' => $stats->balanceVariation($month),
            'totals' => $stats->monthTotals($month),
            'chart' => $stats->lineChart($stats->history($month)),
            'donut' => $stats->donut($stats->totalsByCategory($month, TransactionType::Expense)),
        ]);
    }
}
