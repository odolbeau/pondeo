<?php

declare(strict_types=1);

namespace App\Service\Map;

use App\Repository\CriterionRepository;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL queries backing the unité urbaine map (Doctrine can't express the
 * boundary simplification cleanly). Population is the only filter today;
 * findFeatures()'s min/max params are the extension point for more criteria
 * later, so the data endpoint doesn't need a new controller per criterion.
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
     * @return list<array{id: string, code: string, name: string, population: int, geometryJson: string}>
     */
    public function findFeatures(int $period, int $populationMin, int $populationMax): array
    {
        $criterionId = $this->populationCriterionId();

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
                SQL,
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
