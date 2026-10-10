<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The 13 régions of France métropolitaine (INSEE code). An enum rather than a table: the
 * set only changes with a territorial reform, and a code change is cheaper than a
 * migration. Reached from a commune/unité urbaine through {@see Departement::region()}.
 */
enum Region: string
{
    case AuvergneRhoneAlpes = '84';
    case BourgogneFrancheComte = '27';
    case Bretagne = '53';
    case CentreValDeLoire = '24';
    case Corse = '94';
    case GrandEst = '44';
    case HautsDeFrance = '32';
    case IleDeFrance = '11';
    case Normandie = '28';
    case NouvelleAquitaine = '75';
    case Occitanie = '76';
    case PaysDeLaLoire = '52';
    case ProvenceAlpesCoteDAzur = '93';

    public function label(): string
    {
        return match ($this) {
            self::AuvergneRhoneAlpes => 'Auvergne-Rhône-Alpes',
            self::BourgogneFrancheComte => 'Bourgogne-Franche-Comté',
            self::Bretagne => 'Bretagne',
            self::CentreValDeLoire => 'Centre-Val de Loire',
            self::Corse => 'Corse',
            self::GrandEst => 'Grand Est',
            self::HautsDeFrance => 'Hauts-de-France',
            self::IleDeFrance => 'Île-de-France',
            self::Normandie => 'Normandie',
            self::NouvelleAquitaine => 'Nouvelle-Aquitaine',
            self::Occitanie => 'Occitanie',
            self::PaysDeLaLoire => 'Pays de la Loire',
            self::ProvenceAlpesCoteDAzur => 'Provence-Alpes-Côte d\'Azur',
        };
    }

    /** @return list<Departement> */
    public function departements(): array
    {
        return array_values(array_filter(
            Departement::cases(),
            fn (Departement $departement): bool => $this === $departement->region(),
        ));
    }
}
