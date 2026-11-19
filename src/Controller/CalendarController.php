<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CalendarBuilder;
use App\Service\FinanceStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class CalendarController extends AbstractController
{
    #[Route('/calendar', name: 'app_calendar', methods: ['GET'])]
    public function index(
        CalendarBuilder $calendar,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $month = null,
        #[MapQueryParameter] ?string $day = null,
    ): Response {
        $month = FinanceStats::resolveMonth($month);

        return $this->render('calendar/index.html.twig', [
            'currency' => $calendar->getCurrency(),
            'calendar' => $calendar->build($user, $month, $calendar->resolveSelectedDay($month, $day)),
            'month' => $month,
        ]);
    }
}
