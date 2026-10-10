<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Category of a commune in INSEE's "aires d'attraction des villes 2020"
 * zoning (variable CATEAAV2020). An unité urbaine carries its ville-centre's
 * category, see docs/urban-unit-attraction.md.
 */
enum AttractionCategory: string
{
    case CommuneCentre = '11';
    case AutreCommuneDuPolePrincipal = '12';
    case PoleSecondaire = '13';
    case Couronne = '20';
    case HorsAttraction = '30';

    public function label(): string
    {
        return match ($this) {
            self::CommuneCentre => 'Commune-centre',
            self::AutreCommuneDuPolePrincipal => 'Autre commune du pôle principal',
            self::PoleSecondaire => 'Pôle secondaire',
            self::Couronne => 'Couronne',
            self::HorsAttraction => 'Hors attraction des villes',
        };
    }
}
