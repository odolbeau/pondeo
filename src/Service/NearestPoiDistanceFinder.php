<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PoiCategory;
use App\Enum\ZoneType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Pattern B: "zones whose distance to the nearest POI of category X is
 * greater than N km" — distance is measured zone centroid → POI point
 * (see plan §2.10 for why: a boundary→point distance would be near-zero
 * for any zone whose territory reaches close to the POI).
 *
 * The nearest-neighbor lookup uses Postgres' native KNN `<->` operator,
 * which isn't expressible in DQL, so this runs as native SQL rather than
 * through the ORM (see plan §5). $zoneType selects the target table from a
 * fixed allowlist — never interpolated from external input.
 */
final readonly class NearestPoiDistanceFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Distance in meters from the zone's centroid to the nearest POI of
     * the given category, or null if that category has no POI at all.
     */
    public function distanceToNearestPoi(ZoneType $zoneType, Uuid $zoneId, PoiCategory $category): ?float
    {
        $table = $zoneType->tableName();

        $result = $this->connection->fetchOne(
            <<<SQL
                SELECT ST_Distance(zone.centroid, nearest_poi.location) AS distance
                FROM {$table} AS zone
                CROSS JOIN LATERAL (
                    SELECT location
                    FROM poi
                    WHERE category_id = :categoryId
                    ORDER BY location <-> zone.centroid
                    LIMIT 1
                ) AS nearest_poi
                WHERE zone.id = :zoneId
                SQL,
            [
                'categoryId' => $category->getId()->toRfc4122(),
                'zoneId' => $zoneId->toRfc4122(),
            ],
        );

        if (false === $result || null === $result) {
            return null;
        }

        \assert(is_numeric($result));

        return (float) $result;
    }
}
