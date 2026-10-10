<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PoiCategory;
use App\Entity\UniteUrbaine;
use Doctrine\DBAL\Connection;

/**
 * Pattern B: "unités urbaines whose distance to the nearest POI of category
 * X is greater than N km" — distance is measured from the unité urbaine's
 * centroid to the POI point (a boundary→point distance would be near-zero
 * for any unité urbaine whose territory reaches close to the POI).
 *
 * The nearest-neighbor lookup uses Postgres' native KNN `<->` operator,
 * which isn't expressible in DQL, so this runs as native SQL rather than
 * through the ORM.
 */
final readonly class NearestPoiDistanceFinder
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Distance in meters from the unité urbaine's centroid to the nearest
     * POI of the given category, or null if that category has no POI at all.
     */
    public function distanceToNearestPoi(UniteUrbaine $uniteUrbaine, PoiCategory $category): ?float
    {
        $result = $this->connection->fetchOne(
            <<<'SQL'
                SELECT ST_Distance(uu.centroid, nearest_poi.location) AS distance
                FROM unite_urbaine AS uu
                CROSS JOIN LATERAL (
                    SELECT location
                    FROM poi
                    WHERE category_id = :categoryId
                    ORDER BY location <-> uu.centroid
                    LIMIT 1
                ) AS nearest_poi
                WHERE uu.id = :uniteUrbaineId
                SQL,
            [
                'categoryId' => $category->getId()->toRfc4122(),
                'uniteUrbaineId' => $uniteUrbaine->getId()->toRfc4122(),
            ],
        );

        if (false === $result || null === $result) {
            return null;
        }

        \assert(is_numeric($result));

        return (float) $result;
    }
}
