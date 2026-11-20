<?php

namespace App\Service;

use App\Enum\TransactionType;

/**
 * Lecture tolérante des valeurs d'un fichier CSV bancaire ou d'un export FINORA : dates, montants, types.
 * Chaque méthode renvoie null quand la valeur n'est pas reconnue (la ligne est alors signalée, jamais devinée).
 */
final class CsvValueParser
{
    private const DATE_FORMATS = ['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!d/m/y', '!d-m-y', '!Y/m/d', '!Ymd'];

    /** Date au format AAAA-MM-JJ, JJ/MM/AAAA, JJ-MM-AAAA, JJ.MM.AAAA (avec heure éventuelle, ignorée). */
    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        // Une heure après la date (« 2026-11-20 14:30:00 » ou « 20/11/2026 14:30 ») est ignorée.
        $value = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', '', $value) ?? $value;

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))) {
                $year = (int) $date->format('Y');
                if ($year >= 1970 && $year <= 2100) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Montant signé au format décimal « -1234.56 » : accepte « 1 234,56 », « 1,234.56 », « 1.234,56 », « -42,5 », « 42.50 € »,
     * « (42,50) » (négatif) et « +12 ». Null si la valeur n'est pas un nombre sans ambiguïté.
     */
    public static function parseAmount(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        // Seuls les symboles et codes de devise courants sont tolérés autour du nombre : « 12 abc » n'est pas un montant.
        if (1 === preg_match('/\p{L}/u', preg_replace('/(EUR|USD|GBP|CHF|CAD|JPY|€|\$|£)/iu', '', $value) ?? $value)) {
            return null;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        // Espaces (dont insécables), symboles monétaires et lettres de devise disparaissent ; seuls chiffres, signes et séparateurs restent.
        $clean = preg_replace('/[^\d,.\-+]/u', '', str_replace(["\u{00A0}", "\u{202F}", ' '], '', $value)) ?? '';
        if ('' === $clean || 1 !== preg_match('/\d/', $clean)) {
            return null;
        }

        // Un signe n'est valable qu'en tête.
        $sign = '';
        if (str_starts_with($clean, '-') || str_starts_with($clean, '+')) {
            $sign = $clean[0];
            $clean = substr($clean, 1);
        }
        if (str_contains($clean, '-') || str_contains($clean, '+')) {
            return null;
        }

        $comma = substr_count($clean, ',');
        $dot = substr_count($clean, '.');
        if ($comma > 0 && $dot > 0) {
            // Le dernier séparateur est la virgule décimale ; l'autre sépare les milliers.
            $decimal = strrpos($clean, ',') > strrpos($clean, '.') ? ',' : '.';
            $thousands = ',' === $decimal ? '.' : ',';
            $clean = str_replace($thousands, '', $clean);
            $clean = str_replace($decimal, '.', $clean);
        } elseif ($comma > 0 || $dot > 0) {
            $separator = $comma > 0 ? ',' : '.';
            $count = max($comma, $dot);
            $parts = explode($separator, $clean);
            $last = end($parts);
            if ($count > 1) {
                // Plusieurs séparateurs identiques : ce sont des milliers (« 1.234.567 ») à condition que chaque groupe ait 3 chiffres.
                if (1 !== preg_match('/^\d{1,3}(\\'.$separator.'\d{3})+$/', $clean)) {
                    return null;
                }
                $clean = str_replace($separator, '', $clean);
            } elseif (1 === \strlen($last) || 2 === \strlen($last)) {
                $clean = str_replace($separator, '.', $clean);
            } elseif (3 === \strlen($last) && ',' === $separator) {
                // « 1,234 » : milliers à l'anglaise.
                $clean = str_replace(',', '', $clean);
            } elseif (3 === \strlen($last)) {
                // « 1.234 » : ambigu, on le lit comme des milliers.
                $clean = str_replace('.', '', $clean);
            } else {
                return null;
            }
        }

        if (1 !== preg_match('/^\d{1,10}(\.\d{1,2})?$/', $clean)) {
            return null;
        }

        $number = (float) $clean;
        $isNegative = '-' === $sign || $negative;

        return ($isNegative && $number > 0 ? '-' : '').number_format($number, 2, '.', '');
    }

    /** Type d'une transaction d'après un libellé de colonne « Type » (français ou anglais). */
    public static function parseType(string $value): ?TransactionType
    {
        $value = mb_strtolower(trim($value));
        if ('' === $value) {
            return null;
        }

        foreach (['revenu', 'income', 'crédit', 'credit', 'entrée', 'entree', 'recette', 'gain', 'cr'] as $word) {
            if ($value === $word || str_starts_with($value, $word)) {
                return TransactionType::Income;
            }
        }
        foreach (['dépense', 'depense', 'expense', 'débit', 'debit', 'sortie', 'achat', 'paiement', 'db'] as $word) {
            if ($value === $word || str_starts_with($value, $word)) {
                return TransactionType::Expense;
            }
        }

        return null;
    }

    /** Texte de cellule nettoyé : sans caractères de contrôle, espaces superflus retirés, longueur bornée. */
    public static function text(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }
}
