<?php

namespace App\Twig;

use App\Enum\AccountType;
use App\Enum\Icon;
use Symfony\UX\Icons\IconRendererInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class UiExtension
{
    private const CURRENCY_SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'JPY' => '¥', 'CAD' => 'CA$'];

    /**
     * Clé fonctionnelle => icône Phosphor (importée dans assets/icons/ph/ avec `ux:icons:import`).
     * Duotone pour les pictogrammes, bold pour les symboles d'interface (flèches, plus…).
     */
    private const ICONS = [
        'home' => 'ph:house-duotone',
        'wallet' => 'ph:wallet-duotone',
        'card' => 'ph:credit-card-duotone',
        'basket' => 'ph:basket-duotone',
        'target' => 'ph:target-duotone',
        'chart' => 'ph:chart-bar-duotone',
        'calendar' => 'ph:calendar-blank-duotone',
        'gear' => 'ph:gear-six-duotone',
        'search' => 'ph:magnifying-glass-duotone',
        'filter' => 'ph:funnel-duotone',
        'cart' => 'ph:shopping-cart-duotone',
        'car' => 'ph:car-duotone',
        'wifi' => 'ph:wifi-high-duotone',
        'gamepad' => 'ph:game-controller-duotone',
        'heart' => 'ph:heart-duotone',
        'bag' => 'ph:shopping-bag-duotone',
        'utensils' => 'ph:fork-knife-duotone',
        'bolt' => 'ph:lightning-duotone',
        'gift' => 'ph:gift-duotone',
        'briefcase' => 'ph:briefcase-duotone',
        'plane' => 'ph:airplane-tilt-duotone',
        'piggy' => 'ph:piggy-bank-duotone',
        'cash' => 'ph:money-duotone',
        'laptop' => 'ph:laptop-duotone',
        'umbrella' => 'ph:umbrella-simple-duotone',
        'tag' => 'ph:tag-duotone',
        'eye' => 'ph:eye-duotone',
        'bell' => 'ph:bell-duotone',
        'logout' => 'ph:sign-out-duotone',
        'wave' => 'ph:hand-waving-duotone',
        'check' => 'ph:check-circle-duotone',
        'download' => 'ph:download-simple-duotone',
        'sun' => 'ph:sun-duotone',
        'moon' => 'ph:moon-duotone',
        'auto' => 'ph:circle-half-duotone',
        'repeat' => 'ph:arrows-clockwise-duotone',
        'pause' => 'ph:pause-duotone',
        'play' => 'ph:play-duotone',
        'edit' => 'ph:pencil-simple-duotone',
        'plus' => 'ph:plus-bold',
        'chevron-right' => 'ph:caret-right-bold',
        'chevron-left' => 'ph:caret-left-bold',
        'chevron-down' => 'ph:caret-down-bold',
        'arrow-up' => 'ph:arrow-up-bold',
        'arrow-down' => 'ph:arrow-down-bold',
        'trend' => 'ph:trend-up-bold',
    ];

    public function __construct(private readonly IconRendererInterface $iconRenderer)
    {
    }

    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string|Icon|AccountType|null $name, int $size = 20): string
    {
        if ($name instanceof AccountType) {
            $name = $name->icon();
        }
        $key = $name instanceof Icon ? $name->value : ($name ?? 'tag');

        return $this->iconRenderer->renderIcon(self::ICONS[$key] ?? self::ICONS['tag'], [
            'class' => 'icon',
            'width' => $size,
            'height' => $size,
            'aria-hidden' => 'true',
        ]);
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

    /** « 1 250 € » : montant arrondi à l'euro, pour les espaces réduits (cases du calendrier). */
    #[AsTwigFilter('money_short')]
    public function moneyShort(string|float|int|null $amount, string $currency = 'EUR', bool $signed = false): string
    {
        $value = round((float) $amount);
        $text = number_format(abs($value), 0, ',', "\u{202F}");
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? $currency;
        $sign = $value < 0 ? "\u{2212}" : ($signed && $value > 0 ? '+' : '');

        return sprintf("%s%s\u{00A0}%s", $sign, $text, $symbol);
    }

    /** « Jeudi 19 novembre 2026 ». */
    #[AsTwigFilter('long_date')]
    public function longDate(\DateTimeInterface $date): string
    {
        return mb_strtoupper(mb_substr($text = $this->format($date, 'EEEE d MMMM y'), 0, 1)).mb_substr($text, 1);
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
