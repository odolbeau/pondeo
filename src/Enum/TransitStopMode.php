<?php

declare(strict_types=1);

namespace App\Enum;

/** The kinds of urban rail transit stops; the value is the `mode` column of the vendored file. */
enum TransitStopMode: string
{
    case Tram = 'tram';
    case Metro = 'metro';

    /** The `nature` of this mode's stops in the IGN BD TOPO `equipement_de_transport` layer. */
    public function sourceNature(): string
    {
        return match ($this) {
            self::Tram => 'Station de tramway',
            self::Metro => 'Station de métro',
        };
    }

    /** Code of the POI category of this mode. */
    public function categoryCode(): string
    {
        return match ($this) {
            self::Tram => 'tram_stop',
            self::Metro => 'metro_station',
        };
    }

    public function label(): string
    {
        return $this->sourceNature();
    }
}
