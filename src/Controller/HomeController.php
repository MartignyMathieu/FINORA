<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\TransactionType;
use App\Service\FinanceStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        FinanceStats $stats,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $month = null,
    ): Response {
        $month = FinanceStats::resolveMonth($month);

        return $this->render('home/index.html.twig', [
            'month' => $month,
            'displayName' => $user->getDisplayName(),
            'currency' => $stats->getCurrency(),
            'balance' => $stats->totalBalance($month),
            'variation' => $stats->balanceVariation($month),
            'totals' => $stats->monthTotals($month),
            'chart' => $stats->lineChart($stats->history($month)),
            'donut' => $stats->donut($stats->totalsByCategory($month, TransactionType::Expense)),
        ]);
    }
}
