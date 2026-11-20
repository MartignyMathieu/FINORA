<?php

namespace App\Enum;

/**
 * Palettes proposées pour la couleur d'une catégorie (graphiques, pastilles). Chaque couleur garde son code hexadécimal
 * en base (aucune migration) ; l'utilisateur choisit un nom de couleur, jamais un code.
 */
final class CategoryColors
{
    /** @var array<string, array<string, string>> palette => [nom de couleur => code hexadécimal] */
    public const PALETTES = [
        'Vives' => [
            'Bleu' => '#1d6cf0',
            'Cyan' => '#0ea5e9',
            'Vert' => '#22c55e',
            'Jaune' => '#facc15',
            'Orange' => '#f97316',
            'Rouge' => '#ef4444',
            'Rose' => '#ec4899',
            'Violet' => '#8b5cf6',
        ],
        'Douces' => [
            'Ciel' => '#7dbcff',
            'Menthe' => '#86e3ce',
            'Citron' => '#fde68a',
            'Pêche' => '#fdba74',
            'Corail' => '#fca5a5',
            'Rose pâle' => '#f9a8d4',
            'Lavande' => '#c4b5fd',
            'Gris' => '#94a3b8',
        ],
        'Profondes' => [
            'Marine' => '#1e3a8a',
            'Pétrole' => '#0f766e',
            'Forêt' => '#166534',
            'Ocre' => '#a16207',
            'Brique' => '#9a3412',
            'Bordeaux' => '#9f1239',
            'Prune' => '#6b21a8',
            'Ardoise' => '#334155',
        ],
    ];

    /** Palette d'une couleur (null si elle n'appartient à aucune). */
    public static function paletteOf(?string $hex): ?string
    {
        foreach (self::PALETTES as $palette => $colors) {
            if (\in_array(strtolower((string) $hex), $colors, true)) {
                return $palette;
            }
        }

        return null;
    }
}
