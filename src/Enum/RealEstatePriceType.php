<?php

declare(strict_types=1);

namespace App\Enum;

/** Which price per m² the map's price filter looks at: apartments and houses together, or one of them. */
enum RealEstatePriceType: string
{
    case Global = 'global';
    case Apartment = 'apartment';
    case House = 'house';

    public function criterionCode(): string
    {
        return match ($this) {
            self::Global => 'prix_m2',
            self::Apartment => 'prix_m2_appartement',
            self::House => 'prix_m2_maison',
        };
    }
}
