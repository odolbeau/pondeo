<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ZoneType;
use Symfony\Component\Uid\Uuid;

/**
 * Common read contract implemented by all administrative zone entities
 * (Region, Departement, Epci, Commune). This is a plain PHP interface, not
 * a Doctrine inheritance mapping: there are only 4 known zone types and
 * they don't share storage, so ORM single/joined-table inheritance would
 * add complexity for no benefit. It exists purely so generic code (the
 * comparison UI, generic repositories) can operate on "any zone" without
 * knowing which concrete entity it is.
 */
interface ZoneInterface
{
    public function getId(): Uuid;

    public function getCode(): string;

    public function getName(): string;

    /**
     * EWKT representation of the zone's boundary (e.g.
     * "SRID=4326;MULTIPOLYGON(...)"), as returned by the jsor/doctrine-postgis
     * `geometry` DBAL type — this library has no dedicated PHP value object,
     * conversion to/from PostGIS happens in SQL (ST_AsEWKT/ST_GeomFromEWKT).
     */
    public function getBoundary(): ?string;

    public function getType(): ZoneType;
}
