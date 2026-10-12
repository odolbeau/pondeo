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
    case Marseille = 'marseille';
    case Bordeaux = 'bordeaux';
    case Toulouse = 'toulouse';
    case Lille = 'lille';
    case Rennes = 'rennes';
    case Strasbourg = 'strasbourg';
    case Montpellier = 'montpellier';

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
            // The Saint-Charles station only, not Blancarde.
            self::Marseille => 'stop_area:SNCF:87751008',
            // The Saint-Jean station only.
            self::Bordeaux => 'stop_area:SNCF:87581009',
            // The Matabiau station only.
            self::Toulouse => 'stop_area:SNCF:87611004',
            // The whole city: Flandres and Europe.
            self::Lille => 'admin:fr:59350',
            // The Rennes station.
            self::Rennes => 'stop_area:SNCF:87471003',
            // The Strasbourg station.
            self::Strasbourg => 'stop_area:SNCF:87212027',
            // The whole city: Saint-Roch and Sud de France.
            self::Montpellier => 'admin:fr:34172',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nantes => 'Nantes',
            self::Paris => 'Paris',
            self::Lyon => 'Lyon',
            self::Marseille => 'Marseille',
            self::Bordeaux => 'Bordeaux',
            self::Toulouse => 'Toulouse',
            self::Lille => 'Lille',
            self::Rennes => 'Rennes',
            self::Strasbourg => 'Strasbourg',
            self::Montpellier => 'Montpellier',
        };
    }

    /**
     * Whether a station is in the destination itself, so has no journey to
     * it. Paris, Lyon, Lille and Montpellier are whole cities; the others are a
     * single station, the city's other stations (Pirmil, Blancarde...) being genuine
     * origins.
     *
     * @param string $uicCodes the station's UIC codes, ";"-separated
     */
    public function isInside(string $uicCodes, string $inseeCommuneCode): bool
    {
        return match ($this) {
            self::Nantes => \in_array('87481002', explode(';', $uicCodes), true),
            self::Marseille => \in_array('87751008', explode(';', $uicCodes), true),
            self::Bordeaux => \in_array('87581009', explode(';', $uicCodes), true),
            self::Toulouse => \in_array('87611004', explode(';', $uicCodes), true),
            self::Rennes => \in_array('87471003', explode(';', $uicCodes), true),
            self::Strasbourg => \in_array('87212027', explode(';', $uicCodes), true),
            self::Paris => str_starts_with($inseeCommuneCode, '75'),
            // The 9 arrondissements, 69381 to 69389.
            self::Lyon => str_starts_with($inseeCommuneCode, '6938'),
            self::Lille => '59350' === $inseeCommuneCode,
            self::Montpellier => '34172' === $inseeCommuneCode,
        };
    }
}
