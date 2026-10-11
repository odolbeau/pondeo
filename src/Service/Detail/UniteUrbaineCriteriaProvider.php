<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\UniteUrbaine;
use Doctrine\DBAL\Connection;

/**
 * Raw-SQL read side of the unité urbaine detail page: every criterion value
 * of one unité urbaine, grouped by criterion group, with rank and median
 * computed over all unités urbaines (a window function per criterion is
 * what Doctrine can't express).
 *
 * @phpstan-type ValueRow array{code: string, label: string, unit: ?string, group_label: ?string, position: ?int, period: int, value_integer: ?int, value_decimal: ?string, value_text: ?string, value_boolean: ?bool}
 */
final readonly class UniteUrbaineCriteriaProvider
{
    public const string DEFAULT_GROUP_LABEL = 'Autres';

    /** Groups listed first, in this order; the others follow alphabetically, then the default one. */
    private const array GROUP_ORDER = ['Démographie', 'Immobilier', 'Commerces'];

    /** Code suffix of a criterion derived from the count `<code>`: the detail page folds that count into its title. */
    public const string DENSITY_SUFFIX = '_pour_1000_hab';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Groups are alphabetical with "Autres" last; criteria alphabetical within a group.
     *
     * @return list<CriterionGroupDetail>
     */
    public function findGroups(UniteUrbaine $uniteUrbaine): array
    {
        $id = $uniteUrbaine->getId()->toRfc4122();
        $comparisons = $this->findComparisons($id);

        /** @var array<string, array{criterion: ValueRow, points: array<int, int|float|string|bool>}> $byCriterion */
        $byCriterion = [];
        foreach ($this->findValues($id) as $row) {
            $byCriterion[$row['code']]['criterion'] ??= $row;
            $byCriterion[$row['code']]['points'][$row['period']] = $this->value($row);
        }

        /** @var array<string, list<CriterionDetail>> $criteriaByGroup */
        $criteriaByGroup = [];
        foreach ($byCriterion as $code => ['criterion' => $criterion, 'points' => $points]) {
            // A count that has its "per 1000" criterion is shown in that one's title, not on its own row.
            if (isset($byCriterion[$code.self::DENSITY_SUFFIX])) {
                continue;
            }

            ksort($points);
            $period = array_key_last($points);
            if (null === $period) {
                continue;
            }

            $value = $points[$period];
            $comparison = $comparisons[$code] ?? null;

            $criteriaByGroup[$criterion['group_label'] ?? self::DEFAULT_GROUP_LABEL][] = new CriterionDetail(
                code: $code,
                label: $criterion['label'],
                unit: $criterion['unit'],
                value: $value,
                period: $period,
                series: null === $comparison ? [] : array_filter($points, static fn (int|float|string|bool $point): bool => \is_int($point) || \is_float($point)),
                rank: $comparison['rank'] ?? null,
                total: $comparison['total'] ?? null,
                median: $comparison['median'] ?? null,
                count: $this->pairedCount($code, $byCriterion),
                position: $criterion['position'],
            );
        }

        uksort($criteriaByGroup, static fn (string $a, string $b): int => self::groupRank($a) <=> self::groupRank($b) ?: strcmp($a, $b));

        $groups = [];
        foreach ($criteriaByGroup as $label => $criteria) {
            usort($criteria, static fn (CriterionDetail $a, CriterionDetail $b): int => [$a->position ?? \PHP_INT_MAX, $a->label] <=> [$b->position ?? \PHP_INT_MAX, $b->label]);
            $groups[] = new CriterionGroupDetail($label, $criteria);
        }

        return $groups;
    }

    private static function groupRank(string $label): int
    {
        if (self::DEFAULT_GROUP_LABEL === $label) {
            return \PHP_INT_MAX;
        }

        $index = array_search($label, self::GROUP_ORDER, true);

        return false === $index ? \count(self::GROUP_ORDER) : $index;
    }

    /**
     * Latest value of the count a density criterion (`<code>_pour_1000_hab`) is derived from.
     *
     * @param array<string, array{criterion: ValueRow, points: array<int, int|float|string|bool>}> $byCriterion
     */
    private function pairedCount(string $code, array $byCriterion): ?int
    {
        if (!str_ends_with($code, self::DENSITY_SUFFIX)) {
            return null;
        }

        $points = $byCriterion[substr($code, 0, -\strlen(self::DENSITY_SUFFIX))]['points'] ?? null;
        if (null === $points) {
            return null;
        }

        ksort($points);
        $count = end($points);

        return \is_int($count) ? $count : null;
    }

    /**
     * @return list<ValueRow>
     */
    private function findValues(string $uniteUrbaineId): array
    {
        /** @var list<ValueRow> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.code, c.label, c.unit, c.group_label, c.position, cv.period,
                       cv.value_integer, cv.value_decimal, cv.value_text, cv.value_boolean
                FROM criterion_value cv
                INNER JOIN criterion c ON c.id = cv.criterion_id
                WHERE cv.unite_urbaine_id = :id
                ORDER BY c.code, cv.period
                SQL,
            ['id' => $uniteUrbaineId],
        );

        return $rows;
    }

    /**
     * Rank and median of the unité urbaine's latest value of each numeric
     * criterion, against the other unités urbaines at that same period.
     *
     * @return array<string, array{rank: int, total: int, median: float}> indexed by criterion code
     */
    private function findComparisons(string $uniteUrbaineId): array
    {
        /** @var list<array{code: string, rank: int, total: int, median: string|float}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                WITH latest AS (
                    SELECT criterion_id, MAX(period) AS period
                    FROM criterion_value
                    WHERE unite_urbaine_id = :id
                    GROUP BY criterion_id
                ),
                scoped AS (
                    SELECT cv.criterion_id, cv.unite_urbaine_id, COALESCE(cv.value_integer, cv.value_decimal) AS value
                    FROM criterion_value cv
                    INNER JOIN latest l ON l.criterion_id = cv.criterion_id AND l.period = cv.period
                    WHERE cv.value_integer IS NOT NULL OR cv.value_decimal IS NOT NULL
                ),
                ranked AS (
                    SELECT criterion_id, unite_urbaine_id,
                           RANK() OVER (PARTITION BY criterion_id ORDER BY value DESC) AS rank,
                           COUNT(*) OVER (PARTITION BY criterion_id) AS total
                    FROM scoped
                ),
                medians AS (
                    SELECT criterion_id, PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY value) AS median
                    FROM scoped
                    GROUP BY criterion_id
                )
                SELECT c.code, r.rank, r.total, m.median
                FROM ranked r
                INNER JOIN medians m ON m.criterion_id = r.criterion_id
                INNER JOIN criterion c ON c.id = r.criterion_id
                WHERE r.unite_urbaine_id = :id AND c.comparable
                SQL,
            ['id' => $uniteUrbaineId],
        );

        $comparisons = [];
        foreach ($rows as $row) {
            $comparisons[$row['code']] = [
                'rank' => $row['rank'],
                'total' => $row['total'],
                'median' => (float) $row['median'],
            ];
        }

        return $comparisons;
    }

    /**
     * @param ValueRow $row
     */
    private function value(array $row): int|float|string|bool
    {
        return match (true) {
            null !== $row['value_integer'] => $row['value_integer'],
            null !== $row['value_decimal'] => (float) $row['value_decimal'],
            null !== $row['value_boolean'] => $row['value_boolean'],
            default => (string) $row['value_text'],
        };
    }
}
