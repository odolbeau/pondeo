<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\UniteUrbaine;
use App\Enum\DetailSection;
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

    /** Code suffix of a criterion derived from the count `<code>`: the detail page folds that count into its title. */
    public const string DENSITY_SUFFIX = '_pour_1000_hab';

    /**
     * Criteria that are the brackets of one distribution: drawn as a single chart in their group rather than one
     * row each. Indexed by group, then by criterion code, in display order, with its short label in the chart.
     */
    private const array DISTRIBUTIONS = [
        'Revenus fiscaux' => [
            'title' => 'Foyers fiscaux par tranche de revenu fiscal',
            'layout' => BracketLayout::Columns,
            'brackets' => [
                'part_foyers_moins_15000' => '< 15 k€',
                'part_foyers_15000_30000' => '15-30 k€',
                'part_foyers_30000_50000' => '30-50 k€',
                'part_foyers_50000_100000' => '50-100 k€',
                'part_foyers_plus_100000' => '> 100 k€',
            ],
        ],
        'Catégories socioprofessionnelles' => [
            'title' => 'Population de 15 ans ou plus par catégorie socioprofessionnelle',
            'layout' => BracketLayout::Rows,
            'brackets' => [
                'part_agriculteurs' => 'Agriculteurs',
                'part_artisans_commercants' => 'Artisans, commerçants',
                'part_cadres' => 'Cadres',
                'part_professions_intermediaires' => 'Prof. intermédiaires',
                'part_employes' => 'Employés',
                'part_ouvriers' => 'Ouvriers',
                'part_retraites' => 'Retraités',
                'part_sans_activite' => 'Sans activité pro.',
            ],
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Groups follow the order of their detail section, "Autres" last; criteria alphabetical within a group.
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
            $groups[] = $this->toGroup($label, $criteria);
        }

        return $groups;
    }

    /**
     * @param list<CriterionDetail> $criteria
     */
    private function toGroup(string $label, array $criteria): CriterionGroupDetail
    {
        $distribution = self::DISTRIBUTIONS[$label] ?? null;
        if (null === $distribution) {
            return new CriterionGroupDetail($label, $criteria);
        }

        $byCode = [];
        foreach ($criteria as $criterion) {
            $byCode[$criterion->code] = $criterion;
        }

        $brackets = [];
        foreach ($distribution['brackets'] as $code => $shortLabel) {
            if (isset($byCode[$code])) {
                $brackets[] = ['shortLabel' => $shortLabel, 'criterion' => $byCode[$code]];
            }
        }

        $rows = array_values(array_filter($criteria, static fn (CriterionDetail $criterion): bool => !isset($distribution['brackets'][$criterion->code])));

        return new CriterionGroupDetail($label, $rows, BracketDistribution::fromCriteria($distribution['title'], $brackets, $distribution['layout']));
    }

    /** Sections in their display order, then the group's place in its section; unknown groups close their section. */
    private static function groupRank(string $label): int
    {
        if (self::DEFAULT_GROUP_LABEL === $label) {
            return \PHP_INT_MAX;
        }

        $section = DetailSection::fromGroup($label);
        $index = array_search($label, $section->groups(), true);

        return array_search($section, DetailSection::cases(), true) * 100 + (false === $index ? 99 : $index);
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
                    SELECT s.criterion_id, s.unite_urbaine_id,
                           RANK() OVER (PARTITION BY s.criterion_id ORDER BY CASE WHEN c.rank_from_lowest THEN -s.value ELSE s.value END DESC) AS rank,
                           COUNT(*) OVER (PARTITION BY s.criterion_id) AS total
                    FROM scoped s
                    INNER JOIN criterion c ON c.id = s.criterion_id
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
