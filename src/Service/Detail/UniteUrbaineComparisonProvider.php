<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\UniteUrbaine;

/**
 * Aligns the detail-page criteria of several unités urbaines (at most a handful)
 * into one row per criterion, reusing the detail page's grouping and ordering.
 */
final readonly class UniteUrbaineComparisonProvider
{
    public function __construct(private UniteUrbaineCriteriaProvider $criteriaProvider)
    {
    }

    /**
     * @param list<UniteUrbaine> $unites
     *
     * @return list<ComparisonGroup>
     */
    public function compare(array $unites): array
    {
        $groups = [];
        /** @var array<string, array<string, array{label: string, unit: ?string, cells: array<int, CriterionDetail>}>> $rowsByGroup */
        $rowsByGroup = [];
        foreach ($unites as $index => $unite) {
            foreach ($this->criteriaProvider->findGroups($unite) as $group) {
                $groups[$group->label] = true;
                foreach ($group->criteria as $criterion) {
                    $rowsByGroup[$group->label][$criterion->code] ??= ['label' => $criterion->label, 'unit' => $criterion->unit, 'cells' => []];
                    $rowsByGroup[$group->label][$criterion->code]['cells'][$index] = $criterion;
                }
            }
        }

        $result = [];
        foreach (array_keys($groups) as $label) {
            $rows = [];
            foreach ($rowsByGroup[$label] ?? [] as $code => $row) {
                $cells = [];
                foreach (array_keys($unites) as $index) {
                    $cells[] = $row['cells'][$index] ?? null;
                }

                $rows[] = new ComparisonRow($code, $row['label'], $row['unit'], $cells, $this->best($cells));
            }

            if ([] !== $rows) {
                $result[] = new ComparisonGroup($label, $rows);
            }
        }

        return $result;
    }

    /**
     * Rank 1 is already the best end (it accounts for "lower is better" criteria), so the best cells are those
     * with the smallest rank; nothing stands out when fewer than two cities can be compared.
     *
     * @param list<?CriterionDetail> $cells
     *
     * @return list<int>
     */
    private function best(array $cells): array
    {
        $ranked = [];
        foreach ($cells as $index => $cell) {
            if (null !== $cell?->rank) {
                $ranked[$index] = $cell->rank;
            }
        }

        if (\count($ranked) < 2) {
            return [];
        }

        $min = min($ranked);
        if (\count(array_unique($ranked)) < 2) {
            return [];
        }

        return array_keys($ranked, $min, true);
    }
}
