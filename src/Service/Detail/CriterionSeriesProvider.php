<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Criterion;
use App\Entity\UniteUrbaine;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL read side of the criterion chart: a percentile per period is what
 * Doctrine can't express.
 */
final readonly class CriterionSeriesProvider
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Null when the unité urbaine has no value for the criterion.
     */
    public function find(UniteUrbaine $uniteUrbaine, Criterion $criterion): ?CriterionSeries
    {
        $own = $this->findOwnValues($uniteUrbaine, $criterion);
        if ([] === $own) {
            return null;
        }

        $medians = $criterion->isComparable() ? $this->findMedians($criterion) : null;

        // The medians may cover more periods than the unité urbaine does.
        $periods = array_keys($own);
        if (null !== $medians) {
            $periods = array_unique([...$periods, ...array_keys($medians)]);
        }

        sort($periods);

        return new CriterionSeries(
            label: $criterion->getLabel(),
            unit: $criterion->getUnit(),
            periods: $periods,
            values: array_map(static fn (int $period): ?float => $own[$period] ?? null, $periods),
            medians: null === $medians ? null : array_map(static fn (int $period): ?float => $medians[$period] ?? null, $periods),
        );
    }

    /**
     * @return array<int, float> indexed by period
     */
    private function findOwnValues(UniteUrbaine $uniteUrbaine, Criterion $criterion): array
    {
        /** @var array<int, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT period, COALESCE(value_integer, value_decimal)
                FROM criterion_value
                WHERE criterion_id = :criterion AND unite_urbaine_id = :unite
                  AND (value_integer IS NOT NULL OR value_decimal IS NOT NULL)
                SQL,
            ['criterion' => $criterion->getId()->toRfc4122(), 'unite' => $uniteUrbaine->getId()->toRfc4122()],
        );

        return array_map(floatval(...), $rows);
    }

    /**
     * @return array<int, float> indexed by period
     */
    private function findMedians(Criterion $criterion): array
    {
        /** @var array<int, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT period, PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY COALESCE(value_integer, value_decimal))
                FROM criterion_value
                WHERE criterion_id = :criterion
                  AND (value_integer IS NOT NULL OR value_decimal IS NOT NULL)
                GROUP BY period
                SQL,
            ['criterion' => $criterion->getId()->toRfc4122()],
        );

        return array_map(floatval(...), $rows);
    }
}
