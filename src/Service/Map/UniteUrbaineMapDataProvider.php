<?php

declare(strict_types=1);

namespace App\Service\Map;

use App\Enum\AttractionCategory;
use App\Repository\CriterionRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-SQL queries backing the unité urbaine map (Doctrine can't express the
 * boundary simplification cleanly). findFeatures()'s params are the extension
 * point for more filters, so the data endpoint doesn't need a new controller
 * per criterion.
 */
final readonly class UniteUrbaineMapDataProvider
{
    public function __construct(
        private Connection $connection,
        private CriterionRepository $criterionRepository,
    ) {
    }

    public function getLatestPeriod(): int
    {
        $criterionId = $this->populationCriterionId();

        $period = $this->connection->fetchOne(
            'SELECT MAX(period) FROM criterion_value WHERE criterion_id = :criterionId',
            ['criterionId' => $criterionId],
        );

        if (false === $period || null === $period) {
            throw new \LogicException('No population data found.');
        }

        return self::toInt($period);
    }

    /** @return array{min: int, max: int} */
    public function getPopulationBounds(int $period): array
    {
        $criterionId = $this->populationCriterionId();

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT MIN(value_integer) AS min, MAX(value_integer) AS max
                FROM criterion_value
                WHERE criterion_id = :criterionId AND period = :period
                SQL,
            ['criterionId' => $criterionId, 'period' => $period],
        );

        if (false === $row || null === $row['min']) {
            throw new \LogicException("No population data found at period {$period}.");
        }

        return ['min' => self::toInt($row['min']), 'max' => self::toInt($row['max'])];
    }

    /**
     * geometryJson is kept as a raw GeoJSON string, not decoded to a PHP
     * array — decoding thousands of large coordinate arrays into PHP
     * values (and re-encoding them) is what blows the memory limit, not the
     * JSON payload size itself. toFeatureCollectionJson() splices it
     * straight into the response body instead.
     *
     * A unité urbaine with no attraction category is never excluded: unknown
     * is not the same as satellite. Likewise, with a commerce density
     * minimum, an unité urbaine without a density value is left out: it
     * cannot be said to reach it. With a nuclear exclusion radius, an unité urbaine whose
     * centroid is within it of a plant is left out. A price per m² maximum
     * leaves out an unité urbaine without a price (Alsace-Moselle is not in the source) for the same reason as the density minimum.
     * With `withStation`, only the unités urbaine containing a railway station are kept.
     *
     * @param list<AttractionCategory> $excludedAttractionCategories
     *
     * @return list<array{id: string, code: string, name: string, population: int, geometryJson: string}>
     */
    public function findFeatures(int $period, int $populationMin, int $populationMax, array $excludedAttractionCategories = [], ?float $commerceDensityMin = null, ?float $nuclearExclusionKm = null, ?float $priceMax = null, bool $withStation = false): array
    {
        $criterionId = $this->populationCriterionId();
        $excludedCategoryValues = array_map(static fn (AttractionCategory $category): string => $category->value, $excludedAttractionCategories);

        // Simplified: at full resolution, the boundaries of thousands of
        // unités urbaines together are too large to decode/transfer for an
        // unfiltered map. 0.001° (~100m) is imperceptible at the zoom
        // levels this map is used at.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT uu.id AS id, uu.insee_code AS code, uu.name AS name,
                       cv.value_integer AS population,
                       ST_AsGeoJSON(ST_SimplifyPreserveTopology(uu.boundary, 0.001)) AS geometry
                FROM unite_urbaine uu
                INNER JOIN criterion_value cv ON cv.unite_urbaine_id = uu.id
                WHERE cv.criterion_id = :criterionId
                  AND cv.period = :period
                  AND cv.value_integer BETWEEN :min AND :max
                  AND uu.boundary IS NOT NULL
                  AND (uu.attraction_category IS NULL OR uu.attraction_category NOT IN (:excludedCategories))
                  AND (CAST(:densityMin AS numeric) IS NULL OR EXISTS (
                      SELECT 1
                      FROM criterion_value density
                      INNER JOIN criterion dc ON dc.id = density.criterion_id
                      WHERE dc.code = 'commerces_total_pour_1000_hab'
                        AND density.unite_urbaine_id = uu.id
                        AND density.period = (
                            SELECT MAX(period) FROM criterion_value WHERE criterion_id = dc.id
                        )
                        AND density.value_decimal >= CAST(:densityMin AS numeric)
                  ))
                  AND (CAST(:nuclearKm AS numeric) IS NULL OR NOT EXISTS (
                      SELECT 1
                      FROM poi plant
                      INNER JOIN poi_category plant_category ON plant_category.id = plant.category_id
                      WHERE plant_category.code = 'nuclear_power_plant'
                        AND ST_DWithin(uu.centroid, plant.location, CAST(:nuclearKm AS numeric) * 1000)
                  ))
                  AND (CAST(:priceMax AS numeric) IS NULL OR EXISTS (
                      SELECT 1
                      FROM criterion_value price
                      INNER JOIN criterion pc ON pc.id = price.criterion_id
                      WHERE pc.code = 'prix_m2'
                        AND price.unite_urbaine_id = uu.id
                        AND price.period = (
                            SELECT MAX(period) FROM criterion_value WHERE criterion_id = pc.id
                        )
                        AND price.value_integer <= CAST(:priceMax AS numeric)
                    ))
                  AND (NOT :withStation OR EXISTS (
                      SELECT 1
                      FROM poi station
                      INNER JOIN poi_category station_category ON station_category.id = station.category_id
                      WHERE station_category.code = 'railway_station'
                        AND station.unite_urbaine_id = uu.id
                  ))
                SQL,
            [
                'criterionId' => $criterionId,
                'period' => $period,
                'min' => $populationMin,
                'max' => $populationMax,
                'densityMin' => $commerceDensityMin,
                'nuclearKm' => $nuclearExclusionKm,
                'priceMax' => $priceMax,
                'withStation' => $withStation,
                // An empty IN () list is invalid SQL: a value no category has keeps the clause a no-op.
                'excludedCategories' => [] === $excludedCategoryValues ? [''] : $excludedCategoryValues,
            ],
            ['excludedCategories' => ArrayParameterType::STRING, 'withStation' => ParameterType::BOOLEAN],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => self::toString($row['id']),
                'code' => self::toString($row['code']),
                'name' => self::toString($row['name']),
                'population' => self::toInt($row['population']),
                'geometryJson' => self::toString($row['geometry']),
            ],
            $rows,
        );
    }

    /**
     * @param list<array{id: string, code: string, name: string, population: int, geometryJson: string}> $unitesUrbaines
     */
    public function toFeatureCollectionJson(array $unitesUrbaines): string
    {
        $features = array_map(
            static fn (array $uniteUrbaine): string => \sprintf(
                '{"type":"Feature","geometry":%s,"properties":{"id":%s,"code":%s,"name":%s,"population":%d}}',
                $uniteUrbaine['geometryJson'],
                json_encode($uniteUrbaine['id'], \JSON_THROW_ON_ERROR),
                json_encode($uniteUrbaine['code'], \JSON_THROW_ON_ERROR),
                json_encode($uniteUrbaine['name'], \JSON_THROW_ON_ERROR),
                $uniteUrbaine['population'],
            ),
            $unitesUrbaines,
        );

        return '{"type":"FeatureCollection","features":['.implode(',', $features).']}';
    }

    /**
     * GeoJSON FeatureCollection of the nuclear plants, for the map markers.
     */
    public function findNuclearPlantsJson(): string
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT p.name AS name, p.attributes AS attributes,
                       ST_X(p.location::geometry) AS lon, ST_Y(p.location::geometry) AS lat
                FROM poi p
                INNER JOIN poi_category pc ON pc.id = p.category_id
                WHERE pc.code = 'nuclear_power_plant'
                ORDER BY p.name
                SQL,
        );

        $features = array_map(static function (array $row): array {
            /** @var array{reactors?: int, powerMw?: int} $attributes */
            $attributes = \is_string($row['attributes']) ? json_decode($row['attributes'], true, flags: \JSON_THROW_ON_ERROR) : [];

            return [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) self::toNumeric($row['lon']), (float) self::toNumeric($row['lat'])]],
                'properties' => [
                    'name' => self::toString($row['name']),
                    'reactors' => $attributes['reactors'] ?? null,
                    'powerMw' => $attributes['powerMw'] ?? null,
                ],
            ];
        }, $rows);

        return json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR);
    }

    private function populationCriterionId(): string
    {
        $criterion = $this->criterionRepository->findOneBy(['code' => 'population']);
        if (null === $criterion) {
            throw new \LogicException('Criterion "population" not found.');
        }

        return $criterion->getId()->toRfc4122();
    }

    private static function toInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \LogicException('Expected a numeric database value, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    private static function toNumeric(mixed $value): int|float|string
    {
        if (!is_numeric($value)) {
            throw new \LogicException('Expected a numeric database value, got '.get_debug_type($value));
        }

        return $value;
    }

    private static function toString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('Expected a string database value, got '.get_debug_type($value));
        }

        return $value;
    }
}
