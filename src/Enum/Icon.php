<?php

namespace App\Enum;

/** Icônes sélectionnables pour les catégories et les objectifs. */
enum Icon: string
{
    case Cart = 'cart';
    case Basket = 'basket';
    case Home = 'home';
    case Car = 'car';
    case Wifi = 'wifi';
    case Gamepad = 'gamepad';
    case Heart = 'heart';
    case Bag = 'bag';
    case Utensils = 'utensils';
    case Bolt = 'bolt';
    case Gift = 'gift';
    case Briefcase = 'briefcase';
    case Plane = 'plane';
    case Piggy = 'piggy';
    case Cash = 'cash';
    case Card = 'card';
    case Laptop = 'laptop';
    case Umbrella = 'umbrella';
    case Target = 'target';
    case Tag = 'tag';

    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Caddie',
            self::Basket => 'Panier',
            self::Home => 'Maison',
            self::Car => 'Voiture',
            self::Wifi => 'Internet',
            self::Gamepad => 'Loisirs',
            self::Heart => 'Santé',
            self::Bag => 'Shopping',
            self::Utensils => 'Restaurant',
            self::Bolt => 'Énergie',
            self::Gift => 'Cadeau',
            self::Briefcase => 'Travail',
            self::Plane => 'Voyage',
            self::Piggy => 'Épargne',
            self::Cash => 'Espèces',
            self::Card => 'Carte',
            self::Laptop => 'Informatique',
            self::Umbrella => 'Vacances',
            self::Target => 'Objectif',
            self::Tag => 'Autre',
        };
    }
}
