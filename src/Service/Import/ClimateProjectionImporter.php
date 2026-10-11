<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Enum\CriterionValueType;

/**
 * Imports the climate projections (see `docs/criteria-climate-projections.md`),
 * computed per unité urbaine and stored as is, under the reference year 2050.
 */
final readonly class ClimateProjectionImporter
{
    public const string GROUP = 'Climat 2050';

    /**
     * Indexed by the CSV measure.
     *
     * @var array<string, array{code: string, label: string, unit: string, position: int}>
     */
    private const array CRITERIA = [
        'hot_days' => ['code' => 'jours_plus_35_2050', 'label' => 'Jours > 35 °C vers 2050', 'unit' => 'j/an', 'position' => 0],
        'tropical_nights' => ['code' => 'nuits_tropicales_2050', 'label' => 'Nuits tropicales vers 2050', 'unit' => 'j/an', 'position' => 1],
        'warming' => ['code' => 'hausse_temperature_2050', 'label' => 'Hausse de température vers 2050', 'unit' => '°C', 'position' => 2],
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
        foreach (self::CRITERIA as $measure => ['code' => $code, 'label' => $label, 'unit' => $unit, 'position' => $position]) {
            $count += $this->csvCriterionImporter->import(new CriterionCsvSpec(
                code: $code,
                label: $label,
                valueType: CriterionValueType::Decimal,
                unit: $unit,
                communeCodeColumn: 'code_unite_urbaine',
                periodColumn: 'year',
                valueColumn: 'value',
                groupLabel: self::GROUP,
                filterColumn: 'measure',
                filterValue: $measure,
                position: $position,
                rankFromLowest: true,
                perUniteUrbaine: true,
            ), $csvPath);
        }

        return $count;
    }
}
