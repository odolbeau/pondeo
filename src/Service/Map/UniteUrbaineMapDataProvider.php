<?php

declare(strict_types=1);

namespace App\Service\Map;

use App\Service\Filter\UniteUrbaineFilter;
use App\Service\Filter\UniteUrbaineFilterClauseProvider;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL queries backing the unité urbaine map (Doctrine can't express the
 * boundary simplification cleanly). The filters themselves are the shared ones
 * of {@see UniteUrbaineFilterClauseProvider}, so the data endpoint doesn't need
 * a new controller per criterion.
 */
final readonly class UniteUrbaineMapDataProvider
{
    public function __construct(
        private Connection $connection,
        private UniteUrbaineFilterClauseProvider $filterClauseProvider,
    ) {
    }

    /**
     * geometryJson is kept as a raw GeoJSON string, not decoded to a PHP
     * array — decoding thousands of large coordinate arrays into PHP
     * values (and re-encoding them) is what blows the memory limit, not the
     * JSON payload size itself. toFeatureCollectionJson() splices it
     * straight into the response body instead.
     *
     * Only the unités urbaine with a boundary are kept: the others cannot be drawn.
     *
     * @return list<array{id: string, code: string, name: string, population: int, geometryJson: string}>
     */
    public function findFeatures(UniteUrbaineFilter $filter): array
    {
        $from = $this->matchingFrom($filter);

        // Simplified: at full resolution, the boundaries of thousands of
        // unités urbaines together are too large to decode/transfer for an
        // unfiltered map. 0.001° (~100m) is imperceptible at the zoom
        // levels this map is used at.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT uu.id AS id, uu.insee_code AS code, uu.name AS name,
                       cv.value_integer AS population,
                       ST_AsGeoJSON(ST_SimplifyPreserveTopology(uu.boundary, 0.001)) AS geometry
                SQL.' '.$from['sql'],
            $from['params'],
            $from['types'],
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
     * The unités urbaine the filters keep, with what the filters measured on
     * each (only for the filters that are set), most populated first. One more
     * row than `$limit` is returned at most: enough to tell the cap was exceeded.
     *
     * @return list<array{code: string, name: string, departement: string|null, population: int, commerceDensity: float|null, restaurantDensity: float|null, associationDensity: float|null, price: float|null, trainMinutes: array<string, float|null>}>
     */
    public function findExportRows(UniteUrbaineFilter $filter, int $limit): array
    {
        $from = $this->matchingFrom($filter);

        $columns = '';
        $params = $from['params'];
        if (null !== $filter->commerceDensityMin) {
            $columns .= ', '.$this->latestValue('commerces_total_pour_1000_hab', 'value_decimal').' AS commerce_density';
        }

        if (null !== $filter->restaurantDensityMin) {
            $columns .= ', '.$this->latestValue('restaurants_pour_1000_hab', 'value_decimal').' AS restaurant_density';
        }

        if (null !== $filter->associationDensityMin) {
            $columns .= ', '.$this->latestValue('associations_actives_pour_1000_hab', 'value_decimal').' AS association_density';
        }

        if (null !== $filter->priceMax) {
            $columns .= ', '.$this->latestValue($filter->priceCriterionCode, 'value_integer').' AS price';
        }

        $destinations = array_keys($filter->trainLimits);
        foreach ($destinations as $i => $destination) {
            $columns .= <<<SQL
                , (
                    SELECT MIN(sa.duration_seconds / 60.0 + COALESCE(sj.median_duration_minutes, 0))
                    FROM station_access sa
                    LEFT JOIN station_journey sj ON sj.station_id = sa.station_id AND sj.destination = :exportDestination{$i}
                    WHERE sa.unite_urbaine_id = uu.id
                      AND (sj.id IS NULL OR (sj.median_duration_minutes IS NOT NULL AND (CAST(:fTrainTransfers AS integer) IS NULL OR sj.transfers <= CAST(:fTrainTransfers AS integer))))
                ) AS train_{$i}
                SQL;
            $params["exportDestination{$i}"] = $destination;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT uu.insee_code AS code, uu.name AS name, uu.departement AS departement, cv.value_integer AS population'.$columns.' '.$from['sql'].' ORDER BY cv.value_integer DESC, uu.insee_code LIMIT :exportLimit',
            $params + ['exportLimit' => $limit + 1],
            $from['types'],
        );

        return array_map(
            static function (array $row) use ($destinations): array {
                $trainMinutes = [];
                foreach ($destinations as $i => $destination) {
                    $trainMinutes[$destination] = isset($row["train_{$i}"]) ? (float) self::toNumeric($row["train_{$i}"]) : null;
                }

                return [
                    'code' => self::toString($row['code']),
                    'name' => self::toString($row['name']),
                    'departement' => \is_string($row['departement'] ?? null) ? $row['departement'] : null,
                    'population' => self::toInt($row['population']),
                    'commerceDensity' => isset($row['commerce_density']) ? (float) self::toNumeric($row['commerce_density']) : null,
                    'restaurantDensity' => isset($row['restaurant_density']) ? (float) self::toNumeric($row['restaurant_density']) : null,
                    'associationDensity' => isset($row['association_density']) ? (float) self::toNumeric($row['association_density']) : null,
                    'price' => isset($row['price']) ? (float) self::toNumeric($row['price']) : null,
                    'trainMinutes' => $trainMinutes,
                ];
            },
            $rows,
        );
    }

    /** The criterion's value for the unité urbaine at its latest period, as a scalar subquery on `uu`. */
    private function latestValue(string $code, string $column): string
    {
        return <<<SQL
            (
                SELECT v.{$column}
                FROM criterion_value v
                INNER JOIN criterion c ON c.id = v.criterion_id
                WHERE c.code = '{$code}'
                  AND v.unite_urbaine_id = uu.id
                  AND v.period = (SELECT MAX(period) FROM criterion_value WHERE criterion_id = c.id)
            )
            SQL;
    }

    /**
     * The FROM and WHERE shared by the map and the export, so both keep the
     * same unités urbaine.
     *
     * @return array{sql: string, params: array<string, mixed>, types: array<string, \Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType>}
     */
    private function matchingFrom(UniteUrbaineFilter $filter): array
    {
        $clause = $this->filterClauseProvider->provide($filter);

        return ['sql' => $clause->sql.' AND uu.boundary IS NOT NULL', 'params' => $clause->params, 'types' => $clause->types];
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
