<?php

declare(strict_types=1);

namespace App\Service\Map;

use App\Enum\ZoneType;
use App\Repository\CriterionRepository;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL queries backing the zone maps (see CriterionAggregator for the
 * same "Doctrine can't express this cleanly" rationale). Population is the
 * only filter today; findFeatures()'s min/max params are the extension
 * point for more criteria later, so the data endpoints don't need a new
 * controller per criterion.
 */
final readonly class ZoneMapDataProvider
{
    public function __construct(
        private Connection $connection,
        private CriterionRepository $criterionRepository,
    ) {
    }

    public function getLatestPeriod(ZoneType $zoneType): int
    {
        $criterionId = $this->populationCriterionId();

        $period = $this->connection->fetchOne(
            'SELECT MAX(period) FROM zone_criterion_value WHERE criterion_id = :criterionId AND zone_type = :zoneType',
            ['criterionId' => $criterionId, 'zoneType' => $zoneType->value],
        );

        if (false === $period || null === $period) {
            throw new \LogicException("No population data found for {$zoneType->value}.");
        }

        return self::toInt($period);
    }

    /** @return array{min: int, max: int} */
    public function getPopulationBounds(ZoneType $zoneType, int $period): array
    {
        $criterionId = $this->populationCriterionId();

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT MIN(value_integer) AS min, MAX(value_integer) AS max
                FROM zone_criterion_value
                WHERE criterion_id = :criterionId AND zone_type = :zoneType AND period = :period
                SQL,
            ['criterionId' => $criterionId, 'zoneType' => $zoneType->value, 'period' => $period],
        );

        if (false === $row || null === $row['min']) {
            throw new \LogicException("No population data found for {$zoneType->value} at period {$period}.");
        }

        return ['min' => self::toInt($row['min']), 'max' => self::toInt($row['max'])];
    }

    /**
     * geometryJson is kept as a raw GeoJSON string, not decoded to a PHP
     * array — decoding tens of thousands of coordinate arrays into PHP
     * values (and re-encoding them) is what blows the memory limit, not the
     * JSON payload size itself. toFeatureCollectionJson() splices it
     * straight into the response body instead.
     *
     * @return list<array{id: string, code: string, name: string, population: int, geometryJson: string}>
     */
    public function findFeatures(ZoneType $zoneType, int $period, int $populationMin, int $populationMax): array
    {
        $criterionId = $this->populationCriterionId();
        $table = $zoneType->tableName();
        $codeColumn = $zoneType->codeColumn();
        $fkColumn = $zoneType->foreignKeyColumn();

        // Simplified: at full resolution, tens of thousands of communes'
        // boundaries together are too large to decode/transfer for an
        // unfiltered map. 0.001° (~100m) is imperceptible at the zoom
        // levels these maps are used at.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                <<<'SQL'
                    SELECT zone.id AS id, zone.%1$s AS code, zone.name AS name,
                           zcv.value_integer AS population,
                           ST_AsGeoJSON(ST_SimplifyPreserveTopology(zone.boundary, 0.001)) AS geometry
                    FROM %2$s zone
                    INNER JOIN zone_criterion_value zcv ON zcv.%3$s = zone.id
                    WHERE zcv.criterion_id = :criterionId
                      AND zcv.period = :period
                      AND zcv.value_integer BETWEEN :min AND :max
                      AND zone.boundary IS NOT NULL
                    SQL,
                $codeColumn,
                $table,
                $fkColumn,
            ),
            [
                'criterionId' => $criterionId,
                'period' => $period,
                'min' => $populationMin,
                'max' => $populationMax,
            ],
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
     * @param list<array{id: string, code: string, name: string, population: int, geometryJson: string}> $zones
     */
    public function toFeatureCollectionJson(array $zones): string
    {
        $features = array_map(
            static fn (array $zone): string => \sprintf(
                '{"type":"Feature","geometry":%s,"properties":{"id":%s,"code":%s,"name":%s,"population":%d}}',
                $zone['geometryJson'],
                json_encode($zone['id'], \JSON_THROW_ON_ERROR),
                json_encode($zone['code'], \JSON_THROW_ON_ERROR),
                json_encode($zone['name'], \JSON_THROW_ON_ERROR),
                $zone['population'],
            ),
            $zones,
        );

        return '{"type":"FeatureCollection","features":['.implode(',', $features).']}';
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

    private static function toString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('Expected a string database value, got '.get_debug_type($value));
        }

        return $value;
    }
}
