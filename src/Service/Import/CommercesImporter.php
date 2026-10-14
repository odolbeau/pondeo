<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Imports the shop counts of the BPE (see `docs/criteria-commerces.md`): one
 * count criterion for the total and one per sub-domain, each paired with a
 * density per 1000 inhabitants. Counts are additive and summed per unité
 * urbaine like any criterion; densities are derived from the stored counts
 * and population, never summed.
 */
final readonly class CommercesImporter
{
    public const string GROUP = 'Commerces';

    /** BPE sub-domains of the commerce domain. */
    public const array SUB_DOMAINS = ['B1', 'B2', 'B3'];

    /**
     * Indexed by count criterion code; a null sub-domain means all of them.
     * The detail page shows the density under its own label, with the count
     * in the title; the count criterion itself has no row (see
     * UniteUrbaineCriteriaProvider::DENSITY_SUFFIX). The position puts the total first; the group
     * title already says "Commerces", so the labels do not repeat it.
     *
     * @var array<string, array{label: string, densityLabel: string, subDomain: ?string, position: int}>
     */
    private const array COUNTS = [
        'commerces_total' => ['label' => 'Nombre de commerces', 'densityLabel' => 'Total', 'subDomain' => null, 'position' => 0],
        'commerces_grandes_surfaces' => ['label' => 'Nombre de grandes surfaces', 'densityLabel' => 'Grandes surfaces', 'subDomain' => 'B1', 'position' => 3],
        'commerces_alimentaires' => ['label' => 'Nombre de commerces alimentaires', 'densityLabel' => 'Alimentaires', 'subDomain' => 'B2', 'position' => 1],
        'commerces_non_alimentaires' => ['label' => 'Nombre de commerces non alimentaires', 'densityLabel' => 'Non alimentaires', 'subDomain' => 'B3', 'position' => 2],
    ];

    public function __construct(
        private CsvCriterionImporter $csvCriterionImporter,
        private DensityPerThousandDeriver $densityPerThousandDeriver,
    ) {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted, counts and densities together
     */
    public function import(string $csvPath): int
    {
        $count = 0;

        foreach (self::COUNTS as $code => ['label' => $label, 'densityLabel' => $densityLabel, 'subDomain' => $subDomain, 'position' => $position]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: CriterionValueType::Integer,
                unit: 'commerces',
                communeCodeColumn: 'code_insee',
                periodColumn: 'year',
                valueColumn: 'count',
                groupLabel: self::GROUP,
                filterColumn: null === $subDomain ? null : 'sub_domain',
                filterValue: $subDomain,
                comparable: false,
            ), $csvPath);

            $count += $this->densityPerThousandDeriver->derive($code, $densityLabel, self::GROUP, $position);
        }

        return $count;
    }
}
