<?php

namespace App\Command;

use App\Service\RecurringTransactionGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Exécutée chaque nuit par le Scheduler (worker `messenger:consume scheduler_default`). */
#[AsCommand(name: 'app:recurring:generate', description: 'Génère les transactions des règles récurrentes arrivées à échéance')]
#[AsCronTask('0 3 * * *')]
final class GenerateRecurringTransactionsCommand
{
    public function __construct(private readonly RecurringTransactionGenerator $generator)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Date de référence au format AAAA-MM-JJ (aujourd\'hui par défaut)')]
        ?string $date = null,
    ): int {
        $today = null;
        if (null !== $date) {
            $today = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $today || $today->format('Y-m-d') !== $date) {
                $io->error('La date doit être au format AAAA-MM-JJ.');

                return Command::INVALID;
            }
        }

        $created = $this->generator->generateDue($today);
        $io->success(sprintf('%d transaction(s) générée(s).', $created));

        return Command::SUCCESS;
    }
}
