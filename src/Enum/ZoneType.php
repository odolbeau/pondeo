<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\Commune;
use App\Entity\Departement;
use App\Entity\Epci;
use App\Entity\Region;
use App\Entity\ZoneInterface;

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

    /** The zone's SQL table — equal to $this->value by construction. */
    public function tableName(): string
    {
        return $this->value;
    }

    /** The column referencing this zone type on another table, e.g. `zone_criterion_value`. */
    public function foreignKeyColumn(): string
    {
        return $this->value.'_id';
    }

    /** @return class-string<ZoneInterface> */
    public function entityClass(): string
    {
        return match ($this) {
            self::Commune => Commune::class,
            self::Epci => Epci::class,
            self::Departement => Departement::class,
            self::Region => Region::class,
        };
    }
}
