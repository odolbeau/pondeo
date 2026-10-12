<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Cities stations are compared against by train. Adding a city is adding a
 * case here, then re-running the journeys import: no schema change.
 */
enum JourneyDestination: string
{
    case Nantes = 'nantes';
    case Paris = 'paris';
    case Lyon = 'lyon';

    /** Identifier of the destination in the SNCF API. */
    public function navitiaId(): string
    {
        return match ($this) {
            // The Nantes station only, not Pirmil or Chantenay.
            self::Nantes => 'stop_area:SNCF:87481002',
            // The whole city: any Paris station.
            self::Paris => 'admin:fr:75056',
            // The whole city: Part-Dieu, Perrache, Vaise...
            self::Lyon => 'admin:fr:69123',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nantes => 'Nantes',
            self::Paris => 'Paris',
            self::Lyon => 'Lyon',
        };
    }

    /**
     * Whether a station is in the destination itself, so has no journey to
     * it. Paris and Lyon are whole cities; Nantes only its main station, the others
     * (Pirmil, Chantenay...) are genuine origins.
     *
     * @param string $uicCodes the station's UIC codes, ";"-separated
     */
    public function isInside(string $uicCodes, string $inseeCommuneCode): bool
    {
        return match ($this) {
            self::Nantes => \in_array('87481002', explode(';', $uicCodes), true),
            self::Paris => str_starts_with($inseeCommuneCode, '75'),
            // The 9 arrondissements, 69381 to 69389.
            self::Lyon => str_starts_with($inseeCommuneCode, '6938'),
        };
    }
}
