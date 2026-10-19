<?php

declare(strict_types=1);

namespace App\Service\Map;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reference points of a criterion over all the unités urbaine, at its latest
 * period: what the map shows next to a filter so a threshold can be picked
 * knowing where it falls.
 */
final readonly class CriterionStatsProvider
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $codes
     *
     * @return array<string, array{min: float, p10: float, p25: float, median: float, p75: float, p90: float, max: float}>
     */
    public function findStats(array $codes): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.code AS code,
                       MIN(v.value) AS min,
                       PERCENTILE_CONT(0.1) WITHIN GROUP (ORDER BY v.value) AS p10,
                       PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY v.value) AS p25,
                       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY v.value) AS median,
                       PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY v.value) AS p75,
                       PERCENTILE_CONT(0.9) WITHIN GROUP (ORDER BY v.value) AS p90,
                       MAX(v.value) AS max
                FROM criterion c
                INNER JOIN (
                    SELECT criterion_id, period, CAST(COALESCE(value_decimal, value_integer) AS double precision) AS value
                    FROM criterion_value
                ) v ON v.criterion_id = c.id
                WHERE c.code IN (:codes)
                  AND v.value IS NOT NULL
                  AND v.period = (SELECT MAX(period) FROM criterion_value WHERE criterion_id = c.id)
                GROUP BY c.code
                SQL,
            ['codes' => $codes],
            ['codes' => ArrayParameterType::STRING],
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[$this->toString($row['code'])] = [
                'min' => $this->toFloat($row['min']),
                'p10' => $this->toFloat($row['p10']),
                'p25' => $this->toFloat($row['p25']),
                'median' => $this->toFloat($row['median']),
                'p75' => $this->toFloat($row['p75']),
                'p90' => $this->toFloat($row['p90']),
                'max' => $this->toFloat($row['max']),
            ];
        }

        return $stats;
    }

    private function toFloat(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \LogicException('Expected a numeric database value, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    private function toString(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('Expected a string database value, got '.get_debug_type($value));
        }

        return $value;
    }
}
