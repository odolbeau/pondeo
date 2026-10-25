<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Imports the real estate prices (see `docs/criteria-real-estate.md`): one
 * price per m² criterion per property type. A price is not additive, so a
 * unité urbaine's price is its communes' prices averaged with their number of
 * sales as weight.
 */
final readonly class RealEstatePriceImporter
{
    public const string GROUP = 'Achat';

    /**
     * Indexed by the CSV property type.
     *
     * @var array<string, array{code: string, label: string, position: int}>
     */
    public const array CRITERIA = [
        'apt_maison' => ['code' => 'prix_m2', 'label' => 'Prix au m²', 'position' => 0],
        'appartement' => ['code' => 'prix_m2_appartement', 'label' => 'Prix au m² (appartements)', 'position' => 1],
        'maison' => ['code' => 'prix_m2_maison', 'label' => 'Prix au m² (maisons)', 'position' => 2],
    ];

    public function __construct(private CsvCriterionImporter $csvCriterionImporter)
    {
    }

    /**
     * @return int number of (unité urbaine, period) values upserted
     */
    public function import(string $csvPath): int
    {
        $count = 0;
        foreach (self::CRITERIA as $type => ['code' => $code, 'label' => $label, 'position' => $position]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: CriterionValueType::Integer,
                unit: '€/m²',
                communeCodeColumn: 'code_insee',
                periodColumn: 'year',
                valueColumn: 'prix_m2',
                groupLabel: self::GROUP,
                filterColumn: 'type',
                filterValue: $type,
                position: $position,
                aggregation: Aggregation::WeightedMean,
                weightColumn: 'nb_ventes',
            ), $csvPath);
        }

        return $count;
    }
}
