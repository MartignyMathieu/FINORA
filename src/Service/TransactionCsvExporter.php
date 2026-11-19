<?php

namespace App\Service;

use App\Dto\TransactionExportQuery;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType;
use App\Repository\TransactionRepository;

/**
 * Écrit les transactions d'un utilisateur au format CSV.
 *
 * - UTF-8 avec BOM (Excel reconnaît ainsi les accents), fins de ligne CRLF (RFC 4180) ;
 * - format « fr » (défaut) : séparateur « ; » et virgule décimale, pour Excel en français ; format « intl » : « , » et « . » ;
 * - montants signés (dépenses négatives) pour pouvoir les additionner dans un tableur ;
 * - protection contre l'injection de formules : un texte qui commencerait par = + - @ (ou un caractère de
 *   contrôle) serait exécuté comme une formule par Excel ou LibreOffice ; on le préfixe d'une apostrophe.
 */
final class TransactionCsvExporter
{
    public const HEADER = ['Date', 'Libellé', 'Type', 'Montant', 'Devise', 'Compte', 'Catégorie', 'Note', 'Origine'];

    public function __construct(private readonly TransactionRepository $transactions)
    {
    }

    /**
     * @param resource $out
     *
     * @return int Nombre de transactions écrites
     */
    public function write($out, User $user, TransactionExportQuery $query): int
    {
        [$delimiter, $decimalSeparator] = 'intl' === $query->format ? [',', '.'] : [';', ','];
        $eol = "\r\n";

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, $delimiter, '"', '', $eol);

        $count = 0;
        foreach ($this->transactions->iterateForExport($user, $query->transactionType(), trim($query->q), $query->fromDate(), $query->toDate()) as $transaction) {
            fputcsv($out, $this->row($transaction, $decimalSeparator), $delimiter, '"', '', $eol);
            ++$count;
        }

        return $count;
    }

    /** @return list<string> */
    private function row(Transaction $transaction, string $decimalSeparator): array
    {
        return [
            $transaction->getDate()->format('Y-m-d'),
            self::safe($transaction->getLabel()),
            $transaction->getType()->label(),
            $this->signedAmount($transaction, $decimalSeparator),
            (string) $transaction->getAccount()->getCurrency(),
            self::safe($transaction->getAccount()->getName()),
            self::safe($transaction->getCategory()->getName()),
            self::safe($transaction->getNote()),
            null === $transaction->getRecurringTransaction() ? 'Manuelle' : 'Récurrente',
        ];
    }

    /** Montant signé, formaté sans passer par un flottant : « -42,50 » ou « 2500.00 ». */
    private function signedAmount(Transaction $transaction, string $decimalSeparator): string
    {
        $amount = (string) $transaction->getAmount();
        $sign = TransactionType::Expense === $transaction->getType() ? '-' : '';

        return $sign.str_replace('.', $decimalSeparator, $amount);
    }

    /** Neutralise l'injection de formules dans une cellule de texte. */
    public static function safe(?string $value): string
    {
        $value = (string) $value;

        return 1 === preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
    }
}
