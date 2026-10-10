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

    /** Identifier of the destination in the SNCF API. */
    public function navitiaId(): string
    {
        return match ($this) {
            // The Nantes station only, not Pirmil or Chantenay.
            self::Nantes => 'stop_area:SNCF:87481002',
            // The whole city: any Paris station.
            self::Paris => 'admin:fr:75056',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nantes => 'Nantes',
            self::Paris => 'Paris',
        };
    }
}
