<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Discriminates the four administrative zone types supported in v1.
 *
 * Used wherever a generic reference to "a zone, whatever its type" is
 * needed (Criterion::zoneType, ZoneCriterionValue::zoneType), since zones
 * themselves are stored as discrete entities rather than a single
 * polymorphic one.
 */
enum ZoneType: string
{
    case Commune = 'commune';
    case Epci = 'epci';
    case Departement = 'departement';
    case Region = 'region';
}
