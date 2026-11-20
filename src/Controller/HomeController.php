<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\TransactionType;
use App\Service\FinanceStats;
use App\Service\ForecastBuilder;
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
        ForecastBuilder $forecasts,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $month = null,
    ): Response {
        $month = FinanceStats::resolveMonth($month);

        // La prévision de fin de mois n'a de sens que pour le mois en cours.
        $forecast = $forecasts->build($user);
        if ($month->format('Y-m') !== $forecast['today']->format('Y-m')) {
            $forecast = null;
        }

        // Les quatre budgets les plus consommés du mois.
        $budgets = $stats->budgetProgress($month);
        usort($budgets, static fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        return $this->render('home/index.html.twig', [
            'month' => $month,
            'displayName' => $user->getDisplayName(),
            'currency' => $stats->getCurrency(),
            'balance' => $stats->totalBalance($month),
            'variation' => $stats->balanceVariation($month),
            'totals' => $stats->monthTotals($month),
            'chart' => $stats->lineChart($stats->history($month)),
            'forecast' => $forecast,
            'recent' => $stats->recentTransactions($month),
            'budgets' => array_slice($budgets, 0, 4),
            'donut' => $stats->donut($stats->totalsByCategory($month, TransactionType::Expense)),
        ]);
    }
}
