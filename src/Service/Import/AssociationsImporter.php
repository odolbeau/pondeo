<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\AssociationFamily;
use App\Enum\CriterionValueType;

/**
 * Imports the associations of the RNA (see `docs/criteria-associations.md`):
 * the total declared, the total still active and one count per family, each
 * paired with a density per 1000 inhabitants. Counts are additive and summed
 * per unité urbaine like any criterion; densities are derived from the stored
 * counts and population, never summed.
 */
final readonly class AssociationsImporter
{
    public const string GROUP = 'Vie associative';

    /** Categories of the CSV that are not a family. */
    public const string ALL = 'all';

    public const string ACTIVE = 'active';

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

        foreach ($this->criteria() as $position => ['code' => $code, 'label' => $label, 'densityLabel' => $densityLabel, 'category' => $category]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: CriterionValueType::Integer,
                unit: 'associations',
                communeCodeColumn: 'code_insee',
                periodColumn: 'year',
                valueColumn: 'count',
                groupLabel: self::GROUP,
                filterColumn: 'category',
                filterValue: $category,
                comparable: false,
            ), $csvPath);

            $count += $this->densityPerThousandDeriver->derive($code, $densityLabel, self::GROUP, $position);
        }

        return $count;
    }

    /**
     * In display order: the totals, then the families. The detail page shows
     * the density under its own label, with the count in the title.
     *
     * @return list<array{code: string, label: string, densityLabel: string, category: string}>
     */
    private function criteria(): array
    {
        $criteria = [
            ['code' => 'associations_total', 'label' => "Nombre d'associations", 'densityLabel' => 'Toutes les associations', 'category' => self::ALL],
            ['code' => 'associations_actives', 'label' => "Nombre d'associations actives", 'densityLabel' => 'Associations actives', 'category' => self::ACTIVE],
        ];

        foreach (AssociationFamily::cases() as $family) {
            $criteria[] = [
                'code' => 'associations_'.$family->value,
                'label' => "Nombre d'associations : ".mb_strtolower($family->label()),
                'densityLabel' => $family->label(),
                'category' => $family->value,
            ];
        }

        return $criteria;
    }
}
