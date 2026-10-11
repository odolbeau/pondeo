<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Imports the Filosofi income figures (see `docs/criteria-income.md`): the
 * median standard of living and the poverty rate, which INSEE publishes for
 * each unité urbaine itself, so they are stored as is.
 */
final readonly class IncomeImporter
{
    public const string GROUP = 'Revenus';

    /**
     * Indexed by the CSV measure.
     *
     * @var array<string, array{code: string, label: string, type: CriterionValueType, unit: string, position: int, rankFromLowest: bool}>
     */
    private const array CRITERIA = [
        'median' => ['code' => 'niveau_vie_median', 'label' => 'Niveau de vie médian', 'type' => CriterionValueType::Integer, 'unit' => '€/an', 'position' => 0, 'rankFromLowest' => false],
        'poverty' => ['code' => 'taux_pauvrete', 'label' => 'Taux de pauvreté', 'type' => CriterionValueType::Decimal, 'unit' => '%', 'position' => 1, 'rankFromLowest' => true],
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
        foreach (self::CRITERIA as $measure => ['code' => $code, 'label' => $label, 'type' => $type, 'unit' => $unit, 'position' => $position, 'rankFromLowest' => $rankFromLowest]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: $type,
                unit: $unit,
                communeCodeColumn: 'code_unite_urbaine',
                periodColumn: 'year',
                valueColumn: 'value',
                groupLabel: self::GROUP,
                filterColumn: 'measure',
                filterValue: $measure,
                position: $position,
                rankFromLowest: $rankFromLowest,
                perUniteUrbaine: true,
            ), $csvPath);
        }

        return $count;
    }
}
