<?php

namespace App\Twig;

use App\Enum\AccountType;
use App\Enum\Icon;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class UiExtension
{
    private const CURRENCY_SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'JPY' => '¥', 'CAD' => 'CA$'];

    /** Tracés SVG 24x24 (trait, sans remplissage). */
    private const ICONS = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h12v4"/><path d="M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2"/><circle cx="16.5" cy="14.5" r="1"/>',
        'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
        'basket' => '<path d="M3 9h18l-1.6 9.2A2 2 0 0 1 17.4 20H6.6a2 2 0 0 1-2-1.8L3 9Z"/><path d="m8 9 3-5m5 5-3-5M9 13v3m6-3v3"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>',
        'chart' => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.2M12 19.3v2.2M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.9 19.1l1.6-1.6M17.5 6.5l1.6-1.6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'filter' => '<path d="M4 7h16M7 12h10M10 17h4"/>',
        'cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h3l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.1L20.5 8H6.2"/>',
        'car' => '<path d="M5 16v-3.5l2-5A2 2 0 0 1 8.9 6.2h6.2A2 2 0 0 1 17 7.5l2 5V16"/><rect x="3.5" y="12.5" width="17" height="5.5" rx="1.5"/><circle cx="7.5" cy="15.2" r=".8"/><circle cx="16.5" cy="15.2" r=".8"/>',
        'wifi' => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9.5 9.5 0 0 1 13 0M8.6 16a5 5 0 0 1 6.8 0"/><circle cx="12" cy="19.3" r=".9"/>',
        'gamepad' => '<rect x="2.5" y="7" width="19" height="10.5" rx="5"/><path d="M7 10.5V14M5.25 12.25h3.5"/><circle cx="15.5" cy="11.3" r=".8"/><circle cx="18" cy="13.3" r=".8"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.5 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10Z"/>',
        'bag' => '<path d="M5 8h14l-1 12H6L5 8Z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'utensils' => '<path d="M7 3v8M4.5 3v5a2.5 2.5 0 0 0 5 0V3M7 11v10M17 21V3c-2.2 1.2-3.5 3.7-3.5 6.5V14H17"/>',
        'bolt' => '<path d="M13 2.5 5 13.5h6l-1 8 8-11h-6l1-8Z"/>',
        'gift' => '<rect x="3.5" y="8" width="17" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8S10.5 4 8.5 4a2 2 0 0 0 0 4H12Zm0 0s1.5-4 3.5-4a2 2 0 0 1 0 4H12Z"/>',
        'briefcase' => '<rect x="3" y="7.5" width="18" height="12.5" rx="2"/><path d="M9 7.5v-2A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5v2M3 13h18"/>',
        'plane' => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
        'piggy' => '<path d="M19 11.5c0-3.6-3.1-6.5-7-6.5s-7 2.9-7 6.5c0 2 1 3.7 2.5 4.9V19h3v-1.2c.5.1 1 .2 1.5.2s1-.1 1.5-.2V19h3v-2.6c1.5-1.2 2.5-2.9 2.5-4.9Z"/><path d="M19 10.5h2v3h-1.5M9.5 8.5h3"/><circle cx="15.5" cy="10.5" r=".6"/>',
        'cash' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.7"/><path d="M6 9.5v.01M18 14.5v.01"/>',
        'laptop' => '<rect x="5" y="5" width="14" height="10" rx="1.5"/><path d="M2.5 19h19L20 15H4l-1.5 4Z"/>',
        'umbrella' => '<path d="M3 12a9 9 0 0 1 18 0H3Z"/><path d="M12 12v6.5a2 2 0 0 0 4 0"/>',
        'tag' => '<path d="M3 12.5V4h8.5L21 13.5 13.5 21 3 12.5Z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
        'chevron-right' => '<path d="m9 5 7 7-7 7"/>',
        'chevron-left' => '<path d="m15 5-7 7 7 7"/>',
        'chevron-down' => '<path d="m5 9 7 7 7-7"/>',
        'arrow-up' => '<path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/>',
        'arrow-down' => '<path d="M12 5v14M5.5 12.5 12 19l6.5-6.5"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-8M15 7h6v6"/>',
        'eye' => '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="2.8"/>',
        'bell' => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15L6 16.5ZM10 21h4"/>',
    ];

    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string|Icon|AccountType|null $name, int $size = 20): string
    {
        if ($name instanceof AccountType) {
            $name = $name->icon();
        }
        $key = $name instanceof Icon ? $name->value : ($name ?? 'tag');
        $paths = self::ICONS[$key] ?? self::ICONS['tag'];

        return sprintf(
            '<svg class="icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
            $size,
            $paths,
        );
    }

    /** « 8 450,00 € » — espace fine insécable entre les milliers. */
    #[AsTwigFilter('money')]
    public function money(string|float|int|null $amount, string $currency = 'EUR', bool $signed = false): string
    {
        $value = (float) $amount;
        $text = number_format(abs($value), 2, ',', "\u{202F}");
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? $currency;
        $sign = '';
        if ($value < 0) {
            $sign = "\u{2212}";
        } elseif ($signed && $value > 0) {
            $sign = '+';
        }

        return sprintf("%s%s\u{00A0}%s", $sign, $text, $symbol);
    }

    /** « Novembre 2026 ». */
    #[AsTwigFilter('month_label')]
    public function monthLabel(\DateTimeInterface $date): string
    {
        return mb_convert_case($this->format($date, 'MMMM y'), MB_CASE_TITLE);
    }

    /** « 18 nov. ». */
    #[AsTwigFilter('short_date')]
    public function shortDate(\DateTimeInterface $date): string
    {
        return $this->format($date, 'd MMM');
    }

    private function format(\DateTimeInterface $date, string $pattern): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone()->getName(), \IntlDateFormatter::GREGORIAN, $pattern);

        return (string) $formatter->format($date);
    }
}
